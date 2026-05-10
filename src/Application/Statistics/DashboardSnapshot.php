<?php

declare(strict_types=1);

namespace App\Application\Statistics;

use App\Domain\ValueObject\IllnessRateBreakdown;
use App\Domain\ValueObject\UtilizationBreakdown;

/**
 * Read-model for the admin statistics dashboard.
 *
 * Built once per request by StatisticsService::buildDashboard. All values
 * are aggregated and free of PII so the snapshot can be passed straight to
 * Twig + the chart endpoints without further filtering.
 *
 * `rangeEnd` equals `year-12-31` for past years and `min(year-12-31, today)`
 * for the current year — this keeps the illness rate honest (no padding the
 * denominator with future scheduled hours).
 */
final readonly class DashboardSnapshot
{
    /**
     * @param array<int, float>              $monthlyDistribution map of 1..12 => hours, every month present
     * @param list<DepartmentBreakdownEntry> $departmentBreakdown ordered by department name, "Ohne Abteilung" last
     * @param list<int>                      $availableYears      newest-first list for the year picker
     */
    public function __construct(
        public int $year,
        public \DateTimeImmutable $rangeStart,
        public \DateTimeImmutable $rangeEnd,
        public UtilizationBreakdown $utilization,
        public IllnessRateBreakdown $illnessRate,
        public int $awaitingDecisionCount,
        public int $activeEmployeeCount,
        public float $averageRemainingHours,
        public array $monthlyDistribution,
        public array $departmentBreakdown,
        public int $anonymityThreshold,
        public array $availableYears,
    ) {
    }
}
