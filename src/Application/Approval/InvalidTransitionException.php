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
