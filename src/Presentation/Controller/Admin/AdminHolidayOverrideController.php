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

use App\Domain\Entity\Company;
use App\Domain\Entity\HolidayOverride;
use App\Domain\Entity\Location;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\HolidayOverrideType as OverrideTypeEnum;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\HolidayOverrideRepository;
use App\Presentation\Form\HolidayOverrideType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/holidays/overrides', name: 'app_admin_holiday_override_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminHolidayOverrideController extends AbstractController
{
    public function __construct(
        private readonly HolidayOverrideRepository $repository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->requireCompany();

        return $this->render('admin/holidays/overrides/index.html.twig', [
            'overrides' => $this->repository->findAllByCompany($company),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->requireCompany();
        $form = $this->createForm(HolidayOverrideType::class, null, ['company' => $company]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var FederalState $state */
            $state = $form->get('federalState')->getData();
            /** @var \DateTimeImmutable $date */
            $date = $form->get('date')->getData();
            /** @var string $name */
            $name = $form->get('name')->getData();
            /** @var OverrideTypeEnum $type */
            $type = $form->get('type')->getData();
            $location = $form->get('location')->getData();
            \assert(null === $location || $location instanceof Location);

            // App-side guard: MySQL's unique index treats NULL location_id
            // values as distinct, so the DB constraint can't catch a
            // duplicate state-wide override on its own.
            if (null !== $this->repository->findOneByConflict($company, $state, $location, $date)) {
                $form->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.holidays.override.flash.duplicate')
                ));

                return $this->render('admin/holidays/overrides/form.html.twig', [
                    'form' => $form,
                    'is_new' => true,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $override = new HolidayOverride($company, $state, $date, $name, $type, $location);

            try {
                $this->entityManager->persist($override);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $form->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.holidays.override.flash.duplicate')
                ));

                return $this->render('admin/holidays/overrides/form.html.twig', [
                    'form' => $form,
                    'is_new' => true,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.holidays.override.flash.created'));

            return $this->redirectToRoute('app_admin_holiday_override_index');
        }

        return $this->render('admin/holidays/overrides/form.html.twig', [
            'form' => $form,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, HolidayOverride $override): Response
    {
        $company = $this->requireCompany();
        if ($override->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(HolidayOverrideType::class, null, ['company' => $company]);
        $form->get('federalState')->setData($override->getFederalState());
        $form->get('date')->setData($override->getDate());
        $form->get('name')->setData($override->getName());
        $form->get('type')->setData($override->getType());
        $form->get('location')->setData($override->getLocation());

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var FederalState $state */
            $state = $form->get('federalState')->getData();
            /** @var \DateTimeImmutable $date */
            $date = $form->get('date')->getData();
            /** @var string $name */
            $name = $form->get('name')->getData();
            /** @var OverrideTypeEnum $type */
            $type = $form->get('type')->getData();
            $location = $form->get('location')->getData();
            \assert(null === $location || $location instanceof Location);

            $conflict = $this->repository->findOneByConflict($company, $state, $location, $date);
            if (null !== $conflict && $conflict !== $override) {
                $form->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.holidays.override.flash.duplicate')
                ));

                return $this->render('admin/holidays/overrides/form.html.twig', [
                    'form' => $form,
                    'is_new' => false,
                    'override' => $override,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $override->update($state, $date, $name, $type, $location);

            try {
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $form->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('admin.holidays.override.flash.duplicate')
                ));

                return $this->render('admin/holidays/overrides/form.html.twig', [
                    'form' => $form,
                    'is_new' => false,
                    'override' => $override,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.holidays.override.flash.updated'));

            return $this->redirectToRoute('app_admin_holiday_override_index');
        }

        return $this->render('admin/holidays/overrides/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'override' => $override,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, HolidayOverride $override): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete-override-'.$override->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->entityManager->remove($override);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.holidays.override.flash.deleted'));

        return $this->redirectToRoute('app_admin_holiday_override_index');
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
