<?php

declare(strict_types=1);

namespace App\Application\TwoFactor;

use App\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * After a company turns on the 2FA requirement and the grace deadline
 * has passed, every authenticated request from a user who hasn't
 * activated TOTP is redirected to the setup flow.
 *
 * The path-allowlist below is the minimum a locked-out user still needs
 * to actually fulfill the requirement (setup pages + logout + the asset
 * bundles that those pages depend on). Profiler/dev routes pass through
 * unchanged for local debugging.
 */
final readonly class TwoFactorEnforcementSubscriber implements EventSubscriberInterface
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_PATH_PREFIXES = [
        '/profile/2fa',
        '/logout',
        '/login',
        '/_profiler',
        '/_wdt',
        '/assets',
        '/build',
        '/2fa',
    ];

    public function __construct(
        private Security $security,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Late priority so security has already populated the token.
            KernelEvents::REQUEST => ['onRequest', 4],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }
        if ($user->isTotpEnabled()) {
            return;
        }
        $company = $user->getCompany();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if (!$company->isTwoFactorEnforced($now)) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_profile_2fa_setup'),
        ));
    }
}
