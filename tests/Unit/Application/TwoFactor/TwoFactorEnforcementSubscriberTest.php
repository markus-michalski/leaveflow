<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\TwoFactor;

use App\Application\TwoFactor\TwoFactorEnforcementSubscriber;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(TwoFactorEnforcementSubscriber::class)]
final class TwoFactorEnforcementSubscriberTest extends TestCase
{
    private Security&MockObject $security;
    private UrlGeneratorInterface&MockObject $urlGenerator;
    private HttpKernelInterface&MockObject $kernel;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);

        $this->company = new Company('Acme');
        $this->user = new User($this->company, 'alice@example.test', UserRole::Employee);
    }

    #[Test]
    public function noActionWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $event = $this->makeEvent('/some/page');
        $this->makeSubscriber('2026-05-12')->onRequest($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function noActionWhenUserAlreadyHasTotpEnabled(): void
    {
        $this->user->enableTotp('SECRET', []);
        $this->company->enableTwoFactorRequirement(
            new \DateTimeImmutable('2026-05-12'),
            new \DateTimeImmutable('2026-05-12'),
        );
        $this->security->method('getUser')->willReturn($this->user);

        $event = $this->makeEvent('/some/page');
        $this->makeSubscriber('2026-05-12')->onRequest($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function noActionWhenCompanyDoesNotRequireTwoFactor(): void
    {
        $this->security->method('getUser')->willReturn($this->user);

        $event = $this->makeEvent('/some/page');
        $this->makeSubscriber('2026-05-12')->onRequest($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function noActionWhenGracePeriodIsStillActive(): void
    {
        $this->company->enableTwoFactorRequirement(
            new \DateTimeImmutable('2026-06-11'),
            new \DateTimeImmutable('2026-05-12'),
        );
        $this->security->method('getUser')->willReturn($this->user);

        $event = $this->makeEvent('/some/page');
        $this->makeSubscriber('2026-06-10')->onRequest($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function redirectsToSetupWhenEnforcedAndUserHasNoTotp(): void
    {
        $this->company->enableTwoFactorRequirement(
            new \DateTimeImmutable('2026-06-11'),
            new \DateTimeImmutable('2026-05-12'),
        );
        $this->security->method('getUser')->willReturn($this->user);
        $this->urlGenerator->method('generate')
            ->with('app_profile_2fa_setup')
            ->willReturn('/profile/2fa/setup');

        $event = $this->makeEvent('/some/page');
        $this->makeSubscriber('2026-06-12')->onRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/profile/2fa/setup', $response->getTargetUrl());
    }

    #[Test]
    #[DataProvider('allowedPathsProvider')]
    public function allowedPathsAreNotRedirected(string $path): void
    {
        $this->company->enableTwoFactorRequirement(
            new \DateTimeImmutable('2026-06-11'),
            new \DateTimeImmutable('2026-05-12'),
        );
        $this->security->method('getUser')->willReturn($this->user);

        $event = $this->makeEvent($path);
        $this->makeSubscriber('2026-06-12')->onRequest($event);

        self::assertNull($event->getResponse(), \sprintf('Path %s should not be redirected', $path));
    }

    /**
     * @return iterable<array{0: string}>
     */
    public static function allowedPathsProvider(): iterable
    {
        yield ['/profile/2fa/setup'];
        yield ['/profile/2fa/confirm'];
        yield ['/profile/2fa/codes'];
        yield ['/logout'];
        yield ['/login'];
        yield ['/2fa'];
        yield ['/_profiler/abc'];
        yield ['/assets/app.js'];
    }

    private function makeEvent(string $path): RequestEvent
    {
        $request = Request::create($path);

        return new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeSubscriber(string $today): TwoFactorEnforcementSubscriber
    {
        return new TwoFactorEnforcementSubscriber(
            $this->security,
            new MockClock($today),
            $this->urlGenerator,
        );
    }
}
