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
 * Single carryover row for the expiry-risk action list. Carries the
 * fully-resolved values the template needs so it doesn't have to walk
 * the entitlement object — keeps the read-model self-contained and the
 * Twig untyped-property-friendly.
 */
final readonly class ExpiringCarryoverEntry
{
    public function __construct(
        public int $entitlementId,
        public string $employeeName,
        public string $employeeNumber,
        public float $hoursRemaining,
        public \DateTimeImmutable $expiresAt,
        public int $daysUntilExpiry,
    ) {
    }
}
