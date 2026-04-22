<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Repository\LeaveRequestAuditEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit log for LeaveRequest state transitions.
 *
 * Each entry records one successful workflow transition (approve/reject/
 * cancel_*) with its actor, from/to states, optional reason, and timestamp.
 * Written exclusively by ApprovalAuditSubscriber — no application code should
 * construct these directly except for testing.
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
    ) {
        if ('' === trim($transition)) {
            throw new \InvalidArgumentException('LeaveRequestAuditEntry.transition must not be empty.');
        }
        if ($fromStatus === $toStatus) {
            throw new \InvalidArgumentException(\sprintf('LeaveRequestAuditEntry.fromStatus and toStatus must differ (both "%s").', $fromStatus->value));
        }
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
}
