<?php

declare(strict_types=1);

namespace App\Application\Entitlement;

use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\LeaveEntitlementRepository;

/**
 * Reads the aggregated leave balance for an employee at a reference date.
 *
 * Used by the upcoming Phase 5 dashboard. Carryover hours are only counted
 * when not yet expired relative to `asOf` — the expiry date itself still
 * counts as available (isExpiredOn returns false on the deadline).
 */
final readonly class EntitlementBalanceReader
{
    public function __construct(private LeaveEntitlementRepository $repository)
    {
    }

    public function forEmployee(Employee $employee, int $year, \DateTimeImmutable $asOf): BalanceSnapshot
    {
        /** @var list<LeaveEntitlement> $entitlements */
        $entitlements = $this->repository->findByEmployeeAndYear($employee, $year);

        $regularGranted = 0.0;
        $regularUsed = 0.0;
        $carryoverGranted = 0.0;
        $carryoverUsed = 0.0;
        $nextExpiry = null;

        foreach ($entitlements as $entitlement) {
            if ($entitlement->isExpiredOn($asOf)) {
                continue;
            }

            if (LeaveEntitlementType::Regular === $entitlement->getType()) {
                $regularGranted += $entitlement->getHoursGranted();
                $regularUsed += $entitlement->getHoursUsed();
                continue;
            }

            $carryoverGranted += $entitlement->getHoursGranted();
            $carryoverUsed += $entitlement->getHoursUsed();

            $expiry = $entitlement->getExpiresAt();
            $remaining = $entitlement->getHoursRemaining();
            if (null !== $expiry && $remaining > 0 && (null === $nextExpiry || $expiry < $nextExpiry)) {
                $nextExpiry = $expiry;
            }
        }

        return new BalanceSnapshot(
            regularGranted: $regularGranted,
            regularUsed: $regularUsed,
            regularRemaining: $regularGranted - $regularUsed,
            carryoverGranted: $carryoverGranted,
            carryoverUsed: $carryoverUsed,
            carryoverRemaining: $carryoverGranted - $carryoverUsed,
            nextExpiry: $nextExpiry,
        );
    }
}
