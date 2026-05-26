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

namespace App\Domain\Enum;

/**
 * Why a day inside a LeaveRequest range does not consume leave hours.
 *
 * Priority when multiple apply: EmployeeInactive > Weekend > Holiday >
 * NonWorkingDay. The first match wins and wins stably — downstream reports
 * rely on this ordering for consistent labels.
 */
enum ExclusionReason: string
{
    case EmployeeInactive = 'employee_inactive';
    case Weekend = 'weekend';
    case Holiday = 'holiday';
    case NonWorkingDay = 'non_working_day';
}
