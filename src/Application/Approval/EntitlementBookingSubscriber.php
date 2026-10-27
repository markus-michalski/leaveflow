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

use App\Domain\Entity\LeaveRequest;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Mutates entitlement balances in reaction to approval-workflow transitions.
 *
 * - approve: consume entitlement hours for deducting absence types
 * - confirm_cancel: release previously-consumed hours back to the pool
 *
 * Other transitions (reject, cancel_pending, cancel_recorded, request_cancel,
 * deny_cancel) do not touch hoursUsed: pending/recorded requests never booked
 * hours in the first place, and a denied cancellation leaves the approval
 * untouched.
 *
 * Persist-only: the caller that triggered the workflow is responsible for
 * flushing, same pattern as ApprovalAuditSubscriber.
 */
#[AsEventListener(event: 'workflow.leave_request_approval.completed.approve')]
#[AsEventListener(event: 'workflow.leave_request_approval.completed.confirm_cancel', method: 'onConfirmCancel')]
final readonly class EntitlementBookingSubscriber
{
    public function __construct(
        private LeaveRequestEntitlementBooker $booker,
    ) {
    }

    /** @param CompletedEvent<LeaveRequest> $event */
    public function __invoke(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        // @phpstan-ignore instanceof.alwaysTrue (defence-in-depth guard; event subject is typed via PHPDoc but runtime may differ)
        if (!$subject instanceof LeaveRequest) {
            return;
        }

        $this->booker->consume($subject);
    }

    /** @param CompletedEvent<LeaveRequest> $event */
    public function onConfirmCancel(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        // @phpstan-ignore instanceof.alwaysTrue (defence-in-depth guard; event subject is typed via PHPDoc but runtime may differ)
        if (!$subject instanceof LeaveRequest) {
            return;
        }

        $this->booker->release($subject);
    }
}
