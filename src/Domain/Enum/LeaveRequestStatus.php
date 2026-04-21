<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Lifecycle states for a LeaveRequest.
 *
 * Phase 5 only creates Pending requests. Transitions to the other states and
 * their guards are implemented in Phase 6 via Symfony Workflow; the cases are
 * already declared so the Doctrine column type is stable from the start.
 */
enum LeaveRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case CancelRequested = 'cancel_requested';
}
