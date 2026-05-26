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

namespace App\Domain\ValueObject;

/**
 * A single holiday on a concrete calendar date.
 *
 * Immutable value object returned by the HolidayCalculator. The `nameKey`
 * is a translation key (e.g. "holiday.neujahr"); callers translate it for
 * display. `scope` distinguishes federation-wide holidays from regional
 * ones for UI coloring and reporting.
 */
final readonly class Holiday
{
    public function __construct(
        public \DateTimeImmutable $date,
        public string $nameKey,
        public HolidayScope $scope,
    ) {
        if ('' === trim($nameKey)) {
            throw new \InvalidArgumentException('Holiday.nameKey must not be blank.');
        }
    }

    public function isOn(\DateTimeImmutable $date): bool
    {
        return $this->date->format('Y-m-d') === $date->format('Y-m-d');
    }
}
