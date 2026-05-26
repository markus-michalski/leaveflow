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

namespace App\Application\Employee;

/**
 * Thrown when an admin attempts to anonymize an employee whose DSGVO
 * retention period has not yet elapsed. This is a user-facing condition,
 * not a programmer error — catch it separately from \LogicException.
 */
final class AnonymizationNotDueException extends \RuntimeException
{
}
