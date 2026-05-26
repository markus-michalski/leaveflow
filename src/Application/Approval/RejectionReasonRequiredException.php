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

/**
 * Thrown when a manager rejects a leave request without supplying a reason.
 * The reason is the only domain-level field the manager has to justify a
 * rejection and is rendered to the employee in the notification email.
 */
final class RejectionReasonRequiredException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('A rejection reason is required and must not be empty.');
    }
}
