<?php

declare(strict_types=1);

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
