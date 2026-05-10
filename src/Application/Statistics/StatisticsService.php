<?php

declare(strict_types=1);

namespace App\Application\Statistics;

use App\Domain\Calculator\IllnessRateCalculator;
use App\Domain\Calculator\UtilizationCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Repository\DepartmentRepository;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\Repository\LeaveRequestDayRepository;
use App\Domain\Repository\LeaveRequestRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Builds the admin statistics dashboard read-model.
 *
 * Aggregates company-wide leave utilization, illness rate, and per-department
 * breakdowns into a single DashboardSnapshot. The snapshot is rendered in
 * one round-trip — no per-card AJAX, no progressive enhancement.
 *
 * k-anonymity: departments below the configured threshold (default 3) ship
 * their employee count but withhold every aggregate metric. The UI is
 * expected to substitute a "zu klein für Anzeige" placeholder.
 */
final readonly class StatisticsService
{
    public const int DEFAULT_ANONYMITY_THRESHOLD = 3;

    public function __construct(
        private UtilizationCalculator $utilizationCalculator,
        private IllnessRateCalculator $illnessRateCalculator,
        private LeaveEntitlementRepository $entitlementRepository,
        private LeaveRequestRepository $requestRepository,
        private LeaveRequestDayRepository $dayRepository,
        private EmployeeRepository $employeeRepository,
        private DepartmentRepository $departmentRepository,
        private ClockInterface $clock,
        private int $anonymityThreshold = self::DEFAULT_ANONYMITY_THRESHOLD,
    ) {
    }

    public function buildDashboard(
        Company $company,
        int $year,
        string $orphanLabel = 'Ohne Abteilung',
    ): DashboardSnapshot {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
        $rangeStart = (new \DateTimeImmutable())->setDate($year, 1, 1)->setTime(0, 0);
        $yearEnd = (new \DateTimeImmutable())->setDate($year, 12, 31)->setTime(0, 0);
        $rangeEnd = $year === (int) $now->format('Y') && $now < $yearEnd ? $now : $yearEnd;

        $activeEmployees = $this->filterActiveInRange(
            $this->employeeRepository->findAllByCompany($company),
            $rangeStart,
            $rangeEnd,
        );

        $entitlements = $this->entitlementRepository->findByCompanyAndYear($company, $year);

        $utilization = $this->utilizationCalculator->calculate($entitlements, $rangeEnd);

        $illnessHours = $this->dayRepository->sumIllnessHoursByEmployeeForCompany(
            $company,
            $rangeStart,
            $rangeEnd,
        );
        $illnessRate = $this->illnessRateCalculator->calculate(
            $activeEmployees,
            $illnessHours,
            $rangeStart,
            $rangeEnd,
        );

        $awaitingCount = $this->requestRepository->countAwaitingDecisionInCompany($company);
        $activeCount = \count($activeEmployees);
        $avgRemaining = $activeCount > 0
            ? round($utilization->totalRemainingHours / $activeCount, 1)
            : 0.0;

        $monthlyRaw = $this->dayRepository->sumApprovedDeductingHoursByMonth($company, $year);
        $monthly = [];
        for ($m = 1; $m <= 12; ++$m) {
            $monthly[$m] = round($monthlyRaw[$m] ?? 0.0, 1);
        }

        $departmentBreakdown = $this->buildDepartmentBreakdown(
            $company,
            $activeEmployees,
            $entitlements,
            $rangeEnd,
            $orphanLabel,
        );

        $availableYears = $this->entitlementRepository->findAvailableYears($company);
        if (!\in_array($year, $availableYears, true)) {
            $availableYears[] = $year;
            rsort($availableYears);
        }

        return new DashboardSnapshot(
            year: $year,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
            utilization: $utilization,
            illnessRate: $illnessRate,
            awaitingDecisionCount: $awaitingCount,
            activeEmployeeCount: $activeCount,
            averageRemainingHours: $avgRemaining,
            monthlyDistribution: $monthly,
            departmentBreakdown: $departmentBreakdown,
            anonymityThreshold: $this->anonymityThreshold,
            availableYears: $availableYears,
        );
    }

    /**
     * @param  list<Employee> $employees
     *
     * @return list<Employee>
     */
    private function filterActiveInRange(
        array $employees,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): array {
        return array_values(array_filter(
            $employees,
            static function (Employee $emp) use ($rangeStart, $rangeEnd): bool {
                $joined = $emp->getJoinedAt()->setTime(0, 0);
                $left = $emp->getLeftAt()?->setTime(0, 0);
                if ($joined > $rangeEnd) {
                    return false;
                }
                if (null !== $left && $left < $rangeStart) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * @param  list<Employee>                 $activeEmployees
     * @param  list<LeaveEntitlement>         $entitlements
     *
     * @return list<DepartmentBreakdownEntry>
     */
    private function buildDepartmentBreakdown(
        Company $company,
        array $activeEmployees,
        array $entitlements,
        \DateTimeImmutable $rangeEnd,
        string $orphanLabel,
    ): array {
        /** @var array<int, list<Employee>> $employeesByDeptId */
        $employeesByDeptId = [];
        /** @var list<Employee> $orphanEmployees */
        $orphanEmployees = [];
        foreach ($activeEmployees as $emp) {
            $dept = $emp->getDepartment();
            if (null === $dept) {
                $orphanEmployees[] = $emp;
                continue;
            }
            $deptId = $dept->getId();
            if (null === $deptId) {
                continue;
            }
            $employeesByDeptId[$deptId][] = $emp;
        }

        /** @var array<int, list<LeaveEntitlement>> $entitlementsByEmployeeId */
        $entitlementsByEmployeeId = [];
        foreach ($entitlements as $ent) {
            $empId = $ent->getEmployee()->getId();
            if (null === $empId) {
                continue;
            }
            $entitlementsByEmployeeId[$empId][] = $ent;
        }

        $rows = [];
        foreach ($this->departmentRepository->findByCompany($company) as $dept) {
            $deptId = $dept->getId();
            $emps = null !== $deptId ? ($employeesByDeptId[$deptId] ?? []) : [];
            $rows[] = $this->makeBreakdownEntry(
                $dept->getName(),
                $emps,
                $entitlementsByEmployeeId,
                $rangeEnd,
            );
        }

        if ([] !== $orphanEmployees) {
            $rows[] = $this->makeBreakdownEntry(
                $orphanLabel,
                $orphanEmployees,
                $entitlementsByEmployeeId,
                $rangeEnd,
            );
        }

        return $rows;
    }

    /**
     * @param list<Employee>                          $employees
     * @param array<int, list<LeaveEntitlement>>      $entitlementsByEmployeeId
     */
    private function makeBreakdownEntry(
        string $name,
        array $employees,
        array $entitlementsByEmployeeId,
        \DateTimeImmutable $rangeEnd,
    ): DepartmentBreakdownEntry {
        $count = \count($employees);
        $hidden = $count < $this->anonymityThreshold;

        if ($hidden) {
            return new DepartmentBreakdownEntry($name, $count, true, null, null, null);
        }

        $deptEntitlements = [];
        foreach ($employees as $emp) {
            $empId = $emp->getId();
            if (null === $empId) {
                continue;
            }
            foreach ($entitlementsByEmployeeId[$empId] ?? [] as $ent) {
                $deptEntitlements[] = $ent;
            }
        }

        $util = $this->utilizationCalculator->calculate($deptEntitlements, $rangeEnd);

        return new DepartmentBreakdownEntry(
            $name,
            $count,
            false,
            $util->totalGrantedHours,
            $util->totalUsedHours,
            $util->utilizationPercent,
        );
    }
}
