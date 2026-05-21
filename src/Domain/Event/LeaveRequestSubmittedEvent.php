<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Entity\LeaveRequest;

final readonly class LeaveRequestSubmittedEvent
{
    public function __construct(
        public LeaveRequest $leaveRequest,
    ) {
    }
}
