<?php

declare(strict_types=1);

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
