<?php

declare(strict_types=1);

namespace App\Application\Approval;

use App\Domain\Enum\LeaveRequestStatus;

/**
 * Thrown when a workflow transition is attempted from a state that does not
 * allow it (e.g. approving an already-rejected request).
 */
final class InvalidTransitionException extends \DomainException
{
    public function __construct(string $transition, LeaveRequestStatus $currentStatus)
    {
        parent::__construct(\sprintf('Transition "%s" is not allowed from state "%s".', $transition, $currentStatus->value));
    }
}
