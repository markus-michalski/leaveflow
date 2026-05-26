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

namespace App\Infrastructure\Scheduler;

use App\Application\Notification\ApprovalEscalationCheckMessage;
use App\Application\Notification\EntitlementExpiryCheckMessage;
use App\Application\Notification\IllnessAlertCheckMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Phase 8 + Phase 9 notification schedule.
 *
 * Dispatches:
 * - EntitlementExpiryCheck — daily 03:00, finds entitlements expiring in
 *   the next 30 days that haven't yet been warned about and notifies their
 *   owners.
 * - ApprovalEscalationCheck — hourly, finds Pending leave requests that
 *   exceed their company's approvalEscalationDays threshold and notifies
 *   the company's active admins.
 * - IllnessAlertCheck — daily 06:00, scans all active employees for
 *   illness runs that hit the §3 EntgFG threshold (42 calendar days).
 *
 * Worker: `php bin/console messenger:consume scheduler_notifications`.
 */
#[AsSchedule('notifications')]
final class NotificationSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::cron('0 3 * * *', new EntitlementExpiryCheckMessage()),
                RecurringMessage::cron('0 * * * *', new ApprovalEscalationCheckMessage()),
                RecurringMessage::cron('0 6 * * *', new IllnessAlertCheckMessage()),
            );
    }
}
