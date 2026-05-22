<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Application\Employee\ExitDeactivationCheckMessage;
use App\Application\Entitlement\YearTransitionMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Maintenance crons — non-notification scheduled jobs (#35 phase 1).
 *
 * Kept separate from {@see NotificationSchedule} for clarity: notifications
 * dispatch user-facing emails on minute/hourly cadence, maintenance runs
 * at low frequency and mutates entitlement/balance state. Different worker
 * transport so a backed-up notification queue can't delay year-end work.
 *
 * Currently scheduled:
 * - YearTransition — January 1st at 01:00, rolls remaining Regular balances
 *   from year N-1 into Carryover entries for year N. Idempotent against
 *   repeated firings (existing carryovers are skipped).
 * - ExitDeactivationCheck — daily 07:00, finds employees whose exit date has
 *   been reached and deactivates their user accounts (#81, #82).
 *
 * Worker: `php bin/console messenger:consume scheduler_maintenance`.
 */
#[AsSchedule('maintenance')]
final class MaintenanceSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                // Minute=0, Hour=1, Day-of-month=1, Month=1, Day-of-week=*
                // → "January 1st at 01:00 every year".
                RecurringMessage::cron('0 1 1 1 *', new YearTransitionMessage()),
                RecurringMessage::cron('0 7 * * *', new ExitDeactivationCheckMessage()),
            );
    }
}
