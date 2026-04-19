<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Entity\Company;
use App\Domain\Entity\CompanyHoliday;
use App\Domain\Repository\CompanyHolidayRepository;
use App\Domain\Repository\CompanyRepository;
use App\Presentation\Form\CompanyHolidayType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/holidays/company', name: 'app_admin_company_holiday_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCompanyHolidayController extends AbstractController
{
    public function __construct(
        private readonly CompanyHolidayRepository $repository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->requireCompany();

        return $this->render('admin/holidays/company/index.html.twig', [
            'entries' => $this->repository->findAllByCompany($company),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->requireCompany();
        $form = $this->createForm(CompanyHolidayType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \DateTimeImmutable $date */
            $date = $form->get('date')->getData();
            /** @var string $name */
            $name = $form->get('name')->getData();

            $entry = new CompanyHoliday($company, $date, $name);
            try {
                $this->entityManager->persist($entry);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $form->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.holidays.company.flash.duplicate')
                ));

                return $this->render('admin/holidays/company/form.html.twig', [
                    'form' => $form,
                    'is_new' => true,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.holidays.company.flash.created'));

            return $this->redirectToRoute('app_admin_company_holiday_index');
        }

        return $this->render('admin/holidays/company/form.html.twig', [
            'form' => $form,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, CompanyHoliday $entry): Response
    {
        $form = $this->createForm(CompanyHolidayType::class);
        $form->get('date')->setData($entry->getDate());
        $form->get('name')->setData($entry->getName());

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \DateTimeImmutable $date */
            $date = $form->get('date')->getData();
            /** @var string $name */
            $name = $form->get('name')->getData();

            $entry->update($date, $name);
            try {
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $form->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.holidays.company.flash.duplicate')
                ));

                return $this->render('admin/holidays/company/form.html.twig', [
                    'form' => $form,
                    'is_new' => false,
                    'entry' => $entry,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.holidays.company.flash.updated'));

            return $this->redirectToRoute('app_admin_company_holiday_index');
        }

        return $this->render('admin/holidays/company/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'entry' => $entry,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, CompanyHoliday $entry): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete-company-holiday-'.$entry->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.holidays.company.flash.deleted'));

        return $this->redirectToRoute('app_admin_company_holiday_index');
    }

    private function requireCompany(): Company
    {
        $company = $this->companyRepository->findOneBy([]);
        if (!$company instanceof Company) {
            throw new \LogicException('No company configured.');
        }

        return $company;
    }
}
