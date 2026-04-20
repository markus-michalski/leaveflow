<?php

declare(strict_types=1);

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
        public float $regularRemaining,
        public float $carryoverRemaining,
        public ?\DateTimeImmutable $nextExpiry,
    ) {
    }

    public function totalRemaining(): float
    {
        return $this->regularRemaining + $this->carryoverRemaining;
    }
}
