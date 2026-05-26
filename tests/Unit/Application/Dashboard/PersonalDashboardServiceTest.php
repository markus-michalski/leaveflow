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

namespace App\Tests\Unit\Application\Dashboard;

use App\Application\Dashboard\PersonalDashboardService;
use App\Application\Entitlement\EntitlementBalanceReader;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PersonalDashboardService::class)]
#[AllowMockObjectsWithoutExpectations]
final class PersonalDashboardServiceTest extends TestCase
{
    private LeaveEntitlementRepository&MockObject $entitlementRepository;
    private LeaveRequestRepository&MockObject $requestRepository;
    private MockClock $clock;
    private PersonalDashboardService $service;

    private Company $acme;
    private Location $hq;
    private Department $engineering;
    private AbsenceType $urlaub;

    protected function setUp(): void
    {
        $this->entitlementRepository = $this->createMock(LeaveEntitlementRepository::class);
        $this->requestRepository = $this->createMock(LeaveRequestRepository::class);
        $this->clock = new MockClock(new \DateTimeImmutable('2026-05-18 09:00:00'));

        $balanceReader = new EntitlementBalanceReader($this->entitlementRepository);

        $this->service = new PersonalDashboardService(
            $balanceReader,
            $this->requestRepository,
            $this->clock,
        );

        $this->acme = new Company('Acme GmbH');
        $this->hq = new Location($this->acme, 'HQ', 'DE', 'DE-BY', 'München');
        $this->engineering = new Department($this->acme, 'Engineering');
        $this->urlaub = new AbsenceType(
            company: $this->acme,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
    }

    // -----------------------------------------------------------------
    // buildForEmployee — balance
    // -----------------------------------------------------------------

    #[Test]
    public function buildForEmployeeReturnsBalanceForCurrentYear(): void
    {
        $employee = $this->employee('Maya Manager');

        $entitlement = new LeaveEntitlement($employee, 2026, LeaveEntitlementType::Regular, 200.0);
        $entitlement->consume(40.0);

        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([$entitlement]);
        $this->requestRepository->method('findActiveAndUpcomingByEmployee')->willReturn([]);
        $this->requestRepository->method('findActiveOverlapping')->willReturn([]);

        $dashboard = $this->service->buildForEmployee($employee);

        self::assertSame(2026, $dashboard->balanceYear);
        self::assertSame(160.0, $dashboard->balance->regularRemaining);
        self::assertSame(0.0, $dashboard->balance->carryoverRemaining);
    }

    #[Test]
    public function buildForEmployeeReturnsUpcomingRequests(): void
    {
        $employee = $this->employee('Erik Employee');
        $request = $this->approvedRequest($employee, '2026-05-20', '2026-05-22');

        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([]);
        $this->requestRepository
            ->method('findActiveAndUpcomingByEmployee')
            ->willReturn([$request]);
        $this->requestRepository->method('findActiveOverlapping')->willReturn([]);

        $dashboard = $this->service->buildForEmployee($employee);

        self::assertCount(1, $dashboard->upcomingRequests);
        self::assertSame($request, $dashboard->upcomingRequests[0]);
    }

    #[Test]
    public function buildForEmployeeReturnsTeamAbsencesTodayForDepartmentedEmployee(): void
    {
        $employee = $this->employeeInDept('Erik Employee');
        $colleague = $this->employeeInDept('Anna Absent');
        $colleagueRequest = $this->approvedRequest($colleague, '2026-05-18', '2026-05-18');

        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([]);
        $this->requestRepository->method('findActiveAndUpcomingByEmployee')->willReturn([]);
        $this->requestRepository
            ->method('findActiveOverlapping')
            ->willReturn([$colleagueRequest]);

        $dashboard = $this->service->buildForEmployee($employee);

        self::assertTrue($dashboard->hasDepartment);
        self::assertCount(1, $dashboard->teamAbsencesToday);
        self::assertSame($colleagueRequest, $dashboard->teamAbsencesToday[0]);
    }

    #[Test]
    public function buildForEmployeeReturnsEmptyTeamAbsencesWhenNoDepartment(): void
    {
        $employee = $this->employee('Erik Employee');

        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([]);
        $this->requestRepository->method('findActiveAndUpcomingByEmployee')->willReturn([]);

        $dashboard = $this->service->buildForEmployee($employee);

        self::assertFalse($dashboard->hasDepartment);
        self::assertSame([], $dashboard->teamAbsencesToday);
    }

    // -----------------------------------------------------------------
    // buildForEmployee — carryover expiry helper
    // -----------------------------------------------------------------

    #[Test]
    public function hasCarryoverExpiringSoonTrueWhenExpiryWithinThreshold(): void
    {
        $employee = $this->employee('Maya Manager');
        $carryover = new LeaveEntitlement($employee, 2026, LeaveEntitlementType::Carryover, 40.0);
        // expires in 10 days from clock (2026-05-28)
        $carryover->adjustExpiresAt(new \DateTimeImmutable('2026-05-28'));

        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([$carryover]);
        $this->requestRepository->method('findActiveAndUpcomingByEmployee')->willReturn([]);
        $this->requestRepository->method('findActiveOverlapping')->willReturn([]);

        $dashboard = $this->service->buildForEmployee($employee);

        self::assertTrue($dashboard->hasCarryoverExpiringSoon(30));
    }

    #[Test]
    public function hasCarryoverExpiringSoonFalseWhenExpiryBeyondThreshold(): void
    {
        $employee = $this->employee('Maya Manager');
        $carryover = new LeaveEntitlement($employee, 2026, LeaveEntitlementType::Carryover, 40.0);
        $carryover->adjustExpiresAt(new \DateTimeImmutable('2026-12-31'));

        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([$carryover]);
        $this->requestRepository->method('findActiveAndUpcomingByEmployee')->willReturn([]);
        $this->requestRepository->method('findActiveOverlapping')->willReturn([]);

        $dashboard = $this->service->buildForEmployee($employee);

        self::assertFalse($dashboard->hasCarryoverExpiringSoon(30));
    }

    // -----------------------------------------------------------------
    // buildForManager
    // -----------------------------------------------------------------

    #[Test]
    public function buildForManagerContainsPersonalDashboard(): void
    {
        $manager = $this->employeeInDept('Maya Manager');

        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([]);
        $this->requestRepository->method('findActiveAndUpcomingByEmployee')->willReturn([]);
        $this->requestRepository->method('findActiveOverlapping')->willReturn([]);
        $this->requestRepository->method('findActionableByApprover')->willReturn([]);

        $dashboard = $this->service->buildForManager($manager);

        self::assertSame(2026, $dashboard->personal->balanceYear);
    }

    #[Test]
    public function buildForManagerReturnsPendingApprovals(): void
    {
        $manager = $this->employeeInDept('Maya Manager');
        $reportee = $this->employeeInDept('Erik Employee');
        $pending = $this->pendingRequest($reportee, '2026-05-25', '2026-05-29');

        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([]);
        $this->requestRepository->method('findActiveAndUpcomingByEmployee')->willReturn([]);
        $this->requestRepository->method('findActiveOverlapping')->willReturn([]);
        $this->requestRepository
            ->method('findActionableByApprover')
            ->willReturn([$pending]);

        $dashboard = $this->service->buildForManager($manager);

        self::assertTrue($dashboard->hasPendingApprovals());
        self::assertSame(1, $dashboard->pendingApprovalCount());
        self::assertSame($pending, $dashboard->pendingApprovals[0]);
    }

    #[Test]
    public function buildForManagerReturnsWeekAbsencesExcludingManager(): void
    {
        $manager = $this->employeeInDept('Maya Manager');

        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([]);
        $this->requestRepository->method('findActiveAndUpcomingByEmployee')->willReturn([]);
        $this->requestRepository->method('findActionableByApprover')->willReturn([]);

        // First call (today, employee view): empty; second call (week, manager view): one entry
        $weekRequest = $this->approvedRequest($this->employeeInDept('Anna Absent'), '2026-05-18', '2026-05-20');
        $this->requestRepository
            ->method('findActiveOverlapping')
            ->willReturnOnConsecutiveCalls([], [$weekRequest]);

        $dashboard = $this->service->buildForManager($manager);

        self::assertCount(1, $dashboard->teamAbsencesWeek);
        self::assertSame($weekRequest, $dashboard->teamAbsencesWeek[0]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function employee(string $name): Employee
    {
        return new Employee(
            company: $this->acme,
            fullName: $name,
            employeeNumber: 'EMP-'.str_replace(' ', '', $name),
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
    }

    private function employeeInDept(string $name): Employee
    {
        $employee = $this->employee($name);
        $employee->assignToDepartment($this->engineering);

        return $employee;
    }

    private function approvedRequest(Employee $employee, string $start, string $end): LeaveRequest
    {
        $request = new LeaveRequest(
            employee: $employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            dayType: \App\Domain\Enum\LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );
        $request->setStatus(LeaveRequestStatus::Approved);

        return $request;
    }

    private function pendingRequest(Employee $employee, string $start, string $end): LeaveRequest
    {
        return new LeaveRequest(
            employee: $employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            dayType: \App\Domain\Enum\LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );
    }
}
