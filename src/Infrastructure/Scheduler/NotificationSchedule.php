<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Application\Notification\ApprovalEscalationCheckMessage;
use App\Application\Notification\EntitlementExpiryCheckMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Phase 8 notification schedule.
 *
 * Dispatches:
 * - EntitlementExpiryCheck — daily 03:00, finds entitlements expiring in
 *   the next 30 days that haven't yet been warned about and notifies their
 *   owners.
 * - ApprovalEscalationCheck — hourly, finds Pending leave requests that
 *   exceed their company's approvalEscalationDays threshold and notifies
 *   the company's active admins.
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
            );
    }
}
