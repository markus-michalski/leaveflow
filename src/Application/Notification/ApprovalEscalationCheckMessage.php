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
