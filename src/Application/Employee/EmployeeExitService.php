<?php

declare(strict_types=1);

namespace App\Application\Employee;

use App\Application\Entitlement\EntitlementBalanceReader;
use App\Domain\Entity\Employee;
use Symfony\Component\Clock\ClockInterface;

/**
 * Orchestrates the employee exit workflow.
 *
 * Sets the exit date, deactivates the linked user account, and computes
 * the remaining leave balance so the controller can present an actionable
 * summary to the admin. Does NOT flush — the caller owns the transaction.
 */
final readonly class EmployeeExitService
{
    public function __construct(
        private EntitlementBalanceReader $balanceReader,
        private ClockInterface $clock,
    ) {
    }

    public function execute(Employee $employee, \DateTimeImmutable $exitDate): ExitSummary
    {
        $employee->markLeft($exitDate);

        // Deactivate immediately only when the exit date is today or in the past.
        // Future-dated exits keep the user active; a daily scheduled job (#82)
        // handles deactivation on the actual exit date.
        $userDeactivated = false;
        $user = $employee->getUser();
        if (null !== $user && $exitDate <= $this->clock->now()) {
            $user->deactivate();
            $userDeactivated = true;
        }

        $exitYear = (int) $exitDate->format('Y');
        $currentBalance = $this->balanceReader->forEmployee($employee, $exitYear, $exitDate);
        $totalRemaining = $currentBalance->totalRemaining();

        return new ExitSummary(
            totalRemainingHours: $totalRemaining,
            exitLeaveHandling: $employee->getCompany()->getExitLeaveHandling(),
            exitDate: $exitDate,
            userDeactivated: $userDeactivated,
        );
    }
}
