<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Statistics;

use App\Application\Statistics\DashboardSnapshot;
use App\Application\Statistics\DepartmentBreakdownEntry;
use App\Application\Statistics\StatisticsService;
use App\Domain\Calculator\IllnessRateCalculator;
use App\Domain\Calculator\UtilizationCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\DepartmentRepository;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\Repository\LeaveRequestDayRepository;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(StatisticsService::class)]
#[CoversClass(DashboardSnapshot::class)]
#[CoversClass(DepartmentBreakdownEntry::class)]
final class StatisticsServiceTest extends TestCase
{
    private LeaveEntitlementRepository&Stub $entitlementRepo;
    private LeaveRequestRepository&Stub $requestRepo;
    private LeaveRequestDayRepository&Stub $dayRepo;
    private EmployeeRepository&Stub $employeeRepo;
    private DepartmentRepository&Stub $departmentRepo;

    private Company $acme;
    private Location $hq;

    protected function setUp(): void
    {
        $this->entitlementRepo = $this->createStub(LeaveEntitlementRepository::class);
        $this->requestRepo = $this->createStub(LeaveRequestRepository::class);
        $this->dayRepo = $this->createStub(LeaveRequestDayRepository::class);
        $this->employeeRepo = $this->createStub(EmployeeRepository::class);
        $this->departmentRepo = $this->createStub(DepartmentRepository::class);

        $this->acme = new Company('Acme GmbH');
        $this->hq = new Location($this->acme, 'HQ', 'DE', 'DE-BY', 'München');
    }

    #[Test]
    public function buildsCompanyWideKpis(): void
    {
        $alice = $this->makeEmployee('Alice', 'EMP-1', '2025-01-01', null, 1);
        $bob = $this->makeEmployee('Bob', 'EMP-2', '2025-01-01', null, 2);
        $charlie = $this->makeEmployee('Charlie', 'EMP-3', '2025-01-01', null, 3);

        $aliceReg = new LeaveEntitlement($alice, 2026, LeaveEntitlementType::Regular, 240.0);
        $aliceReg->consume(80.0);
        $bobReg = new LeaveEntitlement($bob, 2026, LeaveEntitlementType::Regular, 240.0);
        $bobReg->consume(40.0);
        $charlieReg = new LeaveEntitlement($charlie, 2026, LeaveEntitlementType::Regular, 240.0);

        $this->employeeRepo->method('findAllByCompany')->willReturn([$alice, $bob, $charlie]);
        $this->entitlementRepo->method('findByCompanyAndYear')->willReturn([$aliceReg, $bobReg, $charlieReg]);
        $this->entitlementRepo->method('findAvailableYears')->willReturn([2026, 2025]);
        $this->dayRepo->method('sumIllnessHoursByEmployeeForCompany')->willReturn([1 => 16.0]);
        $this->dayRepo->method('sumApprovedDeductingHoursByMonth')->willReturn([3 => 80.0, 6 => 40.0]);
        $this->requestRepo->method('countAwaitingDecisionInCompany')->willReturn(2);
        $this->departmentRepo->method('findByCompany')->willReturn([]);

        $service = $this->makeService(new MockClock('2026-12-15'));
        $snapshot = $service->buildDashboard($this->acme, 2026);

        self::assertSame(2026, $snapshot->year);
        self::assertSame(720.0, $snapshot->utilization->totalGrantedHours);
        self::assertSame(120.0, $snapshot->utilization->totalUsedHours);
        // 120 / 720 = 16.666... → 16.7
        self::assertSame(16.7, $snapshot->utilization->utilizationPercent);
        self::assertSame(2, $snapshot->awaitingDecisionCount);
        self::assertSame(3, $snapshot->activeEmployeeCount);
        // 600 remaining / 3 = 200.0
        self::assertSame(200.0, $snapshot->averageRemainingHours);
        self::assertSame(16.0, $snapshot->illnessRate->totalIllnessHours);
        self::assertSame([2026, 2025], $snapshot->availableYears);
    }

    #[Test]
    public function pastYearUsesYearEndAsRangeEnd(): void
    {
        $this->stubEmptyRepos();

        $service = $this->makeService(new MockClock('2026-12-15'));
        $snapshot = $service->buildDashboard($this->acme, 2025);

        self::assertSame('2025-01-01', $snapshot->rangeStart->format('Y-m-d'));
        self::assertSame('2025-12-31', $snapshot->rangeEnd->format('Y-m-d'));
    }

    #[Test]
    public function currentYearCapsRangeEndAtToday(): void
    {
        $this->stubEmptyRepos();

        $service = $this->makeService(new MockClock('2026-05-10'));
        $snapshot = $service->buildDashboard($this->acme, 2026);

        self::assertSame('2026-05-10', $snapshot->rangeEnd->format('Y-m-d'));
    }

