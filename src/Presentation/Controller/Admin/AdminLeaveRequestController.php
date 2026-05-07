<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Admin\AdminTypeChangeService;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\LeaveRequestRepository;
use App\Presentation\Form\LeaveRequestTypeChangeFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-only admin view of all leave requests in the company.
 *
 * Phase 5 surfaces the list and the per-request breakdown so admins can sanity-
 * check the data produced by employees. Approve/reject actions land in Phase 6
 * via the Symfony Workflow state machine.
 */
#[Route('/admin/leave-requests', name: 'app_admin_leave_request_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminLeaveRequestController extends AbstractController
{
    public function __construct(
        private readonly LeaveRequestRepository $repository,
        private readonly AdminTypeChangeService $typeChangeService,
        private readonly EmployeeRepository $employeeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->requireCompany();

        $statusFilter = $request->query->get('status');
        $status = \is_string($statusFilter) ? LeaveRequestStatus::tryFrom($statusFilter) : null;

        $qb = $this->repository->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->andWhere('e.company = :company')
            ->setParameter('company', $company)
            ->orderBy('r.requestedAt', 'DESC');

        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status->value);
        }

        return $this->render('admin/leave_requests/index.html.twig', [
            'requests' => $qb->getQuery()->getResult(),
            'status_filter' => $status,
            'statuses' => LeaveRequestStatus::cases(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $company = $this->requireCompany();

        $leaveRequest = $this->repository->find($id);
        if (null === $leaveRequest || $leaveRequest->getEmployee()->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/leave_requests/show.html.twig', [
            'leaveRequest' => $leaveRequest,
        ]);
    }

    #[Route('/{id}/change-type', name: 'change_type', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function changeType(int $id, Request $request): Response
    {
        $company = $this->requireCompany();

        $leaveRequest = $this->repository->find($id);
        if (null === $leaveRequest || $leaveRequest->getEmployee()->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        // Service rejects non-Approved later, but blocking here too keeps the
        // form off-limits to admins who'd otherwise hit a fail-late 422.
        if (LeaveRequestStatus::Approved !== $leaveRequest->getStatus()) {
            $this->addFlash('error', $this->translator->trans('admin.leave_requests.type_change.error.only_approved'));

            return $this->redirectToRoute('app_admin_leave_request_show', ['id' => $id]);
        }

        $form = $this->createForm(LeaveRequestTypeChangeFormType::class, null, ['company' => $company]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var AbsenceType $newType */
            $newType = $form->get('newType')->getData();
            /** @var string $reason */
            $reason = $form->get('reason')->getData();

            try {
                $this->typeChangeService->changeAbsenceType(
                    $leaveRequest,
                    $newType,
                    $reason,
                    $this->resolveAdminEmployee($company),
                );
                $this->entityManager->flush();
            } catch (\DomainException $e) {
                $message = str_contains($e->getMessage(), 'Insufficient')
                    ? $this->translator->trans('admin.leave_requests.type_change.error.overdraft')
                    : (str_contains($e->getMessage(), 'same type')
                        ? $this->translator->trans('admin.leave_requests.type_change.error.same_type')
                        : $e->getMessage());
                $form->addError(new FormError($message));

                return $this->render('admin/leave_requests/change_type.html.twig', [
                    'form' => $form,
                    'leaveRequest' => $leaveRequest,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', $this->translator->trans('admin.leave_requests.type_change.flash.success'));

            return $this->redirectToRoute('app_admin_leave_request_show', ['id' => $id]);
        }

        $status = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/leave_requests/change_type.html.twig', [
            'form' => $form,
            'leaveRequest' => $leaveRequest,
        ], new Response('', $status));
    }

    /**
     * Maps the logged-in admin User to its Employee for the audit-trail actor.
     * Returns null for external IT-only admin accounts (no HR record) — the
     * audit entry's actor is nullable, the notification payload falls back
     * to a generic "Administrator" label.
     */
    private function resolveAdminEmployee(Company $company): ?Employee
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $employee = $this->employeeRepository->findOneBy(['user' => $user]);
        if (!$employee instanceof Employee || $employee->getCompany() !== $company) {
            return null;
        }

        return $employee;
    }

    private function requireCompany(): Company
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user->getCompany();
    }
}
