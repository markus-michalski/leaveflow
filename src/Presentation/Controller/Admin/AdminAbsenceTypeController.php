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

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Repository\AbsenceTypeRepository;
use App\Domain\Repository\CompanyRepository;
use App\Presentation\Form\AbsenceTypeFormType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/absence-types', name: 'app_admin_absence_type_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAbsenceTypeController extends AbstractController
{
    public function __construct(
        private readonly AbsenceTypeRepository $repository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->requireCompany();

        return $this->render('admin/absence_types/index.html.twig', [
            'entries' => $this->repository->findAllByCompany($company),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->requireCompany();
        $form = $this->createForm(AbsenceTypeFormType::class);
        // Sensible defaults so the color picker isn't empty on first render.
        $form->get('color')->setData('#3B82F6');
        $form->get('active')->setData(true);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $deducts = (bool) $form->get('deductsFromLeave')->getData();
                /** @var \App\Domain\Enum\LeaveEntitlementType|null $bucket */
                $bucket = $form->get('requiredBucket')->getData();
                $entry = new AbsenceType(
                    $company,
                    (string) $form->get('name')->getData(),
                    $deducts,
                    (bool) $form->get('requiresApproval')->getData(),
                    (string) $form->get('color')->getData(),
                    (bool) $form->get('active')->getData(),
                    // Bucket only meaningful for deducting types — silently
                    // discard a stale value if the admin unticked deducts.
                    $deducts ? $bucket : null,
                    illnessTracking: (bool) $form->get('illnessTracking')->getData(),
                );
                $this->entityManager->persist($entry);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError(
                    $this->translator->trans('admin.absence_types.flash.duplicate')
                ));

                return $this->render('admin/absence_types/form.html.twig', [
                    'form' => $form,
                    'is_new' => true,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            } catch (\InvalidArgumentException $e) {
                // Domain validation (e.g. non-hex color) — surface as form error, not 500.
                $form->addError(new FormError($e->getMessage()));

                return $this->render('admin/absence_types/form.html.twig', [
                    'form' => $form,
                    'is_new' => true,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.absence_types.flash.created'));

            return $this->redirectToRoute('app_admin_absence_type_index');
        }

        return $this->render('admin/absence_types/form.html.twig', [
            'form' => $form,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, AbsenceType $entry): Response
    {
        $form = $this->createForm(AbsenceTypeFormType::class);
        $form->get('name')->setData($entry->getName());
        $form->get('deductsFromLeave')->setData($entry->deductsFromLeave());
        $form->get('requiresApproval')->setData($entry->requiresApproval());
        $form->get('color')->setData($entry->getColor());
        $form->get('active')->setData($entry->isActive());
        $form->get('requiredBucket')->setData($entry->getRequiredBucket());
        $form->get('illnessTracking')->setData($entry->isIllnessTracking());

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var \App\Domain\Enum\LeaveEntitlementType|null $bucket */
                $bucket = $form->get('requiredBucket')->getData();
                $entry->update(
                    (string) $form->get('name')->getData(),
                    (bool) $form->get('deductsFromLeave')->getData(),
                    (bool) $form->get('requiresApproval')->getData(),
                    (string) $form->get('color')->getData(),
                    $bucket,
                    illnessTracking: (bool) $form->get('illnessTracking')->getData(),
                );
                // active is independent of update()
                if ((bool) $form->get('active')->getData()) {
                    $entry->activate();
                } else {
                    $entry->deactivate();
                }

                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError(
                    $this->translator->trans('admin.absence_types.flash.duplicate')
                ));

                return $this->render('admin/absence_types/form.html.twig', [
                    'form' => $form,
                    'is_new' => false,
                    'entry' => $entry,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->render('admin/absence_types/form.html.twig', [
                    'form' => $form,
                    'is_new' => false,
                    'entry' => $entry,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.absence_types.flash.updated'));

            return $this->redirectToRoute('app_admin_absence_type_index');
        }

        return $this->render('admin/absence_types/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'entry' => $entry,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleActive(Request $request, AbsenceType $entry): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('toggle-absence-type-'.$entry->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($entry->isActive()) {
            $entry->deactivate();
            $flashKey = 'admin.absence_types.flash.deactivated';
        } else {
            $entry->activate();
            $flashKey = 'admin.absence_types.flash.activated';
        }

        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans($flashKey));

        return $this->redirectToRoute('app_admin_absence_type_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, AbsenceType $entry): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete-absence-type-'.$entry->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.absence_types.flash.deleted'));

        return $this->redirectToRoute('app_admin_absence_type_index');
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
