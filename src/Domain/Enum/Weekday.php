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
 * ISO 8601 weekday numbering: Monday = 1 ... Sunday = 7.
 *
 * Matches the output of \DateTimeInterface::format('N').
 */
enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public static function fromDateTime(\DateTimeInterface $date): self
    {
        return self::from((int) $date->format('N'));
    }

    public function label(): string
    {
        return 'weekday.'.strtolower($this->name);
    }
}
