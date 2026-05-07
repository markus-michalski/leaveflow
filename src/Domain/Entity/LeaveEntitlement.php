<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\LeaveEntitlementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Annual leave balance per employee, year, and type.
 *
 * Regular entitlements represent the current year's grant. Carryover
 * entitlements represent the previous year's remainder, usually constrained
 * by an expiry date (BUrlG §7 Abs. 3: default 31.03. following year, extendable
 * by admin for illness/parental leave per BAG/EuGH case law).
 *
 * Invariants:
 * - hoursGranted >= 0
 * - 0 <= hoursUsed <= hoursGranted
 * - Unique per (employee, year, type)
 *
 * Mutations allowed:
 * - consume(hours): used by EntitlementConsumer when a leave request is approved
 * - adjustExpiresAt(date|null): admin corrects carryover deadline
 *
 * Year range 1970-2200 mirrors HolidayCalculator sanity bounds.
 */
#[ORM\Entity(repositoryClass: LeaveEntitlementRepository::class)]
#[ORM\Table(name: 'leave_entitlements')]
#[ORM\UniqueConstraint(name: 'uniq_entitlement_employee_year_type', columns: ['employee_id', 'year', 'type'])]
class LeaveEntitlement
{
    private const int MIN_YEAR = 1970;
    private const int MAX_YEAR = 2200;

