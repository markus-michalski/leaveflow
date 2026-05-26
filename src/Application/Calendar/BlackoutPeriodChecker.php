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

namespace App\Application\Calendar;

use App\Domain\Entity\Employee;
use App\Domain\Repository\BlackoutPeriodRepository;

/**
 * Application-layer guard that prevents leave requests from being created
 * within an admin-defined BlackoutPeriod window.
 *
 * Call ensureRangeIsClear() before persisting a LeaveRequest. The checker
 * forwards the employee's department to the repository so that
 * department-scoped blackouts only trip when relevant.
 */
final class BlackoutPeriodChecker
{
    public function __construct(
        private readonly BlackoutPeriodRepository $repository,
    ) {
    }

    /**
     * @throws BlackoutPeriodViolationException when one or more blackout
     *                                          periods overlap the requested range
     */
    public function ensureRangeIsClear(
        Employee $employee,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): void {
        $overlaps = $this->repository->findOverlapping(
            $employee->getCompany(),
            $startDate,
            $endDate,
            $employee->getDepartment(),
        );

        if ([] === $overlaps) {
            return;
        }

        throw BlackoutPeriodViolationException::forBlackouts($overlaps);
    }
}
