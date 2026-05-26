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

use App\Application\Entitlement\BalanceSnapshot;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\LeaveRequestStatus;

/**
 * Personal dashboard read-model for employees.
 *
 * Aggregated from EntitlementBalanceReader + LeaveRequestRepository in a
 * single service call. Passed directly to the Twig template — no further
 * processing in the controller.
 */
final readonly class EmployeeDashboard
{
    /**
     * @param list<LeaveRequest> $upcomingRequests  Active and future requests (endDate >= today), limit 5
     * @param list<LeaveRequest> $teamAbsencesToday Colleagues in same department absent today (excluding self)
     */
    public function __construct(
        public BalanceSnapshot $balance,
        public int $balanceYear,
        public array $upcomingRequests,
        public array $teamAbsencesToday,
        public bool $hasDepartment,
    ) {
    }

    public function hasCarryoverExpiringSoon(int $daysThreshold = 30): bool
    {
        if (null === $this->balance->nextExpiry || $this->balance->carryoverRemaining <= 0) {
            return false;
        }

        $now = new \DateTimeImmutable('today');
        $diff = (int) $now->diff($this->balance->nextExpiry)->days;

        return $diff <= $daysThreshold;
    }

    public function daysUntilCarryoverExpiry(): ?int
    {
        if (null === $this->balance->nextExpiry) {
            return null;
        }

        return (int) (new \DateTimeImmutable('today'))->diff($this->balance->nextExpiry)->days;
    }

    /**
     * Hours from Pending requests not yet booked against entitlements.
     * Approved/Recorded are already reflected in balance.regularUsed / carryoverUsed.
     */
    public function plannedHours(): float
    {
        $total = 0.0;
        foreach ($this->upcomingRequests as $request) {
            if (LeaveRequestStatus::Pending === $request->getStatus()) {
                $total += $request->getTotalHours();
            }
        }

        return $total;
    }
}
