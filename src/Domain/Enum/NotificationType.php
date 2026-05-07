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
 * - AdminTypeChange          — AdminTypeChangeService: admin reclassified the
 *                              absence type of an existing approved request
 *                              (Phase 9, e.g. wrongly classified as Urlaub
 *                              instead of Sonderurlaub)
 *                              recipient = request owner (employee)
 */
enum NotificationType: string
{
    case ApprovalRequested = 'approval_requested';
    case ApprovalDecided = 'approval_decided';
    case RequestWithdrawn = 'request_withdrawn';
    case CancelRequested = 'cancel_requested';
    case CancelDecided = 'cancel_decided';
    case EscalationTriggered = 'escalation_triggered';
    case EntitlementExpiringSoon = 'entitlement_expiring_soon';
    case AdminTypeChange = 'admin_type_change';

    /**
     * Symfony role that can ever be a recipient of this notification type.
     * Drives the preferences UI — users only see toggles for types they can
     * actually receive given their role. The dispatcher itself doesn't
     * enforce this; it trusts the caller (subscriber/scheduler) to pass
     * appropriate recipients.
     */
    public function requiredSymfonyRole(): string
    {
        return match ($this) {
            // Manager-only signals — sent to a department lead/deputy via
            // ApproverResolver.
            self::ApprovalRequested,
            self::RequestWithdrawn,
            self::CancelRequested => 'ROLE_MANAGER',

            // Admin-only signal — escalation backstop fan-out.
            self::EscalationTriggered => 'ROLE_ADMIN',

            // Anyone-with-Employee signals — about the user's own request
            // or own entitlement.
            self::ApprovalDecided,
            self::CancelDecided,
            self::EntitlementExpiringSoon,
            self::AdminTypeChange => 'ROLE_EMPLOYEE',
        };
    }

    /**
     * Translation key for the email subject line. ApprovalDecided and
     * CancelDecided carry a decision (approved/rejected, confirmed/denied)
     * that selects the matching sub-key in notifications.<locale>.yaml; all
     * other types have a flat subject key.
     *
     * Twig templates do this resolution inline for body strings; the
     * dispatcher needs the same logic in PHP for the subject.
     *
     * @param array<string, mixed> $payload
     */
    public function emailSubjectTranslationKey(array $payload): string
    {
        return match ($this) {
            self::ApprovalDecided, self::CancelDecided => \sprintf(
                'email.%s.%s.subject',
                $this->value,
                \is_string($payload['decision'] ?? null) ? $payload['decision'] : 'unknown',
            ),
            default => \sprintf('email.%s.subject', $this->value),
        };
    }
}
