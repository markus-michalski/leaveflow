<?php

declare(strict_types=1);

namespace App\Application\Locale;

use App\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Applies the user's stored locale preference to each request.
 * Runs at priority 7 — after the security firewall (8) so the user is
 * already authenticated. Explicitly updates the translator (LocaleAwareInterface)
 * because Symfony's LocaleAwareListener runs at priority 15, before us.
 * Falls back to browser Accept-Language (via enabled_locales negotiation) when
 * no preference is stored.
 */
final readonly class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private LocaleAwareInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 7],
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

        $locale = $user->getLocale();
        if (null === $locale) {
            return;
        }

        $event->getRequest()->setLocale($locale);
        $this->translator->setLocale($locale);
    }
}
