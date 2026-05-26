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
 * Marker message for the daily 6-week-illness sweep.
 *
 * Carries no payload — the handler walks every active employee and
 * resolves their illness-tracking LeaveRequests via the repository.
 * Dispatched by the Symfony Scheduler (NotificationSchedule) once a day.
 */
final readonly class IllnessAlertCheckMessage
{
}
