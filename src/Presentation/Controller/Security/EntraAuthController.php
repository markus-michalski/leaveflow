<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Security;

use App\Domain\Repository\CompanyRepository;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EntraAuthController extends AbstractController
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    /**
     * Initiates the Microsoft Entra ID OAuth2 OIDC flow.
     * Returns 404 when Entra login is not enabled for this tenant.
     */
    #[Route('/connect/entra', name: 'connect_entra_start')]
    public function start(): Response
    {
        if (!$this->isEntraEnabled()) {
            throw $this->createNotFoundException();
        }

        return $this->clientRegistry->getClient('azure')->redirect(['openid', 'profile', 'email', 'offline_access'], []);
    }

    /**
     * Entra redirects here after the user authenticates.
     * The actual token exchange is handled by {@see \App\Infrastructure\Security\EntraAuthenticator}.
     */
    #[Route('/connect/entra/check', name: 'connect_entra_check')]
    public function check(): Response
    {
        if (!$this->isEntraEnabled()) {
            throw $this->createNotFoundException();
        }

        return new RedirectResponse('/');
    }

    private function isEntraEnabled(): bool
    {
        $company = $this->companyRepository->findOneBy([]);

        return null !== $company && $company->isEntraOAuthEnabled();
    }
}
