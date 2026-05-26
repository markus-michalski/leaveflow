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

enum HolidayOverrideType: string
{
    case Added = 'added';
    case Removed = 'removed';

    public function label(): string
    {
        return 'holiday_override.type.'.$this->value;
    }
}
