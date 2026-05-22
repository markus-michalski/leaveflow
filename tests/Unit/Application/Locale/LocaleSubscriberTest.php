<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Locale;

use App\Application\Locale\LocaleSubscriber;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;

#[CoversClass(LocaleSubscriber::class)]
final class LocaleSubscriberTest extends TestCase
{
    private Security&MockObject $security;
    private LocaleAwareInterface&MockObject $translator;
    private HttpKernelInterface&MockObject $kernel;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->translator = $this->createMock(LocaleAwareInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);

        $this->company = new Company('Acme GmbH');
        $this->user = new User($this->company, 'jane@example.com', UserRole::Employee);
    }

    #[Test]
    public function subscribesToKernelRequestWithHigherPriorityThanSecurityFirewall(): void
    {
        $events = LocaleSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);

        $config = $events[KernelEvents::REQUEST];
        $priority = \is_array($config) ? $config[1] : 0;
        self::assertGreaterThan(0, $priority, 'Must run after firewall (firewall priority is 8)');
    }

    #[Test]
    public function subRequestIsIgnored(): void
    {
        $this->security->expects(self::never())->method('getUser');
        $this->translator->expects(self::never())->method('setLocale');

        $request = Request::create('/profile');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->makeSubscriber()->onRequest($event);
    }

    #[Test]
    public function unauthenticatedRequestLeavesLocaleUnchanged(): void
    {
        $this->security->method('getUser')->willReturn(null);
        $this->translator->expects(self::never())->method('setLocale');

        $request = Request::create('/profile');
        $request->setLocale('fr');
        $event = $this->makeMainEvent($request);

        $this->makeSubscriber()->onRequest($event);

        self::assertSame('fr', $request->getLocale());
    }

    #[Test]
    public function nullLocaleDoesNotOverrideBrowserLocale(): void
    {
        $this->user->setLocale(null);
        $this->security->method('getUser')->willReturn($this->user);
        $this->translator->expects(self::never())->method('setLocale');

        $request = Request::create('/profile');
        $request->setLocale('fr');
        $event = $this->makeMainEvent($request);

        $this->makeSubscriber()->onRequest($event);

        self::assertSame('fr', $request->getLocale());
    }

    #[Test]
    public function germanLocaleIsApplied(): void
    {
        $this->user->setLocale('de');
        $this->security->method('getUser')->willReturn($this->user);
        $this->translator->expects(self::once())->method('setLocale')->with('de');

        $request = Request::create('/profile');
        $event = $this->makeMainEvent($request);

        $this->makeSubscriber()->onRequest($event);

        self::assertSame('de', $request->getLocale());
    }

    #[Test]
    public function englishLocaleIsApplied(): void
    {
        $this->user->setLocale('en');
        $this->security->method('getUser')->willReturn($this->user);
        $this->translator->expects(self::once())->method('setLocale')->with('en');

        $request = Request::create('/profile');
        $event = $this->makeMainEvent($request);

        $this->makeSubscriber()->onRequest($event);

        self::assertSame('en', $request->getLocale());
    }

    private function makeMainEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeSubscriber(): LocaleSubscriber
    {
        return new LocaleSubscriber($this->security, $this->translator);
    }
}
