<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Approval\LeaveRequestEntitlementBookerInterface;
use App\Application\Notification\NotificationDispatcherInterface;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestAuditEntry;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reclassifies an approved LeaveRequest's absence type with full audit + balance rebalance.
 *
 * Workflow:
 *  1. release the entitlement booking under the OLD type
 *  2. swap the request's absenceType to the NEW type
 *  3. consume against the NEW type — throws DomainException on overdraft
 *  4. write an audit entry (transition = "admin_type_change", reason required)
 *  5. dispatch in-app + email notification to the request owner
 *
 * Persist-only convention: the caller flushes. On any thrown exception the
 * caller MUST NOT flush so partial in-memory mutations don't reach the DB.
 *
 * Restricted to {@see LeaveRequestStatus::Approved} requests — Pending has
 * no booking to rebalance, Recorded/Cancelled/Rejected don't affect balance.
 *
 * Employees without a User account (archived ex-employees, pre-go-live
 * imports) get audit + balance correction but no notification — same
 * silently-skip semantics as ApprovalNotificationSubscriber.
 */
final readonly class AdminTypeChangeService
{
    public function __construct(
        private LeaveRequestEntitlementBookerInterface $booker,
        private NotificationDispatcherInterface $dispatcher,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws \DomainException         when the request is not Approved, the
     *                                  new type equals the current one, or
     *                                  the new type would cause overdraft
     * @throws \InvalidArgumentException when the reason is empty
     */
    public function changeAbsenceType(
        LeaveRequest $request,
        AbsenceType $newType,
        string $reason,
        ?Employee $admin,
    ): void {
        if (LeaveRequestStatus::Approved !== $request->getStatus()) {
            throw new \DomainException(\sprintf('AdminTypeChangeService: request must be approved (got "%s").', $request->getStatus()->value));
        }

        $oldType = $request->getAbsenceType();
        if ($oldType === $newType) {
            throw new \DomainException(\sprintf('AdminTypeChangeService: new type equals current — request already has same type "%s".', $oldType->getName()));
        }

        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('AdminTypeChangeService: reason must not be empty.');
        }

        // 1. Release old booking (no-op if old type doesn't deduct).
        $this->booker->release($request);

        // 2. Flip the type.
        $request->changeAbsenceType($newType);

        // 3. Consume against the new type — throws on overdraft, caller must
        //    not flush to undo the in-memory changes from steps 1-2.
        $this->booker->consume($request);

        // 4. Audit trail.
        $now = $this->clock->now();
        $auditEntry = LeaveRequestAuditEntry::forTypeChange(
            leaveRequest: $request,
            actor: $admin,
            fromAbsenceType: $oldType,
            toAbsenceType: $newType,
            occurredAt: $now,
            reason: $reason,
        );
        $this->entityManager->persist($auditEntry);

        // 5. Notify the employee (silent skip if no User account).
        $recipient = $request->getEmployee()->getUser();
        if (null === $recipient) {
            return;
        }

        $this->dispatcher->dispatch(
            NotificationType::AdminTypeChange,
            $recipient,
            [
                'oldTypeName' => $oldType->getName(),
                'newTypeName' => $newType->getName(),
                'startDate' => $request->getStartDate()->format('d.m.Y'),
                'endDate' => $request->getEndDate()->format('d.m.Y'),
                'adminName' => $admin?->getFullName() ?? 'Administrator',
                'reason' => $reason,
            ],
            'leave_request',
            $request->getId(),
        );
    }
}
