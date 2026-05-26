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

namespace App\Domain\ValueObject;

/**
 * Aggregated leave-utilization snapshot for a set of entitlements.
 *
 * Hours are summed across employees/types and exclude carryover entries that
 * are already expired at the reference date. utilizationPercent is rounded to
 * one decimal place for stable display in the admin dashboard.
 */
final readonly class UtilizationBreakdown
{
    public function __construct(
        public float $totalGrantedHours,
        public float $totalUsedHours,
        public float $totalRemainingHours,
        public float $utilizationPercent,
    ) {
    }
}