    #[Test]
    public function futureYearStillUsesYearEndAsRangeEnd(): void
    {
        $this->stubEmptyRepos();

        $service = $this->makeService(new MockClock('2026-05-10'));
        $snapshot = $service->buildDashboard($this->acme, 2027);

        self::assertSame('2027-12-31', $snapshot->rangeEnd->format('Y-m-d'));
    }

    #[Test]
    public function monthlyDistributionContainsAllTwelveMonthsZeroFilled(): void
    {
        $this->employeeRepo->method('findAllByCompany')->willReturn([]);
        $this->entitlementRepo->method('findByCompanyAndYear')->willReturn([]);
        $this->entitlementRepo->method('findAvailableYears')->willReturn([2026]);
        $this->dayRepo->method('sumIllnessHoursByEmployeeForCompany')->willReturn([]);
        $this->dayRepo->method('sumApprovedDeductingHoursByMonth')->willReturn([3 => 80.0, 7 => 40.0]);
        $this->requestRepo->method('countAwaitingDecisionInCompany')->willReturn(0);
        $this->departmentRepo->method('findByCompany')->willReturn([]);

        $service = $this->makeService(new MockClock('2026-12-15'));
        $snapshot = $service->buildDashboard($this->acme, 2026);

        self::assertCount(12, $snapshot->monthlyDistribution);
        self::assertSame(0.0, $snapshot->monthlyDistribution[1]);
        self::assertSame(80.0, $snapshot->monthlyDistribution[3]);
        self::assertSame(40.0, $snapshot->monthlyDistribution[7]);
        self::assertSame(0.0, $snapshot->monthlyDistribution[12]);
    }

    #[Test]
    public function respectsAnonymityThresholdInDepartmentBreakdown(): void
    {
        $engineeringId = 10;
        $supportId = 20;
        $engineering = $this->makeDepartment('Engineering', $engineeringId);
        $support = $this->makeDepartment('Support', $supportId);

        $alice = $this->makeEmployeeWithDept('Alice', 'EMP-1', $engineering, 1);
        $bob = $this->makeEmployeeWithDept('Bob', 'EMP-2', $engineering, 2);
        $charlie = $this->makeEmployeeWithDept('Charlie', 'EMP-3', $engineering, 3);
        $dora = $this->makeEmployeeWithDept('Dora', 'EMP-4', $support, 4);

        $aliceReg = new LeaveEntitlement($alice, 2026, LeaveEntitlementType::Regular, 240.0);
        $aliceReg->consume(80.0);
        $doraReg = new LeaveEntitlement($dora, 2026, LeaveEntitlementType::Regular, 240.0);
        $doraReg->consume(120.0);

        $this->employeeRepo->method('findAllByCompany')->willReturn([$alice, $bob, $charlie, $dora]);
        $this->entitlementRepo->method('findByCompanyAndYear')->willReturn([$aliceReg, $doraReg]);
        $this->entitlementRepo->method('findAvailableYears')->willReturn([2026]);
        $this->dayRepo->method('sumIllnessHoursByEmployeeForCompany')->willReturn([]);
        $this->dayRepo->method('sumApprovedDeductingHoursByMonth')->willReturn([]);
        $this->requestRepo->method('countAwaitingDecisionInCompany')->willReturn(0);
        $this->departmentRepo->method('findByCompany')->willReturn([$engineering, $support]);

        $service = $this->makeService(new MockClock('2026-12-15'));
        $snapshot = $service->buildDashboard($this->acme, 2026);

        self::assertCount(2, $snapshot->departmentBreakdown);

        $eng = $snapshot->departmentBreakdown[0];
        self::assertSame('Engineering', $eng->name);
        self::assertSame(3, $eng->employeeCount);
        self::assertFalse($eng->hidden);
        self::assertSame(240.0, $eng->totalGrantedHours);
        self::assertSame(80.0, $eng->totalUsedHours);

        $sup = $snapshot->departmentBreakdown[1];
        self::assertSame('Support', $sup->name);
        self::assertSame(1, $sup->employeeCount);
        self::assertTrue($sup->hidden);
        self::assertNull($sup->totalGrantedHours);
        self::assertNull($sup->utilizationPercent);
    }

