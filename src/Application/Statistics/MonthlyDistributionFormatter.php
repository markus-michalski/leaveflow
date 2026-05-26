<?php

declare(strict_types=1);

/*
 * This file is part of LeaveFlow.
 *
 * (c) Markus Michalski <ich@markus-michalski.net>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace App\Application\Statistics;

/**
 * Pure aggregator that turns the dashboard's 12-month distribution into
 * a set of summary numbers — peak month, quarter totals, empty months.
 *
 * Localization happens in the template; this layer stays free of
 * Translator dependencies so it remains trivially testable.
 */
final readonly class MonthlyDistributionFormatter
{
    /**
     * @param list<float> $monthlyDistribution 12 entries, 0-indexed Jan..Dec
     */
    public function summarize(array $monthlyDistribution): MonthlyDistributionStats
    {
        if (12 !== \count($monthlyDistribution)) {
            throw new \InvalidArgumentException(\sprintf('monthlyDistribution must have exactly 12 entries, got %d.', \count($monthlyDistribution)));
        }

        $total = 0.0;
        $peakMonthIndex = null;
        $peakMonthHours = 0.0;
        $emptyMonthCount = 0;

        foreach ($monthlyDistribution as $index => $hours) {
            $total += $hours;
            if (0.0 === $hours) {
                ++$emptyMonthCount;
            }
            // First-occurrence wins on ties — `>`, not `>=`.
            if ($hours > $peakMonthHours) {
                $peakMonthHours = $hours;
                $peakMonthIndex = $index;
            }
        }

        $quarterTotals = [];
        for ($q = 0; $q < 4; ++$q) {
            $base = $q * 3;
            $quarterTotals[] = $monthlyDistribution[$base]
                + $monthlyDistribution[$base + 1]
                + $monthlyDistribution[$base + 2];
        }

        $peakQuarterIndex = null;
        $peakQuarterHours = 0.0;
        foreach ($quarterTotals as $index => $sum) {
            if ($sum > $peakQuarterHours) {
                $peakQuarterHours = $sum;
                $peakQuarterIndex = $index;
            }
        }

        return new MonthlyDistributionStats(
            totalHours: $total,
            peakMonthIndex: $peakMonthIndex,
            peakMonthHours: $peakMonthHours,
            emptyMonthCount: $emptyMonthCount,
            quarterTotals: $quarterTotals,
            peakQuarterIndex: $peakQuarterIndex,
            peakQuarterHours: $peakQuarterHours,
        );
    }
}
