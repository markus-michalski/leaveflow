<?php

declare(strict_types=1);

namespace App\Domain\Calculator;

/**
 * Calculates pro-rata leave entitlements per BUrlG §5 Zwölftelregel.
 *
 * Entry rule:  joined on/before the 15th → that month counts.
 *              Joined on the 16th or later → month does not count.
 *
 * Exit rule (symmetric): left on/after the 16th → that month counts
 *              (employee worked the majority of it). Left on/before the 15th
 *              → month does not count.
 *
 * Results are rounded up to the nearest 0.5 h (employee-friendly;
 * §5 Abs. 2 BUrlG requires rounding up fractions ≥ ½ day, extended here to ½ h).
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

        $months = max(0, 12 - $this->firstCountedMonth($joinedAt) + 1);
        if (0 === $months) {
            return 0.0;
        }

        return $this->roundUpToHalf($annualHours * $months / 12);
    }

    /**
     * Returns true when the employee's effective months for `year` are fewer
     * than 12 — used by the admin UI to decide whether to show a pro-rata hint.
     *
     * Pass `leftAt` to also detect reduction from a same-year exit (e.g. an
     * employee who joined Jan 1 but exits in June still has a reduced period).
     */
    public function isReducedEntitlement(
        \DateTimeImmutable $joinedAt,
        int $year,
        ?\DateTimeImmutable $leftAt = null,
    ): bool {
        return $this->effectiveMonthsForPeriod($joinedAt, $leftAt, $year) < 12;
    }

    /**
     * Number of countable calendar months for `year`, accounting for both
     * the join date and an optional same-year exit date.
     *
     * When `joinedAt` is in a prior year, counting starts from January.
     * When `leftAt` falls in `year`, counting ends at the last counted month
     * per the symmetric exit rule above.
     */
    public function effectiveMonthsForPeriod(
        \DateTimeImmutable $joinedAt,
        ?\DateTimeImmutable $leftAt,
        int $year,
    ): int {
        $joinYear = (int) $joinedAt->format('Y');
        $firstCounted = $joinYear === $year ? $this->firstCountedMonth($joinedAt) : 1;

        $lastCounted = 12;
        if (null !== $leftAt && (int) $leftAt->format('Y') === $year) {
            $lastCounted = $this->lastCountedMonth($leftAt);
        }

        return max(0, $lastCounted - $firstCounted + 1);
    }

    private function firstCountedMonth(\DateTimeImmutable $joinedAt): int
    {
        $joinDay = (int) $joinedAt->format('j');
        $joinMonth = (int) $joinedAt->format('n');

        return $joinDay <= 15 ? $joinMonth : $joinMonth + 1;
    }

    private function lastCountedMonth(\DateTimeImmutable $leftAt): int
    {
        $exitDay = (int) $leftAt->format('j');
        $exitMonth = (int) $leftAt->format('n');

        // Symmetric to entry: exit on/before the 15th → month does not count
        // (worked the minority of it); exit on the 16th or later → it counts.
        return $exitDay <= 15 ? $exitMonth - 1 : $exitMonth;
    }

    private function roundUpToHalf(float $value): float
    {
        return ceil($value * 2) / 2;
    }
}
