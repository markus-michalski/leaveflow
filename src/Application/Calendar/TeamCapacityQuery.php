<?php

declare(strict_types=1);

namespace App\Application\Calendar;

use App\Domain\Entity\Employee;
use App\Domain\Repository\LeaveRequestRepository;

/**
 * Counts approved-leave overlap among an employee's department peers for a
 * given range.
 *
 * Drives the soft "X colleagues already absent in this window" hint on the
 * leave-request form. Returns 0 when the employee has no department (solo
 * mode) — there is no peer set to compare against.
 */
final readonly class TeamCapacityQuery
{
    public function __construct(
        private LeaveRequestRepository $leaveRequestRepository,
    ) {
    }

    public function countOverlappingPeers(
        Employee $employee,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): int {
        $department = $employee->getDepartment();
        if (null === $department) {
            return 0;
        }

        $overlapping = $this->leaveRequestRepository->findApprovedOverlapping(
            $employee->getCompany(),
            $startDate,
            $endDate,
            $department,
            null,
            $employee,
        );

        // Distinct peers, not request rows — one peer with two short
        // overlapping requests counts once.
        $peerIds = [];
        foreach ($overlapping as $request) {
            $peerIds[$request->getEmployee()->getId()] = true;
        }

        return \count($peerIds);
    }
}
