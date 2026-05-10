<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\LeaveEntitlementAuditChangeType;
use App\Domain\Repository\LeaveEntitlementAuditEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit log for {@see LeaveEntitlement} mutations.
 *
 * Each entry records one successful manual override with actor, old/new
 * value, reason, and timestamp. Two shapes are persisted:
 *
 * - **HoursGrantedAdjusted** — admin changed `hoursGranted` (typo fix,
 *   adding overtime conversion etc.). Hours fields populated, expiry
 *   fields null.
 * - **ExpiresAtAdjusted** — admin extended/cleared a Carryover deadline
 *   (BAG illness/parental-leave case law). Expiry fields populated,
 *   hours fields null. Either side may be null (a freshly granted carry
 *   without expiry, or "deadline removed").
 *
 * The constructor accepts the union; use the named factories for safer
 * call-sites — they enforce the per-shape invariants (reason required,
 * old/new must actually differ).
 *
 * Application code must not construct these directly except via the
 * controller paths that update entitlements; otherwise audit drift
 * gets silently introduced.
 */
#[ORM\Entity(repositoryClass: LeaveEntitlementAuditEntryRepository::class)]
#[ORM\Table(name: 'leave_entitlement_audit_entries')]
#[ORM\Index(name: 'idx_audit_entitlement', columns: ['leave_entitlement_id', 'occurred_at'])]
class LeaveEntitlementAuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'leave_entitlement_id', nullable: false)]
        private LeaveEntitlement $entitlement,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'actor_id', nullable: true)]
        private ?Employee $actor,
        #[ORM\Column(name: 'change_type', length: 40, enumType: LeaveEntitlementAuditChangeType::class)]
        private LeaveEntitlementAuditChangeType $changeType,
        #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(name: 'reason', type: Types::TEXT)]
        private string $reason,
        #[ORM\Column(name: 'old_hours_granted', type: Types::FLOAT, nullable: true)]
        private ?float $oldHoursGranted = null,
        #[ORM\Column(name: 'new_hours_granted', type: Types::FLOAT, nullable: true)]
        private ?float $newHoursGranted = null,
        #[ORM\Column(name: 'old_expires_at', type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $oldExpiresAt = null,
        #[ORM\Column(name: 'new_expires_at', type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $newExpiresAt = null,
    ) {
        if ('' === trim($this->reason)) {
            throw new \InvalidArgumentException('LeaveEntitlementAuditEntry.reason must not be empty.');
        }
    }

    public static function forHoursAdjustment(
        LeaveEntitlement $entitlement,
        ?Employee $actor,
        float $oldHoursGranted,
        float $newHoursGranted,
        \DateTimeImmutable $occurredAt,
        string $reason,
    ): self {
        if (abs($oldHoursGranted - $newHoursGranted) < 0.0001) {
            throw new \InvalidArgumentException('LeaveEntitlementAuditEntry must record an actual change in hoursGranted.');
        }
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('LeaveEntitlementAuditEntry.reason must not be empty.');
        }

        return new self(
            entitlement: $entitlement,
            actor: $actor,
            changeType: LeaveEntitlementAuditChangeType::HoursGrantedAdjusted,
            occurredAt: $occurredAt,
            reason: $reason,
            oldHoursGranted: $oldHoursGranted,
            newHoursGranted: $newHoursGranted,
        );
    }

    public static function forExpiryAdjustment(
        LeaveEntitlement $entitlement,
        ?Employee $actor,
        ?\DateTimeImmutable $oldExpiresAt,
        ?\DateTimeImmutable $newExpiresAt,
        \DateTimeImmutable $occurredAt,
        string $reason,
    ): self {
        $oldKey = $oldExpiresAt?->format('Y-m-d');
        $newKey = $newExpiresAt?->format('Y-m-d');
        if ($oldKey === $newKey) {
            throw new \InvalidArgumentException('LeaveEntitlementAuditEntry must record an actual change in expiresAt.');
        }
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('LeaveEntitlementAuditEntry.reason must not be empty.');
        }

        return new self(
            entitlement: $entitlement,
            actor: $actor,
            changeType: LeaveEntitlementAuditChangeType::ExpiresAtAdjusted,
            occurredAt: $occurredAt,
            reason: $reason,
            oldExpiresAt: $oldExpiresAt?->setTime(0, 0),
            newExpiresAt: $newExpiresAt?->setTime(0, 0),
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntitlement(): LeaveEntitlement
    {
        return $this->entitlement;
    }

    public function getActor(): ?Employee
    {
        return $this->actor;
    }

    public function getChangeType(): LeaveEntitlementAuditChangeType
    {
        return $this->changeType;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getOldHoursGranted(): ?float
    {
        return $this->oldHoursGranted;
    }

    public function getNewHoursGranted(): ?float
    {
        return $this->newHoursGranted;
    }

    public function getOldExpiresAt(): ?\DateTimeImmutable
    {
        return $this->oldExpiresAt;
    }

    public function getNewExpiresAt(): ?\DateTimeImmutable
    {
        return $this->newExpiresAt;
    }
}
