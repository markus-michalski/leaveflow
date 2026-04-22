<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Enum\LeaveDayStatus;

/**
 * Result of LeaveCalculator::calculate — a per-day breakdown of a requested
 * range plus the aggregate hours that would be consumed.
 *
 * The `days` array preserves chronological order and contains an entry for
 * every calendar day in the requested range, including excluded ones, so the
 * Turbo-preview UI can render a full day-by-day table.
 */
final readonly class LeaveBreakdown
{
    /**
     * @param list<LeaveDay> $days
     */
    public function __construct(
        public array $days,
    ) {
    }

    public function totalHours(): float
    {
        $sum = 0.0;
        foreach ($this->days as $day) {
            $sum += $day->hours;
        }

        return $sum;
    }

    /**
     * @return list<LeaveDay>
     */
    public function workingDays(): array
    {
        return array_values(array_filter(
            $this->days,
            static fn (LeaveDay $d): bool => LeaveDayStatus::Excluded !== $d->status,
        ));
    }

    /**
     * @return list<LeaveDay>
     */
    public function excludedDays(): array
    {
        return array_values(array_filter(
            $this->days,
            static fn (LeaveDay $d): bool => LeaveDayStatus::Excluded === $d->status,
        ));
    }
}
