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

namespace App\Presentation\Controller;

use App\Application\Onboarding\SystemRequirementsChecker;
use App\Application\Security\UserProvisioningService;
use App\Domain\Entity\Company;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One-time bootstrap form for a fresh installation. Only reachable
 * while no Company exists; the FirstRunSubscriber redirects every
 * other route here, this controller actively rejects access once
 * the tenant is set up.
 *
 * Creates a Company plus a single ROLE_ADMIN user in one transaction.
 * Everything else (employees, departments, absence types) is admin
 * follow-up work via the normal /admin/* tools.
 */
#[Route('/setup', name: 'app_setup_')]
final class SetupController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly SystemRequirementsChecker $requirementsChecker,
        private readonly UserProvisioningService $userProvisioning,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if (null !== $this->companyRepository->findOneBy([])) {
            return $this->redirectToRoute('app_login');
        }

        $requirementChecks = $this->requirementsChecker->check();
        $requirementsOk = true;
        foreach ($requirementChecks as $check) {
            if ($check->isBlocking()) {
                $requirementsOk = false;
                break;
            }
        }

        $errors = [];
        $submitted = [
            'companyName' => '',
            'adminEmail' => '',
        ];

        if ('POST' === $request->getMethod()) {
            if (!$requirementsOk) {
                // Form is disabled in the template, but defend against a
                // hand-rolled POST that bypasses the disabled attribute.
                return $this->redirectToRoute('app_setup_index');
            }

            if (!$this->isCsrfTokenValid('setup', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $submitted['companyName'] = trim((string) $request->request->get('company_name'));
            $submitted['adminEmail'] = strtolower(trim((string) $request->request->get('admin_email')));
            $adminPassword = (string) $request->request->get('admin_password');
            $adminPasswordConfirm = (string) $request->request->get('admin_password_confirm');

            if ('' === $submitted['companyName']) {
                $errors['companyName'] = $this->translator->trans('setup.error.company_name_required');
            }
            if ('' === $submitted['adminEmail'] || !filter_var($submitted['adminEmail'], \FILTER_VALIDATE_EMAIL)) {
                $errors['adminEmail'] = $this->translator->trans('setup.error.admin_email_invalid');
            }
            if (\strlen($adminPassword) < 12) {
                $errors['adminPassword'] = $this->translator->trans('setup.error.admin_password_too_short');
            }
            if ($adminPassword !== $adminPasswordConfirm) {
                $errors['adminPasswordConfirm'] = $this->translator->trans('setup.error.admin_password_mismatch');
            }

            if ([] === $errors) {
                $company = new Company($submitted['companyName']);
                $this->entityManager->persist($company);
                $this->userProvisioning->provisionLocal($company, $submitted['adminEmail'], UserRole::Admin, $adminPassword);
                $this->entityManager->flush();

                return $this->redirectToRoute('app_setup_done');
            }
        }

        return $this->render('setup/index.html.twig', [
            'errors' => $errors,
            'submitted' => $submitted,
            'requirementChecks' => $requirementChecks,
            'requirementsOk' => $requirementsOk,
        ]);
    }

    #[Route('/done', name: 'done', methods: ['GET'])]
    public function done(): Response
    {
        // If the tenant has more than one company-creation attempt in
        // flight (shouldn't happen) the visit re-checks state — once
        // a company exists this is the natural landing page.
        if (null === $this->companyRepository->findOneBy([])) {
            return $this->redirectToRoute('app_setup_index');
        }

        return $this->render('setup/done.html.twig');
    }
}
