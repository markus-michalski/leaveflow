<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum LeaveDayStatus: string
{
    case Working = 'working';
    case HalfDay = 'half_day';
    case Excluded = 'excluded';
}
