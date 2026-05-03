<?php

declare(strict_types=1);

namespace App\Application\Notification;

/**
 * Marker message for the daily entitlement-expiry sweep.
 *
 * Carries no payload — the handler resolves "today" via ClockInterface and
 * walks the LeaveEntitlement table directly. Dispatched by the Symfony
 * Scheduler (NotificationSchedule) on a daily cadence.
 */
final readonly class EntitlementExpiryCheckMessage
{
}
