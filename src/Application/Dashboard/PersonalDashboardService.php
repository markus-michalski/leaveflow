<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Entitlement\EntitlementBalanceReader;
use App\Domain\Entity\Employee;
use App\Domain\Repository\LeaveRequestRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Builds role-specific personal dashboard read-models.
 *
 * Employee view: leave balance, upcoming requests, team absences today.
 * Manager view: all of the above plus pending approvals and team week overview.
 */
final readonly class PersonalDashboardService
{
    public function __construct(
        private EntitlementBalanceReader $balanceReader,
        private LeaveRequestRepository $requestRepository,
        private ClockInterface $clock,
    ) {
    }

    public function buildForEmployee(Employee $employee): EmployeeDashboard
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $year = (int) $now->format('Y');

        $balance = $this->balanceReader->forEmployee($employee, $year, $now);

        $upcoming = $this->requestRepository->findActiveAndUpcomingByEmployee($employee, $now);

        $department = $employee->getDepartment();
        $teamToday = [];

        if (null !== $department) {
            $today = $now->setTime(0, 0);
            $teamToday = $this->requestRepository->findActiveOverlapping(
                company: $employee->getCompany(),
                rangeStart: $today,
                rangeEnd: $today,
                department: $department,
                absenceType: null,
                excludingEmployee: $employee,
            );
        }

        return new EmployeeDashboard(
            balance: $balance,
            balanceYear: $year,
            upcomingRequests: $upcoming,
            teamAbsencesToday: $teamToday,
            hasDepartment: null !== $department,
        );
    }

    public function buildForManager(Employee $manager): ManagerDashboard
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $personal = $this->buildForEmployee($manager);

        $pendingApprovals = $this->requestRepository->findActionableByApprover($manager);

        $department = $manager->getDepartment();
        $weekAbsences = [];

        if (null !== $department) {
            $monday = $now->setTime(0, 0)->modify('Monday this week');
            $sunday = $now->setTime(0, 0)->modify('Sunday this week');
            $weekAbsences = $this->requestRepository->findActiveOverlapping(
                company: $manager->getCompany(),
                rangeStart: $monday,
                rangeEnd: $sunday,
                department: $department,
                absenceType: null,
                excludingEmployee: $manager,
            );
        }

        return new ManagerDashboard(
            personal: $personal,
            pendingApprovals: $pendingApprovals,
            teamAbsencesWeek: $weekAbsences,
        );
    }
}
