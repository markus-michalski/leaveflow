<?php

declare(strict_types=1);

namespace App\Application\Approval;

use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use Symfony\Component\Clock\ClockInterface;

/**
 * Resolves who should approve a given LeaveRequest.
 *
 * Chain (first applicable wins):
 * 1. Department lead — if the department is active, the lead is active on the
 *    current date, and the lead is not the requester (four-eyes principle).
 * 2. Department deputy — same preconditions as lead.
 * 3. null — caller (controller/notifier) must escalate to any Admin.
 *
 * Intentionally no skip-level walk (dept.lead → dept.lead's dept.lead).
 * Nested departments are deferred; for now Admin is the ultimate backstop.
 */
final readonly class ApproverResolver implements ApproverResolverInterface
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function resolve(LeaveRequest $request): ?Employee
    {
        $requester = $request->getEmployee();
        $department = $requester->getDepartment();

        if (null === $department || !$department->isActive()) {
            return null;
        }

        $today = $this->clock->now()->setTime(0, 0);

        $lead = $department->getLead();
        if (null !== $lead && $lead !== $requester && $lead->isActiveOn($today)) {
            return $lead;
        }

        $deputy = $department->getDeputy();
        if (null !== $deputy && $deputy !== $requester && $deputy->isActiveOn($today)) {
            return $deputy;
        }

        return null;
    }
}
