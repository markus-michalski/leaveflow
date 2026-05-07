<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\ValueObject\LeaveBreakdown;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An employee's request for time off.
 *
 * Phase 5 scope: request is created in Pending state and populated with a
 * per-day breakdown via applyBreakdown() after the LeaveCalculator has run.
 * Workflow transitions (approve/reject/cancel) land in Phase 6 via Symfony
 * Workflow.
 *
 * Invariants:
 * - endDate >= startDate
 * - absenceType.company === employee.company (tenant integrity)
 * - startDate, endDate are normalized to midnight for stable comparisons
 *
 * The `totalHours` snapshot and `days` collection are populated by
 * applyBreakdown so the approval workflow and dashboard queries don't have to
 * recompute on every read; the snapshot is also audit-relevant (preserves the
 * calculation at time of request even if the employee's WorkSchedule later
 * changes).
 */
#[ORM\Entity(repositoryClass: LeaveRequestRepository::class)]
#[ORM\Table(name: 'leave_requests')]
class LeaveRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'status', length: 20, enumType: LeaveRequestStatus::class)]
    private LeaveRequestStatus $status;

    #[ORM\Column(name: 'total_hours', type: Types::FLOAT)]
    private float $totalHours = 0.0;

    /**
     * Idempotency timestamp for the EscalationTriggered notification.
     * Set the first time the scheduler decides this Pending request has
     * exceeded its company's approvalEscalationDays threshold; null
     * thereafter prevents repeated escalations on the same request.
     */
    #[ORM\Column(name: 'escalation_notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $escalationNotifiedAt = null;

    /**
     * @var Collection<int, LeaveRequestDay>
     */
    #[ORM\OneToMany(
        mappedBy: 'leaveRequest',
        targetEntity: LeaveRequestDay::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['date' => 'ASC'])]
    private Collection $days;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'employee_id', nullable: false)]
        private Employee $employee,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'absence_type_id', nullable: false)]
        private AbsenceType $absenceType,
        #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $startDate,
        #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $endDate,
        #[ORM\Column(name: 'day_type', length: 20, enumType: LeaveDayType::class)]
        private LeaveDayType $dayType,
        #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $requestedAt,
    ) {
        $this->assertSameCompany($employee, $absenceType);

        $this->startDate = $startDate->setTime(0, 0, 0, 0);
        $this->endDate = $endDate->setTime(0, 0, 0, 0);

        $this->assertRangeValid($this->startDate, $this->endDate);

        // Absence types without an approval gate (Krankheit is the default
        // example, thanks to eAU) are informational entries — they get
        // Recorded instead of Pending so neither the employee nor the manager
        // sees a fake "awaiting approval" badge on something that isn't up
        // for review.
        $this->status = $absenceType->requiresApproval()
            ? LeaveRequestStatus::Pending
            : LeaveRequestStatus::Recorded;
        $this->days = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): Employee
    {
        return $this->employee;
    }

    public function getAbsenceType(): AbsenceType
    {
        return $this->absenceType;
    }

    /**
     * Reclassifies the absence type of this request. Used by admin
     * type-change flow (Phase 9). Does NOT touch entitlement bookings —
     * the orchestrating service must release the old hours and consume
     * the new ones, otherwise balances drift.
     */
    public function changeAbsenceType(AbsenceType $newType): void
    {
        $this->assertSameCompany($this->employee, $newType);
        $this->absenceType = $newType;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getDayType(): LeaveDayType
    {
        return $this->dayType;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getStatus(): LeaveRequestStatus
    {
        return $this->status;
    }

    /**
     * Workflow marking setter. Invoked by Symfony Workflow's MethodMarkingStore
     * when a transition is applied. Business code must not call this directly —
     * go through {@see \App\Application\Approval\ApprovalWorkflow} so audit trail
     * and notifications fire.
     */
    public function setStatus(LeaveRequestStatus $status): void
    {
        $this->status = $status;
    }

    public function getTotalHours(): float
    {
        return $this->totalHours;
    }

    public function getEscalationNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->escalationNotifiedAt;
    }

    public function markEscalationNotified(\DateTimeImmutable $now): void
    {
        $this->escalationNotifiedAt = $now;
    }

    /**
     * @return Collection<int, LeaveRequestDay>
     */
    public function getDays(): Collection
    {
        return $this->days;
    }

    /**
     * Populate per-day breakdown + total hours from a LeaveCalculator result.
     *
     * Idempotent: replaces any previously stored days (orphanRemoval handles
     * DB cleanup). The breakdown must cover exactly the request's date range
     * in chronological order — the factory/orchestrator is responsible for
     * calling the calculator with the same dates as the request.
     */
    public function applyBreakdown(LeaveBreakdown $breakdown): void
    {
        $this->assertBreakdownCoversRange($breakdown);

        $this->days->clear();
        foreach ($breakdown->days as $day) {
            $this->days->add(new LeaveRequestDay(
                leaveRequest: $this,
                date: $day->date,
                hours: $day->hours,
                status: $day->status,
                reason: $day->reason,
            ));
        }

        $this->totalHours = $breakdown->totalHours();
    }

    private function assertSameCompany(Employee $employee, AbsenceType $absenceType): void
    {
        if ($employee->getCompany() !== $absenceType->getCompany()) {
            throw new \InvalidArgumentException('LeaveRequest.absenceType must belong to the same company as the employee.');
        }
    }

    private function assertRangeValid(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        if ($end < $start) {
            throw new \InvalidArgumentException('LeaveRequest.endDate must not precede startDate.');
        }
    }

    private function assertBreakdownCoversRange(LeaveBreakdown $breakdown): void
    {
        $expectedDates = [];
        for ($cursor = $this->startDate; $cursor <= $this->endDate; $cursor = $cursor->modify('+1 day')) {
            $expectedDates[] = $cursor->format('Y-m-d');
        }

        if (\count($breakdown->days) !== \count($expectedDates)) {
            throw new \InvalidArgumentException(\sprintf('LeaveRequest: breakdown covers %d day(s), expected %d for %s..%s.', \count($breakdown->days), \count($expectedDates), $this->startDate->format('Y-m-d'), $this->endDate->format('Y-m-d')));
        }

        foreach ($breakdown->days as $index => $day) {
            if ($day->date->format('Y-m-d') !== $expectedDates[$index]) {
                throw new \InvalidArgumentException(\sprintf('LeaveRequest: breakdown day %d is %s, expected %s.', $index, $day->date->format('Y-m-d'), $expectedDates[$index]));
            }
        }
    }
}
