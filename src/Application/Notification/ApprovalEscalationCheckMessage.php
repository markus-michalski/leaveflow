<?php

declare(strict_types=1);

namespace App\Application\Notification;

/**
 * Marker message for the periodic approval-escalation sweep.
 *
 * Carries no payload — the handler resolves the threshold per-company via
 * Company.approvalEscalationDays. Dispatched on an hourly cadence by
 * NotificationSchedule.
 */
final readonly class ApprovalEscalationCheckMessage
{
}
