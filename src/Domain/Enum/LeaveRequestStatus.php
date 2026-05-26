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
 * Lifecycle states for a LeaveRequest.
 *
 * Phase 5 creates either Pending or Recorded depending on the absence type's
 * requiresApproval flag. Transitions between the Pending/Approved/Rejected
 * triple are implemented in Phase 6 via Symfony Workflow; the cases are
 * declared now so the Doctrine column type is stable from the start.
 *
 * - Pending           — waiting for manager decision (requiresApproval = true):
 *                       Urlaub, Resturlaub, Sonderurlaub §616, Überstundenabbau,
 *                       Fortbildung
 * - Recorded          — informational entry, no approval gate. Krankheit is
 *                       the only default type in this slot (eAU since 2023
 *                       makes manager approval moot).
 * - Approved/Rejected — Phase 6, after manager decision
 * - Cancelled         — withdrawn (by employee or manager)
 * - CancelRequested   — employee asks for cancellation of an approved request
 */
enum LeaveRequestStatus: string
{
    case Pending = 'pending';
    case Recorded = 'recorded';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case CancelRequested = 'cancel_requested';
}
