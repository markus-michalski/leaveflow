<?php

declare(strict_types=1);

namespace App\Application\Leave;

/**
 * Thrown by LeaveRequestService::create when a deducting leave request would
 * exceed the employee's remaining balance for a given year, after accounting
 * for existing pending requests.
 *
 * Carries enough context for the UI to render a specific error ("You asked for
 * 40h, only 24h left for 2026 after pending requests") rather than a generic
 * failure.
 */
final class InsufficientLeaveBalanceException extends \DomainException
{
    public function __construct(
        public readonly int $year,
        public readonly float $requestedHours,
        public readonly float $availableHours,
    ) {
        parent::__construct(\sprintf(
            'Insufficient leave balance for %d: requested %.2fh, %.2fh available after pending requests.',
            $year,
            $requestedHours,
            $availableHours,
        ));
    }
}
