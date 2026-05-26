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

namespace App\Presentation\Controller\Manager;

use App\Application\Approval\ApprovalWorkflow;
use App\Application\Approval\CancellationNotAllowedException;
use App\Application\Approval\InvalidTransitionException;
use App\Application\Approval\LeaveRequestApprovalAttribute;
use App\Application\Approval\RejectionReasonRequiredException;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\LeaveRequestAuditEntryRepository;
use App\Domain\Repository\LeaveRequestRepository;
use App\Presentation\Form\RejectLeaveRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Manager-facing approval surface for LeaveRequests.
 *
 * List + detail + the four state-machine actions (approve/reject/confirm-
 * cancel/deny-cancel). Access is gated by the approval voter — the
 * controller hands the subject to the Voter instead of re-implementing
 * dept-lead/deputy checks.
 *
 * Admins see everything company-wide (bypass via repository). Managers see
 * only requests routed to them by ApproverResolver's rules (dept.lead or
 * dept.deputy, excluding self-approval).
 */
#[Route('/manager/approvals', name: 'app_manager_approval_')]
#[IsGranted('ROLE_MANAGER')]
final class ManagerApprovalController extends AbstractController
{
    public function __construct(
        private readonly LeaveRequestRepository $leaveRequestRepository,
        private readonly LeaveRequestAuditEntryRepository $auditRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly ApprovalWorkflow $approvalWorkflow,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        // Toggle: ?filter=open (default, current behavior) or ?filter=all
        // (full team / company history, regardless of status). Admin gets
        // company-wide scope on both; manager stays inside their own
        // department (matches ApproverResolver's lead/deputy boundary).
        $filter = 'all' === $request->query->get('filter') ? 'all' : 'open';

        if (UserRole::Admin === $user->getRole()) {
            $company = $this->requireCompany();
            $requests = 'all' === $filter
                ? $this->leaveRequestRepository->findAllInCompany($company)
                : $this->leaveRequestRepository->findActionableInCompany($company);
        } else {
            $employee = $user->getEmployee();
            if (!$employee instanceof Employee) {
                // Non-admin without an employee link cannot approve anything.
                $requests = [];
            } else {
                $requests = 'all' === $filter
                    ? $this->leaveRequestRepository->findAllByApprover($employee)
                    : $this->leaveRequestRepository->findActionableByApprover($employee);
            }
        }

        return $this->render('manager/approvals/index.html.twig', [
            'requests' => $requests,
            'filter' => $filter,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(LeaveRequest $leaveRequest): Response
    {
        // Loosened from the four action attributes to View so a dept lead or
        // deputy can also pull up an already-decided request from the
        // history tab. The show template already gates the action forms on
        // status, so loosening the GET doesn't expose extra buttons.
        $this->denyAccessUnlessGranted(LeaveRequestApprovalAttribute::View->value, $leaveRequest);

        return $this->render('manager/approvals/show.html.twig', [
            'leaveRequest' => $leaveRequest,
            'auditEntries' => $this->auditRepository->findByLeaveRequest($leaveRequest),
            'rejectForm' => $this->createForm(RejectLeaveRequestFormType::class),
            'denyCancelForm' => $this->createForm(RejectLeaveRequestFormType::class),
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(Request $request, LeaveRequest $leaveRequest): Response
    {
        $this->denyAccessUnlessGranted(LeaveRequestApprovalAttribute::Approve->value, $leaveRequest);

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('approve-leave-request-'.$leaveRequest->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->approvalWorkflow->approve($leaveRequest, $this->currentEmployee());
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('manager.approvals.flash.approved', [
                '%name%' => $leaveRequest->getEmployee()->getFullName(),
            ]));
        } catch (InvalidTransitionException $e) {
            $this->addFlash('error', $this->translator->trans('manager.approvals.flash.invalid_transition'));
        }

        return $this->redirectToRoute('app_manager_approval_index');
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(Request $request, LeaveRequest $leaveRequest): Response
    {
        $this->denyAccessUnlessGranted(LeaveRequestApprovalAttribute::Reject->value, $leaveRequest);

        $form = $this->createForm(RejectLeaveRequestFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('manager/approvals/show.html.twig', [
                'leaveRequest' => $leaveRequest,
                'auditEntries' => $this->auditRepository->findByLeaveRequest($leaveRequest),
                'rejectForm' => $form,
                'denyCancelForm' => $this->createForm(RejectLeaveRequestFormType::class),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $reason = (string) $form->get('reason')->getData();

        try {
            $this->approvalWorkflow->reject($leaveRequest, $this->currentEmployee(), $reason);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('manager.approvals.flash.rejected', [
                '%name%' => $leaveRequest->getEmployee()->getFullName(),
            ]));
        } catch (RejectionReasonRequiredException) {
            $form->get('reason')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('manager.approvals.reject.reason_required'),
            ));

            return $this->render('manager/approvals/show.html.twig', [
                'leaveRequest' => $leaveRequest,
                'auditEntries' => $this->auditRepository->findByLeaveRequest($leaveRequest),
                'rejectForm' => $form,
                'denyCancelForm' => $this->createForm(RejectLeaveRequestFormType::class),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        } catch (InvalidTransitionException) {
            $this->addFlash('error', $this->translator->trans('manager.approvals.flash.invalid_transition'));
        }

        return $this->redirectToRoute('app_manager_approval_index');
    }

    #[Route('/{id}/confirm-cancel', name: 'confirm_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirmCancel(Request $request, LeaveRequest $leaveRequest): Response
    {
        $this->denyAccessUnlessGranted(LeaveRequestApprovalAttribute::ConfirmCancel->value, $leaveRequest);

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('confirm-cancel-leave-request-'.$leaveRequest->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->approvalWorkflow->confirmCancel($leaveRequest, $this->currentEmployee());
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('manager.approvals.flash.cancel_confirmed', [
                '%name%' => $leaveRequest->getEmployee()->getFullName(),
            ]));
        } catch (InvalidTransitionException) {
            $this->addFlash('error', $this->translator->trans('manager.approvals.flash.invalid_transition'));
        } catch (CancellationNotAllowedException) {
            $this->addFlash('error', $this->translator->trans('manager.approvals.flash.cancellation_not_allowed'));
        }

        return $this->redirectToRoute('app_manager_approval_index');
    }

    #[Route('/{id}/deny-cancel', name: 'deny_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function denyCancel(Request $request, LeaveRequest $leaveRequest): Response
    {
        $this->denyAccessUnlessGranted(LeaveRequestApprovalAttribute::DenyCancel->value, $leaveRequest);

        $form = $this->createForm(RejectLeaveRequestFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('manager/approvals/show.html.twig', [
                'leaveRequest' => $leaveRequest,
                'auditEntries' => $this->auditRepository->findByLeaveRequest($leaveRequest),
                'rejectForm' => $this->createForm(RejectLeaveRequestFormType::class),
                'denyCancelForm' => $form,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $reason = (string) $form->get('reason')->getData();

        try {
            $this->approvalWorkflow->denyCancel($leaveRequest, $this->currentEmployee(), $reason);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('manager.approvals.flash.cancel_denied', [
                '%name%' => $leaveRequest->getEmployee()->getFullName(),
            ]));
        } catch (RejectionReasonRequiredException) {
            $form->get('reason')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('manager.approvals.deny_cancel.reason_required'),
            ));

            return $this->render('manager/approvals/show.html.twig', [
                'leaveRequest' => $leaveRequest,
                'auditEntries' => $this->auditRepository->findByLeaveRequest($leaveRequest),
                'rejectForm' => $this->createForm(RejectLeaveRequestFormType::class),
                'denyCancelForm' => $form,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        } catch (InvalidTransitionException) {
            $this->addFlash('error', $this->translator->trans('manager.approvals.flash.invalid_transition'));
        }

        return $this->redirectToRoute('app_manager_approval_index');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function currentEmployee(): Employee
    {
        $employee = $this->currentUser()->getEmployee();
        if (!$employee instanceof Employee) {
            // Admins without an employee record end up here if they try to act
            // on a request. The UI does not expose the action buttons for them
            // in that case, so this path is a defensive guard.
            throw $this->createAccessDeniedException('Cannot act on leave request without a linked employee record.');
        }

        return $employee;
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
