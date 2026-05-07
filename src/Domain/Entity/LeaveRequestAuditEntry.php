<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Repository\LeaveRequestAuditEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit log for LeaveRequest mutations.
 *
 * Each entry records one successful change to a LeaveRequest with its actor,
 * from/to states, optional reason, and timestamp. Two shapes are persisted:
 *
 * - **Status transitions** (approve/reject/cancel_*): fromStatus differs from
 *   toStatus, fromAbsenceType + toAbsenceType are null. Written by
 *   ApprovalAuditSubscriber.
 * - **Type changes** (Phase 9 admin reclassification): fromAbsenceType differs
 *   from toAbsenceType, status fields mirror each other (no status change).
 *   Written by AdminTypeChangeService.
 *
 * The constructor accepts both shapes; the type-change factory below provides
 * a more readable call-site for the new flow.
 *
 * Application code must not construct these directly except via the dedicated
 * subscriber/service pair — anything else risks audit/notification drift.
 *
 * The `actor` is nullable so later phases can add system-triggered transitions
 * (auto-cancellation on employee offboarding, scheduled sweeps) without a
 * schema migration.
 */
#[ORM\Entity(repositoryClass: LeaveRequestAuditEntryRepository::class)]
#[ORM\Table(name: 'leave_request_audit_entries')]
#[ORM\Index(name: 'idx_audit_leave_request', columns: ['leave_request_id', 'occurred_at'])]
class LeaveRequestAuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'leave_request_id', nullable: false)]
        private LeaveRequest $leaveRequest,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'actor_id', nullable: true)]
        private ?Employee $actor,
        #[ORM\Column(name: 'transition', length: 40)]
        private string $transition,
        #[ORM\Column(name: 'from_status', length: 20, enumType: LeaveRequestStatus::class)]
        private LeaveRequestStatus $fromStatus,
        #[ORM\Column(name: 'to_status', length: 20, enumType: LeaveRequestStatus::class)]
        private LeaveRequestStatus $toStatus,
        #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(name: 'reason', type: Types::TEXT, nullable: true)]
        private ?string $reason = null,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'from_absence_type_id', nullable: true)]
        private ?AbsenceType $fromAbsenceType = null,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'to_absence_type_id', nullable: true)]
        private ?AbsenceType $toAbsenceType = null,
    ) {
        if ('' === trim($transition)) {
            throw new \InvalidArgumentException('LeaveRequestAuditEntry.transition must not be empty.');
        }

        $statusChanged = $fromStatus !== $toStatus;
        $typeChanged = null !== $fromAbsenceType
            && null !== $toAbsenceType
            && $fromAbsenceType !== $toAbsenceType;

        if (!$statusChanged && !$typeChanged) {
            throw new \InvalidArgumentException(\sprintf('LeaveRequestAuditEntry must record either a status change (got both "%s") or a type change (got %s).', $fromStatus->value, null === $fromAbsenceType ? 'no type fields' : 'identical types'));
        }

        // Type-change shape requires both type fields to be set: a half-set
        // pair would lose information about what changed.
        if ((null === $fromAbsenceType) !== (null === $toAbsenceType)) {
            throw new \InvalidArgumentException('LeaveRequestAuditEntry.fromAbsenceType and toAbsenceType must be set together (both or neither).');
        }
    }

    /**
     * Records an admin reclassification of a request's absence type. Status
     * is mirrored on both sides since type-changes do not move the workflow
     * state. Reason is required for type changes — admins must explain the
     * reclassification.
     */
    public static function forTypeChange(
        LeaveRequest $leaveRequest,
        ?Employee $actor,
        AbsenceType $fromAbsenceType,
        AbsenceType $toAbsenceType,
        \DateTimeImmutable $occurredAt,
        string $reason,
    ): self {
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('LeaveRequestAuditEntry.reason must not be empty for type changes.');
        }

        return new self(
            leaveRequest: $leaveRequest,
            actor: $actor,
            transition: 'admin_type_change',
            fromStatus: $leaveRequest->getStatus(),
            toStatus: $leaveRequest->getStatus(),
            occurredAt: $occurredAt,
            reason: $reason,
            fromAbsenceType: $fromAbsenceType,
            toAbsenceType: $toAbsenceType,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLeaveRequest(): LeaveRequest
    {
        return $this->leaveRequest;
    }

    public function getActor(): ?Employee
    {
        return $this->actor;
    }

    public function getTransition(): string
    {
        return $this->transition;
    }

    public function getFromStatus(): LeaveRequestStatus
    {
        return $this->fromStatus;
    }

    public function getToStatus(): LeaveRequestStatus
    {
        return $this->toStatus;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getFromAbsenceType(): ?AbsenceType
    {
        return $this->fromAbsenceType;
    }

    public function getToAbsenceType(): ?AbsenceType
    {
        return $this->toAbsenceType;
    }
}
