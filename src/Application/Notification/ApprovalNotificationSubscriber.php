<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Application\Approval\ApproverResolverInterface;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\NotificationType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Translates LeaveRequest workflow transitions into outbound notifications.
 *
 * Subscribes to `workflow.leave_request_approval.completed` and dispatches
 * the matching NotificationType for transitions that warrant a notification:
 *
 *   approve, reject               -> ApprovalDecided to employee
 *   request_cancel                -> CancelRequested to approver
 *   confirm_cancel, deny_cancel   -> CancelDecided to employee
 *
 * The remaining two transitions (cancel_pending, cancel_recorded) are user
 * self-cancellations of unapproved requests and produce no notification.
 *
 * Persist-only: the dispatcher persists the Notification and the caller
 * (controller that triggered the workflow) flushes — same UnitOfWork
 * convention as ApprovalAuditSubscriber.
 *
 * If the recipient (employee or approver) has no User account the
 * notification is silently skipped — Phase 2 explicitly allows
 * Employee-without-User for ex-employees and imports.
 */
#[AsEventListener(event: 'workflow.leave_request_approval.completed')]
final readonly class ApprovalNotificationSubscriber
{
    public function __construct(
        private NotificationDispatcherInterface $dispatcher,
        private ApproverResolverInterface $approverResolver,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $request = $event->getSubject();
        if (!$request instanceof LeaveRequest) {
            return;
        }

        $transition = $event->getTransition();
        if (null === $transition) {
            return;
        }

        $context = $event->getContext();
        $actor = $context['actor'] ?? null;
        $reason = (isset($context['reason']) && \is_string($context['reason']) && '' !== $context['reason'])
            ? $context['reason']
            : null;

        match ($transition->getName()) {
            'approve' => $this->dispatchApprovalDecided($request, $actor, 'approved', null),
            'reject' => $this->dispatchApprovalDecided($request, $actor, 'rejected', $reason),
            'request_cancel' => $this->dispatchCancelRequested($request),
            'confirm_cancel' => $this->dispatchCancelDecided($request, $actor, 'confirmed', null),
            'deny_cancel' => $this->dispatchCancelDecided($request, $actor, 'denied', $reason),
            default => null,
        };
    }

    private function dispatchApprovalDecided(LeaveRequest $request, mixed $actor, string $decision, ?string $reason): void
    {
        $recipient = $request->getEmployee()->getUser();
        if (null === $recipient) {
            return;
        }

        $approverName = $actor instanceof Employee ? $actor->getFullName() : '';

        $payload = [
            'decision' => $decision,
            'approverName' => $approverName,
            'absenceTypeName' => $request->getAbsenceType()->getName(),
            'startDate' => $request->getStartDate()->format('d.m.Y'),
            'endDate' => $request->getEndDate()->format('d.m.Y'),
        ];
        if (null !== $reason) {
            $payload['reason'] = $reason;
        }

        $this->dispatcher->dispatch(
            type: NotificationType::ApprovalDecided,
            recipient: $recipient,
            payload: $payload,
            relatedEntityType: LeaveRequest::class,
            relatedEntityId: $request->getId(),
        );
    }

    private function dispatchCancelRequested(LeaveRequest $request): void
    {
        $approver = $this->approverResolver->resolve($request);
        if (null === $approver) {
            return;
        }

        $recipient = $approver->getUser();
        if (null === $recipient) {
            return;
        }

        $this->dispatcher->dispatch(
            type: NotificationType::CancelRequested,
            recipient: $recipient,
            payload: [
                'employeeName' => $request->getEmployee()->getFullName(),
                'absenceTypeName' => $request->getAbsenceType()->getName(),
                'startDate' => $request->getStartDate()->format('d.m.Y'),
                'endDate' => $request->getEndDate()->format('d.m.Y'),
            ],
            relatedEntityType: LeaveRequest::class,
            relatedEntityId: $request->getId(),
        );
    }

    private function dispatchCancelDecided(LeaveRequest $request, mixed $actor, string $decision, ?string $reason): void
    {
        $recipient = $request->getEmployee()->getUser();
        if (null === $recipient) {
            return;
        }

        $approverName = $actor instanceof Employee ? $actor->getFullName() : '';

        $payload = [
            'decision' => $decision,
            'approverName' => $approverName,
            'absenceTypeName' => $request->getAbsenceType()->getName(),
            'startDate' => $request->getStartDate()->format('d.m.Y'),
            'endDate' => $request->getEndDate()->format('d.m.Y'),
        ];
        if (null !== $reason) {
            $payload['reason'] = $reason;
        }

        $this->dispatcher->dispatch(
            type: NotificationType::CancelDecided,
            recipient: $recipient,
            payload: $payload,
            relatedEntityType: LeaveRequest::class,
            relatedEntityId: $request->getId(),
        );
    }
}
