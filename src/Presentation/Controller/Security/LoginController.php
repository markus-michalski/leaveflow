<?php

declare(strict_types=1);

/*
 * This file is part of LeaveFlow.
 *
 * (c) Markus Michalski <ich@markus-michalski.net>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace App\Presentation\Controller\Security;

use App\Application\Dashboard\PersonalDashboardService;
use App\Domain\Entity\User;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\EmployeeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly EmployeeRepository $employeeRepository,
        private readonly PersonalDashboardService $dashboardService,
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
            'entra_oauth_enabled' => null !== $company && $company->isEntraOAuthEnabled(),
            'ldap_enabled' => null !== $company && $company->isLdapEnabled(),
            'company_logo_path' => $company?->getLogoPath(),
            'company_name' => $company?->getName(),
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

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $employee = $this->employeeRepository->findOneByUser($user);

        if (null === $employee) {
            return $this->render('dashboard/index.html.twig', [
                'employeeDashboard' => null,
                'managerDashboard' => null,
            ]);
        }

        if ($this->isGranted('ROLE_MANAGER')) {
            return $this->render('dashboard/index.html.twig', [
                'employeeDashboard' => null,
                'managerDashboard' => $this->dashboardService->buildForManager($employee),
            ]);
        }

        return $this->render('dashboard/index.html.twig', [
            'employeeDashboard' => $this->dashboardService->buildForEmployee($employee),
            'managerDashboard' => null,
        ]);
    }
}
