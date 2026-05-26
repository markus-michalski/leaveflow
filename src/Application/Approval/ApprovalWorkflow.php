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

namespace App\Application\Approval;

use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\LeaveRequestStatus;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Application service that drives the LeaveRequest approval state machine.
 *
 * Wraps Symfony's state_machine to:
 * - translate the "cancel" abstraction into the two place-specific transitions
 *   (cancel_pending vs. cancel_recorded) so callers don't need to know the
 *   current state;
 * - enforce domain rules the workflow config cannot express (rejection reason
 *   non-empty, future-only rule for request_cancel);
 * - wrap transition failures in rich domain exceptions instead of Symfony's
 *   generic NotEnabledTransitionException.
 *
 * The workflow itself (approve/reject/confirm_cancel/deny_cancel authorization
 * and audit logging) is handled by separate event subscribers registered on
 * the workflow's EventDispatcher.
 */
final class ApprovalWorkflow
{
    public function __construct(
        private readonly WorkflowInterface $leaveRequestApprovalStateMachine,
        private readonly ClockInterface $clock,
    ) {
    }

    public function approve(LeaveRequest $request, Employee $approver): void
    {
        $this->apply($request, 'approve', ['actor' => $approver]);
    }

    public function reject(LeaveRequest $request, Employee $approver, string $reason): void
    {
        if ('' === trim($reason)) {
            throw new RejectionReasonRequiredException();
        }

        $this->apply($request, 'reject', [
            'actor' => $approver,
            'reason' => trim($reason),
        ]);
    }

    /**
     * Self-service cancellation for employees — works only while the request
     * is still Pending or Recorded. Approved requests must go through
     * {@see requestCancel} so the manager retains the final word.
     */
    public function cancelDirect(LeaveRequest $request, Employee $actor): void
    {
        $transition = match ($request->getStatus()) {
            LeaveRequestStatus::Pending => 'cancel_pending',
            LeaveRequestStatus::Recorded => 'cancel_recorded',
            default => null,
        };

        if (null === $transition) {
            throw new InvalidTransitionException('cancel', $request->getStatus());
        }

        $this->apply($request, $transition, ['actor' => $actor]);
    }

    /**
     * Employee asks for cancellation of an already-approved leave. Only
     * permitted as long as the leave hasn't started yet — anything that has
     * already begun needs an admin override (out of Phase 6 scope).
     */
    public function requestCancel(LeaveRequest $request, Employee $actor): void
    {
        if (LeaveRequestStatus::Approved === $request->getStatus()) {
            $today = $this->clock->now()->setTime(0, 0);
            if ($request->getStartDate() <= $today) {
                throw new CancellationNotAllowedException($request->getStartDate());
            }
        }

        $this->apply($request, 'request_cancel', ['actor' => $actor]);
    }

    public function confirmCancel(LeaveRequest $request, Employee $manager): void
    {
        $this->apply($request, 'confirm_cancel', ['actor' => $manager]);
    }

    public function denyCancel(LeaveRequest $request, Employee $manager, string $reason): void
    {
        if ('' === trim($reason)) {
            throw new RejectionReasonRequiredException();
        }

        $this->apply($request, 'deny_cancel', [
            'actor' => $manager,
            'reason' => trim($reason),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function apply(LeaveRequest $request, string $transition, array $context): void
    {
        if (!$this->leaveRequestApprovalStateMachine->can($request, $transition)) {
            throw new InvalidTransitionException($transition, $request->getStatus());
        }

        $this->leaveRequestApprovalStateMachine->apply($request, $transition, $context);
    }
}
