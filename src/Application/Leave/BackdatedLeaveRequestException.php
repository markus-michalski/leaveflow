<?php

declare(strict_types=1);

namespace App\Application\Leave;

/**
 * Thrown by LeaveRequestService when a leave request's start date lies before
 * the current day.
 *
 * Self-service employees cannot file backdated requests — a correction flow
 * driven by the admin is the correct route for past entries (Post-MVP).
 */
final class BackdatedLeaveRequestException extends \DomainException
{
    public function __construct(
        public readonly \DateTimeImmutable $startDate,
    ) {
        parent::__construct(\sprintf(
            'Leave request start date %s is in the past; backdated requests are not supported.',
            $startDate->format('Y-m-d'),
        ));
    }
}
