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

namespace App\Application\Leave;

/**
 * Thrown when an employee submits a deducting leave request for a year without
 * any entitlement entry at all.
 *
 * Distinct from InsufficientLeaveBalanceException: that one signals "you have
 * an entitlement but not enough hours left"; this one signals "the year hasn't
 * been opened for you yet — the admin must create an entitlement first".
 *
 * Non-deducting absence types (Krankheit etc.) skip this check.
 */
final class NoEntitlementForYearException extends \DomainException
{
    public function __construct(
        public readonly int $year,
    ) {
        parent::__construct(\sprintf(
            'No leave entitlement exists for %d; admin must create one before requests for this year are possible.',
            $year,
        ));
    }
}
