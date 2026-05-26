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
 * Thrown when a half-day day-type is combined with a multi-day range.
 *
 * Phase 5 enforces that HalfDayAm and HalfDayPm are only valid for single-day
 * requests (start == end). Mix-scenarios like "Mon half + Wed full + Fri half"
 * must be split into separate requests so the approval audit-trail stays
 * clear.
 */
final class MultiDayHalfDayException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Half-day day-type is only valid for single-day leave requests.');
    }
}
