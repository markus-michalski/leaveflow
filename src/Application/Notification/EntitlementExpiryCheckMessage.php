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
 * Marker message for the daily entitlement-expiry sweep.
 *
 * Carries no payload — the handler resolves "today" via ClockInterface and
 * walks the LeaveEntitlement table directly. Dispatched by the Symfony
 * Scheduler (NotificationSchedule) on a daily cadence.
 */
final readonly class EntitlementExpiryCheckMessage
{
}
