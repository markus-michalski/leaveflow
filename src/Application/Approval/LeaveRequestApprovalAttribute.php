<?php

declare(strict_types=1);

namespace App\Application\Approval;

/**
 * Security attributes for manager-side actions on a LeaveRequest.
 *
 * Lives in the Application layer so both the Presentation controllers and
 * the Infrastructure voter can depend on it — the Deptrac ruleset forbids
 * Presentation -> Infrastructure, so the shared constants must sit in a
 * commonly-allowed layer.
 */
enum LeaveRequestApprovalAttribute: string
{
    case Approve = 'LEAVE_REQUEST_APPROVE';
    case Reject = 'LEAVE_REQUEST_REJECT';
    case ConfirmCancel = 'LEAVE_REQUEST_CONFIRM_CANCEL';
    case DenyCancel = 'LEAVE_REQUEST_DENY_CANCEL';
}
