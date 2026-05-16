<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Security;

use App\Domain\Repository\CompanyRepository;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleAuthController extends AbstractController
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    /**
     * Initiates the Google OAuth2 OIDC flow.
     * Returns 404 when Google login is not enabled for this tenant so that
     * the button is never reachable even via direct URL.
     */
    #[Route('/connect/google', name: 'connect_google_start')]
    public function start(): Response
    {
        if (!$this->isGoogleEnabled()) {
            throw $this->createNotFoundException();
        }

        return $this->clientRegistry->getClient('google')->redirect(['openid', 'email', 'profile'], []);
    }

    /**
     * Google redirects here after the user authenticates.
     * The actual token exchange and user resolution is handled by
     * {@see \App\Infrastructure\Security\GoogleAuthenticator}.
     */
    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function check(): Response
    {
        if (!$this->isGoogleEnabled()) {
            throw $this->createNotFoundException();
        }

        // GoogleAuthenticator intercepts this route before the controller
        // method runs — this code is unreachable under normal operation.
        return new RedirectResponse('/');
    }

    private function isGoogleEnabled(): bool
    {
        $company = $this->companyRepository->findOneBy([]);

        return null !== $company && $company->isGoogleOAuthEnabled();
    }
}
