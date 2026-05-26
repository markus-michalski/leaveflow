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

namespace App\Presentation\Controller\Admin;

use App\Application\Employee\AnonymizationNotDueException;
use App\Application\Employee\AnonymizationService;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\EmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/anonymization', name: 'app_admin_anonymization_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAnonymizationController extends AbstractController
{
    public function __construct(
        private readonly AnonymizationService $anonymizationService,
        private readonly EmployeeRepository $employeeRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $asOf = $this->clock->now();

        return $this->render('admin/anonymization/index.html.twig', [
            'due' => $this->anonymizationService->findDue($asOf),
            'already_anonymized' => $this->employeeRepository->findAlreadyAnonymizedByCompany(
                $this->currentCompany(),
            ),
            'retention_months' => $this->currentCompany()->getRetentionPeriodMonths(),
        ]);
    }

    #[Route('/{id}/anonymize', name: 'anonymize', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function anonymize(Request $request, Employee $employee): Response
    {
        if (!$this->isCsrfTokenValid('anonymize-employee-'.$employee->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->assertSameCompany($employee);

        try {
            $this->entityManager->wrapInTransaction(function () use ($employee): void {
                $this->anonymizationService->anonymize($employee);
            });

            $this->addFlash(
                'success',
                $this->translator->trans('admin.anonymization.flash.success', ['%id%' => $employee->getId()]),
            );
        } catch (AnonymizationNotDueException $e) {
            $this->addFlash('warning', $e->getMessage());
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_anonymization_index');
    }

    private function currentCompany(): Company
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured.');
        }

        return $company;
    }

    private function assertSameCompany(Employee $employee): void
    {
        if ($employee->getCompany() !== $this->currentCompany()) {
            throw $this->createNotFoundException();
        }
    }
}
