<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Repository\BlackoutPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admin-managed hard-block date range that prevents leave requests.
 *
 * Scope:
 * - Company-wide when department is null (e.g. company-wide Werksferien).
 * - Department-scoped when department is set (e.g. Engineering release-freeze).
 *
 * Both startDate and endDate are inclusive and stored at midnight (no time
 * component). A single-day blackout has start == end.
 *
 * Enforcement happens at the Application layer (BlackoutPeriodChecker hooked
 * into LeaveRequestService). Recurring blackouts (e.g. yearly Christmas) are
 * not modeled — admin re-creates them per year. Location scoping is deferred
 * to Phase 9 (deduplicates the HolidayOverride location story).
 */
#[ORM\Entity(repositoryClass: BlackoutPeriodRepository::class)]
#[ORM\Table(name: 'blackout_periods')]
#[ORM\Index(name: 'idx_blackout_company_range', columns: ['company_id', 'start_date', 'end_date'])]
class BlackoutPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'company_id', nullable: false)]
        private Company $company,
        #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $startDate,
        #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $endDate,
        #[ORM\Column(length: 255)]
        private string $reason,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'department_id', nullable: true, onDelete: 'CASCADE')]
        private ?Department $department = null,
    ) {
        $this->startDate = $startDate->setTime(0, 0);
        $this->endDate = $endDate->setTime(0, 0);

        if ($this->endDate < $this->startDate) {
            throw new \InvalidArgumentException('end date must be on or after start date');
        }

        $trimmed = trim($reason);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('reason must not be blank');
        }
        $this->reason = $trimmed;

        if (null !== $department && $department->getCompany() !== $company) {
            throw new \InvalidArgumentException('department must belong to the same company');
        }

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        string $reason,
        ?Department $department,
    ): void {
        $newStart = $startDate->setTime(0, 0);
        $newEnd = $endDate->setTime(0, 0);

        if ($newEnd < $newStart) {
            throw new \InvalidArgumentException('end date must be on or after start date');
        }

        $trimmed = trim($reason);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('reason must not be blank');
        }

        if (null !== $department && $department->getCompany() !== $this->company) {
            throw new \InvalidArgumentException('department must belong to the same company');
        }

        $this->startDate = $newStart;
        $this->endDate = $newEnd;
        $this->reason = $trimmed;
        $this->department = $department;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        $normalized = $date->setTime(0, 0);

        return $normalized >= $this->startDate && $normalized <= $this->endDate;
    }

    public function appliesTo(?Department $department): bool
    {
        if (null === $this->department) {
            return true;
        }

        return null !== $department && $this->department === $department;
    }
}
