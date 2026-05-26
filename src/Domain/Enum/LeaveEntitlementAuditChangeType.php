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
 * Discriminator for {@see \App\Domain\Entity\LeaveEntitlementAuditEntry}.
 *
 * Each case maps to one mutator on {@see \App\Domain\Entity\LeaveEntitlement}
 * — the Audit-Entry's from/to fields differ by case, hence the
 * discriminator instead of a single union shape.
 *
 * - HoursGrantedAdjusted   — admin updated `hoursGranted` (typo fix,
 *                            adding overtime conversion, etc.)
 * - ExpiresAtAdjusted      — admin extended/cleared a Carryover deadline
 *                            (BAG case law on illness or parental leave)
 */
enum LeaveEntitlementAuditChangeType: string
{
    case HoursGrantedAdjusted = 'hours_granted_adjusted';
    case ExpiresAtAdjusted = 'expires_at_adjusted';
}
