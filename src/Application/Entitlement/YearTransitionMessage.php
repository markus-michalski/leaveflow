<?php

declare(strict_types=1);

namespace App\Application\Entitlement;

/**
 * Marker message for the annual carryover sweep (#35 phase 1).
 *
 * Carries no payload — the handler resolves "this year" via ClockInterface
 * and rolls last year's remaining Regular balances into Carryover entries.
 * Dispatched by the Symfony Scheduler (MaintenanceSchedule) on January 1st
 * shortly after midnight, idempotent against repeat firings.
 */
final readonly class YearTransitionMessage
{
}
