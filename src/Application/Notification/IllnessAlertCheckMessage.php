<?php

declare(strict_types=1);

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