    #[Test]
    public function orphanEmployeesAppearAsOhneAbteilungEntry(): void
    {
        $support = $this->makeDepartment('Support', 20);
        $alice = $this->makeEmployeeWithDept('Alice', 'EMP-1', $support, 1);
        $bob = $this->makeEmployeeWithDept('Bob', 'EMP-2', $support, 2);
        $charlie = $this->makeEmployeeWithDept('Charlie', 'EMP-3', $support, 3);
        $orphan1 = $this->makeEmployee('Orphan1', 'EMP-4', '2025-01-01', null, 4);
        $orphan2 = $this->makeEmployee('Orphan2', 'EMP-5', '2025-01-01', null, 5);
        $orphan3 = $this->makeEmployee('Orphan3', 'EMP-6', '2025-01-01', null, 6);

        $this->employeeRepo->method('findAllByCompany')->willReturn([$alice, $bob, $charlie, $orphan1, $orphan2, $orphan3]);
        $this->entitlementRepo->method('findByCompanyAndYear')->willReturn([]);
        $this->entitlementRepo->method('findAvailableYears')->willReturn([2026]);
        $this->dayRepo->method('sumIllnessHoursByEmployeeForCompany')->willReturn([]);
        $this->dayRepo->method('sumApprovedDeductingHoursByMonth')->willReturn([]);
        $this->requestRepo->method('countAwaitingDecisionInCompany')->willReturn(0);
        $this->departmentRepo->method('findByCompany')->willReturn([$support]);

        $service = $this->makeService(new MockClock('2026-12-15'));
        $snapshot = $service->buildDashboard($this->acme, 2026);

        self::assertCount(2, $snapshot->departmentBreakdown);
        self::assertSame('Support', $snapshot->departmentBreakdown[0]->name);
        self::assertSame('Ohne Abteilung', $snapshot->departmentBreakdown[1]->name);
        self::assertSame(3, $snapshot->departmentBreakdown[1]->employeeCount);
        self::assertFalse($snapshot->departmentBreakdown[1]->hidden);
    }

    #[Test]
    public function filtersOutEmployeesWhoLeftBeforeRangeStart(): void
    {
        $alice = $this->makeEmployee('Alice', 'EMP-1', '2024-01-01', null, 1);
        $bob = $this->makeEmployee('Bob', 'EMP-2', '2023-01-01', '2025-12-15', 2);

        $this->employeeRepo->method('findAllByCompany')->willReturn([$alice, $bob]);
        $this->entitlementRepo->method('findByCompanyAndYear')->willReturn([]);
        $this->entitlementRepo->method('findAvailableYears')->willReturn([2026]);
        $this->dayRepo->method('sumIllnessHoursByEmployeeForCompany')->willReturn([]);
        $this->dayRepo->method('sumApprovedDeductingHoursByMonth')->willReturn([]);
        $this->requestRepo->method('countAwaitingDecisionInCompany')->willReturn(0);
        $this->departmentRepo->method('findByCompany')->willReturn([]);

        $service = $this->makeService(new MockClock('2026-12-15'));
        $snapshot = $service->buildDashboard($this->acme, 2026);

        self::assertSame(1, $snapshot->activeEmployeeCount);
    }

    #[Test]
    public function injectsRequestedYearIntoAvailableYearsListIfMissing(): void
    {
        $this->stubEmptyRepos();
        $this->entitlementRepo = $this->createStub(LeaveEntitlementRepository::class);
        $this->entitlementRepo->method('findByCompanyAndYear')->willReturn([]);
        $this->entitlementRepo->method('findAvailableYears')->willReturn([2025]);

        $service = $this->makeService(new MockClock('2026-05-10'));
        $snapshot = $service->buildDashboard($this->acme, 2026);

        self::assertContains(2026, $snapshot->availableYears);
        self::assertSame([2026, 2025], $snapshot->availableYears);
    }

    private function stubEmptyRepos(): void
    {
        $this->employeeRepo->method('findAllByCompany')->willReturn([]);
        $this->entitlementRepo->method('findByCompanyAndYear')->willReturn([]);
        $this->entitlementRepo->method('findAvailableYears')->willReturn([2026]);
        $this->dayRepo->method('sumIllnessHoursByEmployeeForCompany')->willReturn([]);
        $this->dayRepo->method('sumApprovedDeductingHoursByMonth')->willReturn([]);
        $this->requestRepo->method('countAwaitingDecisionInCompany')->willReturn(0);
        $this->departmentRepo->method('findByCompany')->willReturn([]);
    }

    private function makeService(MockClock $clock): StatisticsService
    {
        return new StatisticsService(
            new UtilizationCalculator(),
            new IllnessRateCalculator(),
            $this->entitlementRepo,
            $this->requestRepo,
            $this->dayRepo,
            $this->employeeRepo,
            $this->departmentRepo,
            $clock,
        );
    }

    private function makeEmployee(string $name, string $number, string $joinedAt, ?string $leftAt, int $id): Employee
    {
        $emp = new Employee(
            $this->acme,
            $name,
            $number,
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable($joinedAt),
        );
        if (null !== $leftAt) {
            $emp->markLeft(new \DateTimeImmutable($leftAt));
        }
        $reflection = new \ReflectionProperty(Employee::class, 'id');
        $reflection->setValue($emp, $id);

        return $emp;
    }

    private function makeEmployeeWithDept(string $name, string $number, Department $dept, int $id): Employee
    {
        $emp = $this->makeEmployee($name, $number, '2025-01-01', null, $id);
        $emp->assignToDepartment($dept);

        return $emp;
    }

    private function makeDepartment(string $name, int $id): Department
    {
        $dept = new Department($this->acme, $name);
        $reflection = new \ReflectionProperty(Department::class, 'id');
        $reflection->setValue($dept, $id);

        return $dept;
    }
}
