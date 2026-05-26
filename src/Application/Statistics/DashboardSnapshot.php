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
     * @param list<float>                    $monthlyDistribution 12 entries, Jan..Dec (0-indexed) for direct JSON serialization to Chart.js
     * @param list<DepartmentBreakdownEntry> $departmentBreakdown ordered by department name, "Ohne Abteilung" last
     * @param list<int>                      $availableYears      newest-first list for the year picker
     * @param list<ExpiringCarryoverEntry>   $expiringCarryovers  ordered ascending by daysUntilExpiry — the most urgent first
     * @param list<OverduePendingEntry>      $overduePending      ordered descending by daysWaiting — the worst offenders first
     * @param list<CurrentAbsenceEntry>      $currentAbsences     up to currentAbsencesLimit entries, ordered by employee name
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
        public array $expiringCarryovers,
        public array $overduePending,
        public int $expiryHorizonDays,
        public int $overdueThresholdDays,
        public array $currentAbsences,
        public int $currentAbsencesTotal,
        public int $currentAbsencesLimit,
    ) {
    }

    public function hasActions(): bool
    {
        return [] !== $this->expiringCarryovers || [] !== $this->overduePending;
    }

    public function hasMoreAbsencesThanShown(): bool
    {
        return $this->currentAbsencesTotal > \count($this->currentAbsences);
    }

    public function additionalAbsencesCount(): int
    {
        return max(0, $this->currentAbsencesTotal - \count($this->currentAbsences));
    }
}