    /**
     * Tolerance for float arithmetic in balance comparisons (seconds-level precision).
     */
    private const float SUM_EPSILON = 0.0001;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'hours_used', type: Types::FLOAT)]
    private float $hoursUsed = 0.0;

    /**
     * Idempotency timestamp for the EntitlementExpiringSoon notification.
     * Set by the scheduler handler the first time the 30-day window opens
     * for this entitlement; null thereafter prevents daily re-notification.
     * adjustExpiresAt resets this so admin-extended deadlines re-arm the
     * warning at the new threshold.
     */
    #[ORM\Column(name: 'expiry_warning_sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiryWarningSentAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'employee_id', nullable: false)]
        private Employee $employee,
        #[ORM\Column(type: Types::INTEGER)]
        private int $year,
        #[ORM\Column(length: 20, enumType: LeaveEntitlementType::class)]
        private LeaveEntitlementType $type,
        #[ORM\Column(name: 'hours_granted', type: Types::FLOAT)]
        private float $hoursGranted,
        #[ORM\Column(name: 'expires_at', type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->assertYearInRange($year);
        $this->assertHoursGrantedNotNegative($hoursGranted);

        if (null !== $expiresAt) {
            $this->assertTypeAllowsExpiry($type);
            $normalized = $expiresAt->setTime(0, 0);
            $this->assertExpiresAtRespectsBurlgFloor($normalized, $year);
            $this->expiresAt = $normalized;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): Employee
    {
        return $this->employee;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getType(): LeaveEntitlementType
    {
        return $this->type;
    }

    public function getHoursGranted(): float
    {
        return $this->hoursGranted;
    }

    public function getHoursUsed(): float
    {
        return $this->hoursUsed;
    }

    public function getHoursRemaining(): float
    {
        return $this->hoursGranted - $this->hoursUsed;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredOn(\DateTimeImmutable $date): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return $date->setTime(0, 0) > $this->expiresAt;
    }

    public function consume(float $hours): void
    {
        if ($hours < 0) {
            throw new \InvalidArgumentException('LeaveEntitlement.consume requires non-negative hours.');
        }
        if (0.0 === $hours) {
            return;
        }
        if (($this->hoursUsed + $hours) > ($this->hoursGranted + self::SUM_EPSILON)) {
            throw new \DomainException(\sprintf('Consumption of %.2fh would exceed entitlement balance (%.2fh remaining).', $hours, $this->getHoursRemaining()));
        }

        $this->hoursUsed += $hours;
    }

    /**
     * Returns previously-consumed hours to the balance. Used when an approved
     * leave is cancelled (workflow confirm_cancel).
     */
    public function release(float $hours): void
    {
        if ($hours < 0) {
            throw new \InvalidArgumentException('LeaveEntitlement.release requires non-negative hours.');
        }
        if (0.0 === $hours) {
            return;
        }
        if (($this->hoursUsed - $hours) < -self::SUM_EPSILON) {
            throw new \DomainException(\sprintf('Cannot release %.2fh: only %.2fh have been consumed.', $hours, $this->hoursUsed));
        }

        $this->hoursUsed -= $hours;
        if ($this->hoursUsed < 0) {
            $this->hoursUsed = 0.0;
        }
    }

    /**
     * Allows admins to correct a previously-granted balance — typo fix,
     * adding rolled-over overtime hours, etc. Cannot drop below the
     * already-consumed amount; that would leave the entitlement
     * over-consumed (hoursUsed > hoursGranted), an inconsistent state
     * the booker cannot recover from without reversing approved leave.
     */
    public function adjustGrantedHours(float $newGrant): void
    {
        if ($newGrant < 0) {
            throw new \InvalidArgumentException('LeaveEntitlement.hoursGranted must not be negative.');
        }
        if ($newGrant + self::SUM_EPSILON < $this->hoursUsed) {
            throw new \DomainException(\sprintf('Cannot lower granted hours to %.2fh: %.2fh have already been consumed.', $newGrant, $this->hoursUsed));
        }

        $this->hoursGranted = $newGrant;
    }

    public function adjustExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $normalized = $expiresAt?->setTime(0, 0);
        if (null !== $normalized) {
            $this->assertTypeAllowsExpiry($this->type);
            $this->assertExpiresAtRespectsBurlgFloor($normalized, $this->year);
        }
        $this->expiresAt = $normalized;
        // Reset idempotency: the deadline changed, so a new 30-day window
        // earns a new warning. Phase 9 admin-driven extensions rely on this.
        $this->expiryWarningSentAt = null;
    }

    public function getExpiryWarningSentAt(): ?\DateTimeImmutable
    {
        return $this->expiryWarningSentAt;
    }

    public function markExpiryWarningSent(\DateTimeImmutable $now): void
    {
        $this->expiryWarningSentAt = $now;
    }

    private function assertYearInRange(int $year): void
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new \InvalidArgumentException(\sprintf('LeaveEntitlement.year must be between %d and %d, got %d.', self::MIN_YEAR, self::MAX_YEAR, $year));
        }
    }

    private function assertHoursGrantedNotNegative(float $hours): void
    {
        if ($hours < 0) {
            throw new \InvalidArgumentException('LeaveEntitlement.hoursGranted must not be negative.');
        }
    }

    /**
     * Regular entitlements have no per-record expiry: BUrlG mandates the
     * year itself as the deadline, with unused hours either lapsing or
     * being rolled into a separate Carryover entry by YearTransitionService.
     * Only Carryover entries carry an `expiresAt`.
     */
    private function assertTypeAllowsExpiry(LeaveEntitlementType $type): void
    {
        if (LeaveEntitlementType::Carryover !== $type) {
            throw new \InvalidArgumentException(\sprintf('LeaveEntitlement.expiresAt may only be set for Carryover entries; got %s.', $type->value));
        }
    }

    /**
     * BUrlG §7 Abs. 3 grants employees a 3-month grace period (until 31.03.
     * of the carryover year) to use untaken leave. The employer cannot
     * unilaterally shorten this floor — admin can only EXTEND (illness,
     * parental leave, missing employer notice per BAG case law). For a
     * year=N carryover the earliest valid expiresAt is therefore N-03-31.
     */
    private function assertExpiresAtRespectsBurlgFloor(\DateTimeImmutable $expiresAt, int $year): void
    {
        $burlgFloor = new \DateTimeImmutable(\sprintf('%d-03-31', $year));
        if ($expiresAt < $burlgFloor) {
            throw new \InvalidArgumentException(\sprintf('LeaveEntitlement.expiresAt must not be before %d-03-31 (BUrlG §7 Abs. 3 floor; got %s).', $year, $expiresAt->format('Y-m-d')));
        }
    }
}
