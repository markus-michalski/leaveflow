<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

enum HolidayScope: string
{
    case National = 'national';
    case Regional = 'regional';
    case Company = 'company';
}
