<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

use App\Domain\Repository\CompanyRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Redirects every request to /setup until the single-tenant Company
 * has been created. Allows the setup form itself plus a tight set of
 * static-asset prefixes so the form can actually render and post.
 *
 * Caches the first-run status in a private property so a fully-set-up
 * tenant doesn't query CompanyRepository on every request.
 */
final class FirstRunSubscriber implements EventSubscriberInterface
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_PATH_PREFIXES = [
        '/setup',
        '/_profiler',
        '/_wdt',
        '/assets',
        '/build',
    ];

    private ?bool $companyExists = null;

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Earlier than security (priority 8 — between Firewall and Router).
            KernelEvents::REQUEST => ['onRequest', 32],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->isCompanyConfigured()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_setup_index'),
        ));
    }

    private function isCompanyConfigured(): bool
    {
        if (null !== $this->companyExists) {
            return $this->companyExists;
        }

        $this->companyExists = null !== $this->companyRepository->findOneBy([]);

        return $this->companyExists;
    }
}
