<?php

declare(strict_types=1);

namespace App\Domain\Calculator;

/**
 * Calculates pro-rata leave entitlements per BUrlG §5 Zwölftelregel.
 *
 * Rule: a calendar month counts as a full month of service when the employee
 * joined on or before the 15th of that month. Joined on the 16th or later →
 * that month does not count. The result is rounded up to the nearest 0.5h.
 */
final class ProRataEntitlementCalculator
{
    /**
     * Returns the pro-rata entitlement for an employee who joined mid-year.
     *
     * When `joinedAt` is in a different year than `year`, the employee was
     * already employed for the full year → returns `annualHours` unchanged.
     */
    public function calculateForEntry(
        \DateTimeImmutable $joinedAt,
        int $year,
        float $annualHours,
    ): float {
        if (0.0 === $annualHours) {
            return 0.0;
        }

        $joinYear = (int) $joinedAt->format('Y');
        if ($joinYear !== $year) {
            return $annualHours;
        }

        $effectiveMonths = $this->effectiveMonths($joinedAt);
        if (0 === $effectiveMonths) {
            return 0.0;
        }

        $raw = $annualHours * $effectiveMonths / 12;

        return $this->roundUpToHalf($raw);
    }

    /**
     * Returns true when the employee joined during `year` and will receive
     * fewer than the full annual entitlement — used by the admin UI to show
     * a pro-rata hint on the entitlement creation form.
     */
    public function isReducedEntitlement(\DateTimeImmutable $joinedAt, int $year): bool
    {
        $joinYear = (int) $joinedAt->format('Y');
        if ($joinYear !== $year) {
            return false;
        }

        return $this->effectiveMonths($joinedAt) < 12;
    }

    /**
     * Number of calendar months that count toward the entitlement.
     *
     * Join on/before the 15th → join month counts (start from join month).
     * Join on the 16th or later → join month does not count (start from next month).
     */
    private function effectiveMonths(\DateTimeImmutable $joinedAt): int
    {
        $joinMonth = (int) $joinedAt->format('n');
        $joinDay = (int) $joinedAt->format('j');

        $firstCountedMonth = $joinDay <= 15 ? $joinMonth : $joinMonth + 1;

        // Months from firstCountedMonth through December (month 12)
        $months = 12 - $firstCountedMonth + 1;

        return max(0, $months);
    }

    private function roundUpToHalf(float $value): float
    {
        // ceil to nearest 0.5: multiply by 2, ceil, divide by 2
        return ceil($value * 2) / 2;
    }
}
