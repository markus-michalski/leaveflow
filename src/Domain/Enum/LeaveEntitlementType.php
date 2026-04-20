<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Classifies an annual leave entitlement.
 *
 * Regular: entitlement granted for the current year (main vacation balance).
 * Carryover: remainder from a previous year, legally constrained by BUrlG
 *   §7 Abs. 3 (default expiry 31.03. following year) unless admin extends it.
 */
enum LeaveEntitlementType: string
{
    case Regular = 'regular';
    case Carryover = 'carryover';

    public function label(): string
    {
        return 'leave_entitlement.type.'.$this->value;
    }
}
