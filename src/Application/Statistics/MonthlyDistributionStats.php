<?php

declare(strict_types=1);

namespace App\Application\Statistics;

/**
 * Aggregated facts about a 12-month leave-distribution. Used by the
 * PDF export to render a textual chart-replacement (the dompdf pipeline
 * doesn't run Chart.js).
 *
 * `peakMonthIndex` is 0-indexed (0 = January). Null when the
 * distribution is entirely empty so callers can render a fall-back.
 */
final readonly class MonthlyDistributionStats
{
    /**
     * @param list<float> $quarterTotals Q1..Q4 sums (4 entries)
     */
    public function __construct(
        public float $totalHours,
        public ?int $peakMonthIndex,
        public float $peakMonthHours,
        public int $emptyMonthCount,
        public array $quarterTotals,
        public ?int $peakQuarterIndex,
        public float $peakQuarterHours,
    ) {
    }
}
