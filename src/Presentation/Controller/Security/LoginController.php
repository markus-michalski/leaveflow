<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Security;

use App\Domain\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        $company = $this->companyRepository->findOneBy([]);

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'google_oauth_enabled' => null !== $company && $company->isGoogleOAuthEnabled(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the firewall logout listener.');
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function dashboard(): Response
    {
        // Admins land on the statistics action-briefing rather than the
        // generic personal dashboard — without an employee record the
        // /-page is mostly empty for them, while the briefing surfaces
        // exactly the items that need their attention (carryover risk
        // and overdue requests). Non-admins keep the personal dashboard.
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_admin_statistics_index');
        }

        return $this->render('dashboard/index.html.twig');
    }
}
