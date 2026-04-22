<?php

declare(strict_types=1);

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
