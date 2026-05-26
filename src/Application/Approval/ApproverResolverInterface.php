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
