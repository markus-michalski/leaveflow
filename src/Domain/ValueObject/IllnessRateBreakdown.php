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
 * Aggregated illness-rate snapshot.
 *
 * illnessRatePercent = illnessHours / scheduledHours * 100, rounded to one
 * decimal. Scheduled hours are computed from each employee's WorkSchedule and
 * active range (joinedAt..leftAt) clipped to the requested date range.
 */
final readonly class IllnessRateBreakdown
{
    public function __construct(
        public float $totalIllnessHours,
        public float $totalScheduledHours,
        public float $illnessRatePercent,
    ) {
    }
}
