<?php

declare(strict_types=1);

namespace App\Application\Approval;

use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;

/**
 * Resolves who should approve a given LeaveRequest.
 *
 * Existence of this interface is purely for testability — listeners that
 * need to know the resolved approver take this contract so they can be
 * unit-tested with a mocked resolver.
 */
interface ApproverResolverInterface
{
    public function resolve(LeaveRequest $request): ?Employee;
}
