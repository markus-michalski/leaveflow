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
    /**
     * Read-only access to a request from the manager surface — drives
     * /manager/approvals/{id} for already-decided requests so the
     * department lead/deputy can review their own history (issue #17).
     * Same access scope as the action attributes (lead/deputy, no self).
     */
    case View = 'LEAVE_REQUEST_VIEW_FROM_MANAGER';
}
