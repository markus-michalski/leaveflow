<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\DepartmentRepository;
use App\Presentation\Form\DepartmentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/departments', name: 'app_admin_department_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminDepartmentController extends AbstractController
{
    public function __construct(
        private readonly DepartmentRepository $departmentRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->requireCompany();

        return $this->render('admin/departments/index.html.twig', [
            'departments' => $this->departmentRepository->findByCompany($company),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->requireCompany();

        $form = $this->createForm(DepartmentType::class, null, ['company' => $company]);
        $form->get('active')->setData(true);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ?Employee $lead */
            $lead = $form->get('lead')->getData();
            /** @var ?Employee $deputy */
            $deputy = $form->get('deputy')->getData();

            try {
                $department = new Department(
                    company: $company,
                    name: (string) $form->get('name')->getData(),
                    lead: $lead,
                    deputy: $deputy,
                );
            } catch (\InvalidArgumentException $e) {
                $form->addError(new \Symfony\Component\Form\FormError($e->getMessage()));

                return $this->render('admin/departments/form.html.twig', [
                    'form' => $form,
                    'is_new' => true,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            if (false === (bool) $form->get('active')->getData()) {
                $department->deactivate();
            }

            $this->entityManager->persist($department);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('admin.departments.flash.created', ['%name%' => $department->getName()]));

            return $this->redirectToRoute('app_admin_department_index');
        }

        return $this->render('admin/departments/form.html.twig', [
            'form' => $form,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Department $department): Response
    {
        $company = $department->getCompany();

        $form = $this->createForm(DepartmentType::class, null, ['company' => $company]);
        $form->get('name')->setData($department->getName());
        $form->get('lead')->setData($department->getLead());
        $form->get('deputy')->setData($department->getDeputy());
        $form->get('active')->setData($department->isActive());

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ?Employee $lead */
            $lead = $form->get('lead')->getData();
            /** @var ?Employee $deputy */
            $deputy = $form->get('deputy')->getData();

            try {
                $department->rename((string) $form->get('name')->getData());
                $department->assignLead($lead);
                $department->assignDeputy($deputy);
            } catch (\InvalidArgumentException $e) {
                $form->addError(new \Symfony\Component\Form\FormError($e->getMessage()));

                return $this->render('admin/departments/form.html.twig', [
                    'form' => $form,
                    'is_new' => false,
                    'department' => $department,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            if ((bool) $form->get('active')->getData()) {
                $department->activate();
            } else {
                $department->deactivate();
            }

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('admin.departments.flash.updated', ['%name%' => $department->getName()]));

            return $this->redirectToRoute('app_admin_department_index');
        }

        return $this->render('admin/departments/form.html.twig', [
            'form' => $form,
            'is_new' => false,
            'department' => $department,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleActive(Request $request, Department $department): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('toggle-department-'.$department->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($department->isActive()) {
            $department->deactivate();
            $flashKey = 'admin.departments.flash.deactivated';
        } else {
            $department->activate();
            $flashKey = 'admin.departments.flash.activated';
        }

        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans($flashKey, ['%name%' => $department->getName()]));

        return $this->redirectToRoute('app_admin_department_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Department $department): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete-department-'.$department->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = $department->getName();
        $this->entityManager->remove($department);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.departments.flash.deleted', ['%name%' => $name]));

        return $this->redirectToRoute('app_admin_department_index');
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
