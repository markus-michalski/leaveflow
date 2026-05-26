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
    public const int DEFAULT_EXPIRY_HORIZON_DAYS = 90;
    public const int DEFAULT_OVERDUE_THRESHOLD_DAYS = 5;
    public const int DEFAULT_ABSENCES_LIMIT = 10;

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
        private int $expiryHorizonDays = self::DEFAULT_EXPIRY_HORIZON_DAYS,
        private int $overdueThresholdDays = self::DEFAULT_OVERDUE_THRESHOLD_DAYS,
        private int $absencesLimit = self::DEFAULT_ABSENCES_LIMIT,
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
        // Emit as a 0-indexed list (Jan=0..Dec=11) so json_encode produces a
        // JSON array, not a JSON object — Stimulus' Array value type rejects
        // the latter. The chart controller pairs the values with the
        // monthLabels array literal in the template, which is also 0-indexed.
        $monthly = [];
        for ($m = 1; $m <= 12; ++$m) {
            $monthly[] = round($monthlyRaw[$m] ?? 0.0, 1);
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

        $expiringCarryovers = $this->buildExpiringCarryovers($company, $now);
        $overduePending = $this->buildOverduePending($company, $now);
        $currentAbsences = $this->buildCurrentAbsences($company, $now);
        $currentAbsencesTotal = $this->requestRepository->countActiveAbsencesOn($company, $now);

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
            expiringCarryovers: $expiringCarryovers,
            overduePending: $overduePending,
            expiryHorizonDays: $this->expiryHorizonDays,
            overdueThresholdDays: $this->overdueThresholdDays,
            currentAbsences: $currentAbsences,
            currentAbsencesTotal: $currentAbsencesTotal,
            currentAbsencesLimit: $this->absencesLimit,
        );
    }

    /**
     * @return list<CurrentAbsenceEntry>
     */
    private function buildCurrentAbsences(Company $company, \DateTimeImmutable $today): array
    {
        $requests = $this->requestRepository->findActiveAbsencesOn(
            $company,
            $today,
            $this->absencesLimit,
        );

        $todayMidnight = $today->setTime(0, 0);
        $entries = [];
        foreach ($requests as $request) {
            $requestId = $request->getId();
            if (null === $requestId) {
                continue;
            }
            $endDate = $request->getEndDate()->setTime(0, 0);

            $entries[] = new CurrentAbsenceEntry(
                requestId: $requestId,
                employeeName: $request->getEmployee()->getFullName(),
                absenceTypeName: $request->getAbsenceType()->getName(),
                absenceTypeColor: $request->getAbsenceType()->getColor(),
                endDate: $endDate,
                // String compare avoids both timezone wobbles and the
                // CS-Fixer strict_comparison rule (=== on DateTimeImmutable
                // would be identity-not-value, which is wrong here).
                endsToday: $endDate->format('Y-m-d') === $todayMidnight->format('Y-m-d'),
            );
        }

        return $entries;
    }

    /**
     * @return list<ExpiringCarryoverEntry>
     */
    private function buildExpiringCarryovers(Company $company, \DateTimeImmutable $today): array
    {
        $carryovers = $this->entitlementRepository->findCarryoversExpiringWithin(
            $company,
            $today,
            $this->expiryHorizonDays,
        );

        $entries = [];
        foreach ($carryovers as $entitlement) {
            $expiresAt = $entitlement->getExpiresAt();
            if (null === $expiresAt) {
                continue;
            }
            $entitlementId = $entitlement->getId();
            if (null === $entitlementId) {
                continue;
            }

            $entries[] = new ExpiringCarryoverEntry(
                entitlementId: $entitlementId,
                employeeName: $entitlement->getEmployee()->getFullName(),
                employeeNumber: $entitlement->getEmployee()->getEmployeeNumber(),
                hoursRemaining: $entitlement->getHoursRemaining(),
                expiresAt: $expiresAt,
                daysUntilExpiry: $this->daysBetween($today, $expiresAt),
            );
        }

        return $entries;
    }

    /**
     * Calendar-day difference, timezone-independent. Both inputs are
     * reduced to their YYYY-MM-DD components and re-instantiated in UTC
     * before diffing — `\DateTime::diff` would otherwise off-by-one when
     * one operand is UTC (typical of MockClock and some prod cron paths)
     * and the other is the app's default timezone (typical of Doctrine
     * date_immutable hydration). Sign is preserved via `%r%a`.
     */
    private function daysBetween(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        $utc = new \DateTimeZone('UTC');
        $aUtc = new \DateTimeImmutable($a->format('Y-m-d'), $utc);
        $bUtc = new \DateTimeImmutable($b->format('Y-m-d'), $utc);

        return (int) $aUtc->diff($bUtc)->format('%r%a');
    }

    /**
     * @return list<OverduePendingEntry>
     */
    private function buildOverduePending(Company $company, \DateTimeImmutable $now): array
    {
        $requests = $this->requestRepository->findOverduePendingInCompany(
            $company,
            $now,
            $this->overdueThresholdDays,
        );

        $entries = [];
        foreach ($requests as $request) {
            $requestId = $request->getId();
            if (null === $requestId) {
                continue;
            }
            $daysWaiting = $this->daysBetween($request->getRequestedAt(), $now);

            $entries[] = new OverduePendingEntry(
                requestId: $requestId,
                employeeName: $request->getEmployee()->getFullName(),
                absenceTypeName: $request->getAbsenceType()->getName(),
                startDate: $request->getStartDate(),
                endDate: $request->getEndDate(),
                requestedAt: $request->getRequestedAt(),
                daysWaiting: $daysWaiting,
            );
        }

        return $entries;
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
