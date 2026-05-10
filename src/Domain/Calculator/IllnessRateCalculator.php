<?php

declare(strict_types=1);

namespace App\Domain\Calculator;

use App\Domain\Entity\Employee;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\IllnessRateBreakdown;

/**
 * Computes the company-wide illness rate as illness hours over scheduled
 * hours, percent rounded to one decimal.
 *
 * Pure domain service. Scheduled hours are derived from each employee's
 * WorkSchedule walked day-by-day across the active intersection of
 * (rangeStart..rangeEnd) and (joinedAt..leftAt). Public holidays are NOT
 * subtracted: the German industry-standard for the Krankenquote takes the
 * contractual schedule as denominator, not the actual working days. This
 * keeps the metric stable across federal states and avoids coupling the
 * statistics layer to HolidayService.
 *
 * Illness hours come pre-aggregated as a map of employeeId => hours so
 * the calculator stays a pure function — the orchestrating service runs
 * the SQL query.
 */
final readonly class IllnessRateCalculator
{
    /**
     * @param list<Employee>      $employees
     * @param array<int, float>   $illnessHoursByEmployeeId
     */
    public function calculate(
        array $employees,
        array $illnessHoursByEmployeeId,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): IllnessRateBreakdown {
        $rangeStart = $rangeStart->setTime(0, 0);
        $rangeEnd = $rangeEnd->setTime(0, 0);

        $totalScheduled = 0.0;
        $totalIllness = 0.0;

        foreach ($employees as $employee) {
            $totalScheduled += $this->scheduledHoursFor($employee, $rangeStart, $rangeEnd);

            $id = $employee->getId();
            if (null !== $id && isset($illnessHoursByEmployeeId[$id])) {
                $totalIllness += $illnessHoursByEmployeeId[$id];
            }
        }

        $percent = $totalScheduled > 0.0
            ? round(($totalIllness / $totalScheduled) * 100.0, 1)
            : 0.0;

        return new IllnessRateBreakdown($totalIllness, $totalScheduled, $percent);
    }

    private function scheduledHoursFor(
        Employee $employee,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): float {
        $effectiveStart = max($rangeStart, $employee->getJoinedAt()->setTime(0, 0));
        $leftAt = $employee->getLeftAt()?->setTime(0, 0);
        $effectiveEnd = null !== $leftAt && $leftAt < $rangeEnd ? $leftAt : $rangeEnd;

        if ($effectiveStart > $effectiveEnd) {
            return 0.0;
        }

        $schedule = $employee->getWorkSchedule();
        $hours = 0.0;
        $cursor = $effectiveStart;
        while ($cursor <= $effectiveEnd) {
            $weekday = Weekday::fromDateTime($cursor);
            if ($schedule->isWorkingDay($weekday)) {
                $hours += $schedule->hoursForDay($weekday);
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $hours;
    }
}
