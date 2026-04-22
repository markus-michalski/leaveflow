<?php

declare(strict_types=1);

namespace App\Domain\Calculator;

use App\Domain\Entity\Employee;
use App\Domain\Enum\ExclusionReason;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\Holiday;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;

/**
 * Pure, stateless calculator for a leave request's hour breakdown.
 *
 * Iterates the requested date range and classifies every day against the
 * employee's work schedule, the supplied holiday list, and the employee's
 * active window. No DB access — callers must pass in the relevant holidays
 * (typically resolved upstream via HolidayService for the employee's
 * work-location federal state and the relevant year(s)).
 */
final class LeaveCalculator
{
    /**
     * @param list<Holiday> $holidays holidays relevant to the employee's
     *                                work-location federal state for every
     *                                year the range spans
     */
    public function calculate(
        Employee $employee,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        LeaveDayType $dayType,
        array $holidays,
    ): LeaveBreakdown {
        $start = $this->normalizeToDay($startDate);
        $end = $this->normalizeToDay($endDate);

        if ($end < $start) {
            throw new \InvalidArgumentException('LeaveCalculator: endDate must not precede startDate.');
        }
        if ($dayType->isHalfDay() && $start->getTimestamp() !== $end->getTimestamp()) {
            throw new \InvalidArgumentException('LeaveCalculator: half-day day-type is only valid for single-day ranges.');
        }

        $holidayIndex = $this->indexHolidaysByDate($holidays);
        $schedule = $employee->getWorkSchedule();

        $days = [];
        for ($current = $start; $current <= $end; $current = $current->modify('+1 day')) {
            $days[] = $this->classify($employee, $schedule, $current, $dayType, $holidayIndex);
        }

        return new LeaveBreakdown($days);
    }

    /**
     * @param array<string, true> $holidayIndex
     */
    private function classify(
        Employee $employee,
        \App\Domain\ValueObject\WorkSchedule $schedule,
        \DateTimeImmutable $current,
        LeaveDayType $dayType,
        array $holidayIndex,
    ): LeaveDay {
        $reason = $this->exclusionReason($employee, $schedule, $current, $holidayIndex);
        if (null !== $reason) {
            return new LeaveDay($current, 0.0, LeaveDayStatus::Excluded, $reason);
        }

        $weekday = Weekday::fromDateTime($current);
        $fullHours = $schedule->hoursForDay($weekday);

        // Multi-day + half-day is guarded by calculate() above, so reaching
        // this branch means the range is a single day — the half-day flag
        // applies to that one day regardless of Am vs. Pm.
        if ($dayType->isHalfDay()) {
            return new LeaveDay($current, $fullHours / 2.0, LeaveDayStatus::HalfDay);
        }

        return new LeaveDay($current, $fullHours, LeaveDayStatus::Working);
    }

    /**
     * @param array<string, true> $holidayIndex
     */
    private function exclusionReason(
        Employee $employee,
        \App\Domain\ValueObject\WorkSchedule $schedule,
        \DateTimeImmutable $current,
        array $holidayIndex,
    ): ?ExclusionReason {
        if (!$employee->isActiveOn($current)) {
            return ExclusionReason::EmployeeInactive;
        }

        $weekday = Weekday::fromDateTime($current);
        if (Weekday::Saturday === $weekday || Weekday::Sunday === $weekday) {
            return ExclusionReason::Weekend;
        }

        if (isset($holidayIndex[$current->format('Y-m-d')])) {
            return ExclusionReason::Holiday;
        }

        if (!$schedule->isWorkingDay($weekday)) {
            return ExclusionReason::NonWorkingDay;
        }

        return null;
    }

    /**
     * @param list<Holiday> $holidays
     *
     * @return array<string, true>
     */
    private function indexHolidaysByDate(array $holidays): array
    {
        $index = [];
        foreach ($holidays as $holiday) {
            $index[$holiday->date->format('Y-m-d')] = true;
        }

        return $index;
    }

    private function normalizeToDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTime(0, 0, 0, 0);
    }
}
