<?php

declare(strict_types=1);

namespace App\Application\Approval;

/**
 * Thrown when an employee tries to request cancellation of an approved leave
 * that has already started or lies in the past. Manager-side rollback for
 * those cases requires a separate admin override (out of Phase 6 scope).
 */
final class CancellationNotAllowedException extends \DomainException
{
    public function __construct(\DateTimeImmutable $startDate)
    {
        parent::__construct(\sprintf('Cancellation is not allowed — leave starting %s has already begun or is in the past.', $startDate->format('Y-m-d')));
    }
}
