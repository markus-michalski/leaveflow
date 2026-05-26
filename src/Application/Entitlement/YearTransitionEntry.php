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

namespace App\Application\Entitlement;

use App\Domain\Entity\Employee;

/**
 * Outcome of processing one employee during year transition.
 *
 * `hoursCarriedOver` is 0 for skipped entries; for created entries it reflects
 * the amount that would be / was persisted as a Carryover entitlement.
 */
final readonly class YearTransitionEntry
{
    public function __construct(
        public Employee $employee,
        public float $hoursCarriedOver,
        public YearTransitionStatus $status,
    ) {
    }
}
