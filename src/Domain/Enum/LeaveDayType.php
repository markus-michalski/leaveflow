<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Day-type granularity for a LeaveRequest.
 *
 * For multi-day requests the half-day flag applies only to the boundary day it
 * names: HalfDayAm => start day is half, HalfDayPm => end day is half, all
 * intermediate days stay full. Two half-day boundaries in one request are not
 * supported — file two requests.
 *
 * For single-day requests (start == end) both HalfDayAm and HalfDayPm mean the
 * same thing: the one day is half.
 */
enum LeaveDayType: string
{
    case FullDay = 'full_day';
    case HalfDayAm = 'half_day_am';
    case HalfDayPm = 'half_day_pm';

    public function isHalfDay(): bool
    {
        return self::HalfDayAm === $this || self::HalfDayPm === $this;
    }
}
