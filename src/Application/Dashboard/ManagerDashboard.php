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

namespace App\Application\Dashboard;

use App\Domain\Entity\LeaveRequest;

/**
 * Dashboard read-model for managers — extends the personal employee view with
 * team-level data: pending approvals and who's away this week.
 */
final readonly class ManagerDashboard
{
    /**
     * @param list<LeaveRequest> $pendingApprovals  Requests awaiting this manager's decision
     * @param list<LeaveRequest> $teamAbsencesWeek  Approved team absences covering the current week
     */
    public function __construct(
        public EmployeeDashboard $personal,
        public array $pendingApprovals,
        public array $teamAbsencesWeek,
    ) {
    }

    public function pendingApprovalCount(): int
    {
        return \count($this->pendingApprovals);
    }

    public function hasPendingApprovals(): bool
    {
        return [] !== $this->pendingApprovals;
    }
}
