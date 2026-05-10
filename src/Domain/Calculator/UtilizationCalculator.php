<?php

declare(strict_types=1);

namespace App\Domain\Calculator;

use App\Domain\Entity\LeaveEntitlement;
use App\Domain\ValueObject\UtilizationBreakdown;

/**
 * Aggregates a list of LeaveEntitlement entries into a UtilizationBreakdown.
 *
 * Pure domain service — accepts already-loaded entitlements so the caller
 * controls scoping (company-wide, per-department, per-employee). Carryover
 * entries that are already expired at the reference date are excluded from
 * both granted and remaining totals; their previously-consumed hours are
 * also dropped because counting them would inflate utilization against a
 * smaller granted base.
 */
final readonly class UtilizationCalculator
{
    /**
     * @param list<LeaveEntitlement> $entitlements
     */
    public function calculate(array $entitlements, \DateTimeImmutable $asOf): UtilizationBreakdown
    {
        $granted = 0.0;
        $used = 0.0;

        foreach ($entitlements as $entitlement) {
            if ($entitlement->isExpiredOn($asOf)) {
                continue;
            }
            $granted += $entitlement->getHoursGranted();
            $used += $entitlement->getHoursUsed();
        }

        $remaining = $granted - $used;
        $percent = $granted > 0.0
            ? round(($used / $granted) * 100.0, 1)
            : 0.0;

        return new UtilizationBreakdown($granted, $used, $remaining, $percent);
    }
}
