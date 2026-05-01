<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Entity\BlackoutPeriod;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Repository\BlackoutPeriodRepository;
use App\Domain\Repository\CompanyRepository;
use App\Presentation\Form\BlackoutPeriodType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin CRUD for BlackoutPeriod (Phase 7) — admin-managed hard-block date
 * ranges that prevent leave requests company-wide or per Department.
 */
#[Route('/admin/blackout-periods', name: 'app_admin_blackout_period_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminBlackoutPeriodController extends AbstractController
{
    public function __construct(
        private readonly BlackoutPeriodRepository $repository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->requireCompany();

        return $this->render('admin/blackout_periods/index.html.twig', [
            'periods' => $this->repository->findAllForCompany($company),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->requireCompany();
        $form = $this->createForm(BlackoutPeriodType::class, null, ['company' => $company]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \DateTimeImmutable $start */
            $start = $form->get('startDate')->getData();
            /** @var \DateTimeImmutable $end */
            $end = $form->get('endDate')->getData();
            /** @var string $reason */
            $reason = $form->get('reason')->getData();
            /** @var Department|null $department */
            $department = $form->get('department')->getData();

            try {
                $period = new BlackoutPeriod($company, $start, $end, $reason, $department);
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->render('admin/blackout_periods/form.html.twig', [
                    'form' => $form,
                    'is_new' => true,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->entityManager->persist($period);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('admin.blackout_periods.flash.created'));

            return $this->redirectToRoute('app_admin_blackout_period_index');
        }

        return $this->render('admin/blackout_periods/form.html.twig', [
            'form' => $form,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, BlackoutPeriod $period): Response
    {
        $company = $this->requireCompany();
        $this->assertSameCompany($period, $company);

        $form = $this->createForm(BlackoutPeriodType::class, null, ['company' => $company]);
        $form->get('startDate')->setData($period->getStartDate());
        $form->get('endDate')->setData($period->getEndDate());
        $form->get('reason')->setData($period->getReason());
        $form->get('department')->setData($period->getDepartment());

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \DateTimeImmutable $start */
            $start = $form->get('startDate')->getData();
            /** @var \DateTimeImmutable $end */
            $end = $form->get('endDate')->getData();
            /** @var string $reason */
            $reason = $form->get('reason')->getData();
            /** @var Department|null $department */
            $department = $form->get('department')->getData();

            try {
                $period->update($start, $end, $reason, $department);
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->render('admin/blackout_periods/form.html.twig', [
                    'form' => $form,
                    'is_new' => false,
                    'period' => $period,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('admin.blackout_periods.flash.updated'));

            return $this->redirectToRoute('app_admin_blackout_period_index');
        }

        return $this->render('admin/blackout_periods/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'period' => $period,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, BlackoutPeriod $period): Response
    {
        $company = $this->requireCompany();
        $this->assertSameCompany($period, $company);

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete-blackout-period-'.$period->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->entityManager->remove($period);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.blackout_periods.flash.deleted'));

        return $this->redirectToRoute('app_admin_blackout_period_index');
    }

    private function requireCompany(): Company
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured.');
        }

        return $company;
    }

    private function assertSameCompany(BlackoutPeriod $period, Company $company): void
    {
        if ($period->getCompany() !== $company) {
            throw $this->createNotFoundException('Blackout period not found.');
        }
    }
}
