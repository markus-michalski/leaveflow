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

namespace App\Application\Entitlement;

/**
 * Aggregated leave balance for a single employee at a reference date.
 *
 * Separates regular vs. carryover hours so the dashboard (Phase 5) can show
 * both and render expiry warnings where it matters. `nextExpiry` is the
 * earliest expiresAt among non-exhausted carryovers with remaining hours;
 * null when the employee has no pending carryover balance.
 */
final readonly class BalanceSnapshot
{
    public function __construct(
        public float $regularGranted,
        public float $regularUsed,
        public float $regularRemaining,
        public float $carryoverGranted,
        public float $carryoverUsed,
        public float $carryoverRemaining,
        public ?\DateTimeImmutable $nextExpiry,
    ) {
    }

    public function totalRemaining(): float
    {
        return $this->regularRemaining + $this->carryoverRemaining;
    }

    public function totalGranted(): float
    {
        return $this->regularGranted + $this->carryoverGranted;
    }

    public function totalUsed(): float
    {
        return $this->regularUsed + $this->carryoverUsed;
    }
}
