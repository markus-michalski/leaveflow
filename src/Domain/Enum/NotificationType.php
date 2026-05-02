<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Notification kinds delivered by the Phase 8 notification system.
 *
 * Each case identifies one event source. Two channels exist:
 * - In-app: persisted as Notification rows, surfaced via the bell turbo-frame.
 *           Always on, not opt-out-able.
 * - Email:  dispatched via Symfony Mailer/Messenger when the user has not
 *           opted out via NotificationPreference.
 *
 * Cases are wired to triggers as follows:
 * - ApprovalRequested        — workflow.completed (no transition; pending request created)
 *                              recipient = resolved approver (dept lead/deputy/admin)
 * - ApprovalDecided          — workflow.completed.approve | .reject
 *                              recipient = request owner (employee)
 * - CancelRequested          — workflow.completed.request_cancel
 *                              recipient = resolved approver
 * - CancelDecided            — workflow.completed.confirm_cancel | .deny_cancel
 *                              recipient = request owner
 * - EscalationTriggered      — Scheduler: pending request older than threshold
 *                              recipient = next-in-chain (deputy or admin)
 * - EntitlementExpiringSoon  — Scheduler: 30 days before LeaveEntitlement.expiresAt
 *                              recipient = entitlement owner (employee user)
 *
 * Phase 9 will add AdminTypeChange when the admin type-change UI lands —
 * deliberately deferred since the trigger doesn't exist yet.
 */
enum NotificationType: string
{
    case ApprovalRequested = 'approval_requested';
    case ApprovalDecided = 'approval_decided';
    case CancelRequested = 'cancel_requested';
    case CancelDecided = 'cancel_decided';
    case EscalationTriggered = 'escalation_triggered';
    case EntitlementExpiringSoon = 'entitlement_expiring_soon';
}
