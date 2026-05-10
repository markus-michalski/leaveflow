<?php

declare(strict_types=1);

namespace App\Application\Leave;

use App\Application\Approval\ApproverResolverInterface;
use App\Application\Calendar\BlackoutPeriodChecker;
use App\Application\Entitlement\EntitlementBalanceReader;
use App\Application\Holiday\HolidayService;
use App\Application\Notification\NotificationDispatcherInterface;
use App\Domain\Calculator\LeaveCalculator;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\NotificationType;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\Repository\LeaveRequestDayRepository;
use App\Domain\ValueObject\Holiday;
use App\Domain\ValueObject\LeaveBreakdown;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Application-layer orchestrator for the LeaveRequest write path.
 *
 * Wraps the pure LeaveCalculator with the two stateful lookups it needs
 * (holidays resolved via HolidayService for the employee's work-location
 * federal state, the wall clock for requestedAt) and takes care of persisting
 * the resulting aggregate.
 *
 * Two public entry points:
 * - preview(): returns a LeaveBreakdown without creating anything (Turbo-Frame
 *   live preview on the request form)
 * - create(): creates the pending LeaveRequest with its per-day snapshot and
 *   flushes it
 */
final readonly class LeaveRequestService
{
    /**
     * Tolerance for float comparisons in the per-year balance check.
     */
    private const float BALANCE_EPSILON = 0.0001;

    public function __construct(
        private HolidayService $holidayService,
        private LeaveCalculator $calculator,
        private EntitlementBalanceReader $balanceReader,
        private LeaveEntitlementRepository $entitlementRepository,
        private LeaveRequestDayRepository $dayRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private BlackoutPeriodChecker $blackoutChecker,
        private NotificationDispatcherInterface $notificationDispatcher,
        private ApproverResolverInterface $approverResolver,
    ) {
    }

    public function preview(
        Employee $employee,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        LeaveDayType $dayType,
    ): LeaveBreakdown {
        $this->assertNotBackdated($startDate);
        $this->assertHalfDayOnlyOnSingleDay($startDate, $endDate, $dayType);
        $this->blackoutChecker->ensureRangeIsClear($employee, $startDate, $endDate);
        $holidays = $this->resolveHolidays($employee, $startDate, $endDate);

        return $this->calculator->calculate($employee, $startDate, $endDate, $dayType, $holidays);
    }

    public function create(
        Employee $employee,
        AbsenceType $absenceType,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        LeaveDayType $dayType,
    ): LeaveRequest {
        $breakdown = $this->preview($employee, $startDate, $endDate, $dayType);

        if ($absenceType->deductsFromLeave()) {
            $this->assertBalanceCoversBreakdown($employee, $breakdown);
        }

        $request = new LeaveRequest(
            employee: $employee,
            absenceType: $absenceType,
            startDate: $startDate,
            endDate: $endDate,
            dayType: $dayType,
            requestedAt: $this->clock->now(),
        );
        $request->applyBreakdown($breakdown);

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->notifyApprovalRequested($request);

        return $request;
    }

    /**
     * Fires the ApprovalRequested in-app + email notification when a Pending
     * request needs an approver. No-op for Recorded requests (informational
     * absences like Krankheit don't have an approver).
     *
     * Silently skipped if there's no resolvable approver (department without
     * lead/deputy, or approver without User account) — Phase 8 doesn't
     * escalate to Admin here; that's the scheduler's job (Slice 6).
     */
    private function notifyApprovalRequested(LeaveRequest $request): void
    {
        if (LeaveRequestStatus::Pending !== $request->getStatus()) {
            return;
        }

        $approver = $this->approverResolver->resolve($request);
        if (null === $approver) {
            return;
        }

        $approverUser = $approver->getUser();
        if (null === $approverUser) {
            return;
        }

        $this->notificationDispatcher->dispatch(
            type: NotificationType::ApprovalRequested,
            recipient: $approverUser,
            payload: [
                'employeeName' => $request->getEmployee()->getFullName(),
                'absenceTypeName' => $request->getAbsenceType()->getName(),
                'startDate' => $request->getStartDate()->format('d.m.Y'),
                'endDate' => $request->getEndDate()->format('d.m.Y'),
            ],
            relatedEntityType: LeaveRequest::class,
            relatedEntityId: $request->getId(),
        );
        $this->entityManager->flush();
    }

    /**
     * Per-year balance check before creating a deducting leave request.
     *
     * Pending requests already held by the employee are counted as reserved,
     * so three overlapping-year requests can't slip through by each one seeing
     * the pre-approval balance. BUrlG §7 Abs. 1 sets the baseline expectation
     * that leave requests are granted unless operational reasons block them —
     * we want the block to happen visibly at request time, not silently during
     * approval weeks later.
     */
    private function assertBalanceCoversBreakdown(Employee $employee, LeaveBreakdown $breakdown): void
    {
        $hoursByYear = $this->hoursByYear($breakdown);
        if ([] === $hoursByYear) {
            return;
        }

        $pendingByYear = $this->dayRepository->sumPendingHoursByYear($employee);
        $asOf = $this->clock->now();

        foreach ($hoursByYear as $year => $requestedHours) {
            // Existence of an entitlement for the year is the "this year is
            // open for you" signal — distinct from "you have an entry but
            // not enough hours left". Giving the two cases separate errors
            // lets the UI point the user to the admin for the former and to
            // the request itself for the latter.
            if ([] === $this->entitlementRepository->findByEmployeeAndYear($employee, $year)) {
                throw new NoEntitlementForYearException($year);
            }

            $snapshot = $this->balanceReader->forEmployee($employee, $year, $asOf);
            $available = $snapshot->totalRemaining() - ($pendingByYear[$year] ?? 0.0);

            if (($available + self::BALANCE_EPSILON) < $requestedHours) {
                throw new InsufficientLeaveBalanceException($year, $requestedHours, max(0.0, $available));
            }
        }
    }

    private function assertNotBackdated(\DateTimeImmutable $startDate): void
    {
        $today = $this->clock->now()->setTime(0, 0, 0, 0);
        $start = $startDate->setTime(0, 0, 0, 0);

        if ($start < $today) {
            throw new BackdatedLeaveRequestException($start);
        }
    }

    private function assertHalfDayOnlyOnSingleDay(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        LeaveDayType $dayType,
    ): void {
        if (!$dayType->isHalfDay()) {
            return;
        }

        $start = $startDate->setTime(0, 0, 0, 0);
        $end = $endDate->setTime(0, 0, 0, 0);

        if ($start->getTimestamp() !== $end->getTimestamp()) {
            throw new MultiDayHalfDayException();
        }
    }

    /**
     * @return array<int, float>
     */
    private function hoursByYear(LeaveBreakdown $breakdown): array
    {
        $hoursByYear = [];
        foreach ($breakdown->days as $day) {
            if (LeaveDayStatus::Excluded === $day->status) {
                continue;
            }
            $year = (int) $day->date->format('Y');
            $hoursByYear[$year] = ($hoursByYear[$year] ?? 0.0) + $day->hours;
        }

        return $hoursByYear;
    }

    /**
     * @return list<Holiday>
     */
    private function resolveHolidays(
        Employee $employee,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        $holidays = [];
        $startYear = (int) $startDate->format('Y');
        $endYear = (int) $endDate->format('Y');

        for ($year = $startYear; $year <= $endYear; ++$year) {
            // Employee-scoped path picks up location-specific overrides
            // (Phase 9 #47) — same office, same state, but two locations
            // can resolve to different holiday lists.
            foreach ($this->holidayService->getHolidaysForEmployee($employee, $year) as $holiday) {
                $holidays[] = $holiday;
            }
        }

        return $holidays;
    }
}
