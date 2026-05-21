<?php

declare(strict_types=1);

namespace App\Application\Employee;

/**
 * Thrown when an admin attempts to anonymize an employee whose DSGVO
 * retention period has not yet elapsed. This is a user-facing condition,
 * not a programmer error — catch it separately from \LogicException.
 */
final class AnonymizationNotDueException extends \RuntimeException
{
}
