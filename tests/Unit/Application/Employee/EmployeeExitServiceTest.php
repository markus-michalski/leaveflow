<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Employee;

use App\Application\Employee\EmployeeExitService;
use App\Application\Employee\ExitSummary;
use App\Application\Entitlement\EntitlementBalanceReader;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\ExitLeaveHandling;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(EmployeeExitService::class)]
#[CoversClass(ExitSummary::class)]
final class EmployeeExitServiceTest extends TestCase
{
    private Company $company;
    private Employee $employee;
    private EmployeeExitService $service;
    /** @var Stub&LeaveEntitlementRepository */
    private Stub $repository;

    protected function setUp(): void
    {
        $this->company = new Company('Acme GmbH');
        $hq = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->employee = new Employee(
            $this->company,
            'Hannah Doe',
            'EMP-001',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2023-01-01'),
        );

        $this->repository = $this->createStub(LeaveEntitlementRepository::class);
        // Clock fixed to 2025-07-16 so tests can distinguish past (2025-07-15),
        // today (2025-07-16), and future (2026-01-01) exit dates.
        $this->service = new EmployeeExitService(
            new EntitlementBalanceReader($this->repository),
            new MockClock(new \DateTimeImmutable('2025-07-16')),
        );
    }

    #[Test]
    public function executeSetsLeftAtOnEmployee(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);
        $exitDate = new \DateTimeImmutable('2025-07-15');

        $this->service->execute($this->employee, $exitDate);

        self::assertEquals($exitDate, $this->employee->getLeftAt());
    }

    #[Test]
    public function executeDeactivatesLinkedUser(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);
        $user = new User($this->company, 'hannah@example.com', UserRole::Employee);
        $this->employee->linkUser($user);
        self::assertTrue($user->isActive());

        $this->service->execute($this->employee, new \DateTimeImmutable('2025-07-15'));

        self::assertFalse($user->isActive());
    }

    #[Test]
    public function executeWithNoLinkedUserDoesNotThrow(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);
        self::assertNull($this->employee->getUser());

        $summary = $this->service->execute($this->employee, new \DateTimeImmutable('2025-07-15'));

        self::assertFalse($summary->userDeactivated);
    }

    #[Test]
    public function executeReturnsZeroRemainingWhenNoEntitlements(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);

        $summary = $this->service->execute($this->employee, new \DateTimeImmutable('2025-07-15'));

        self::assertEqualsWithDelta(0.0, $summary->totalRemainingHours, 0.01);
    }

    #[Test]
    public function executeSumsRemainingHoursAcrossCurrentAndPriorYear(): void
    {
        $exitDate = new \DateTimeImmutable('2025-07-15');
        $currentYearEntitlement = $this->makeRegularEntitlement(2025, 240.0, 80.0);
        $carryoverEntitlement = $this->makeCarryoverEntitlement(2025, 20.0, 0.0, new \DateTimeImmutable('2025-03-31'));

        $this->repository
            ->method('findByEmployeeAndYear')
            ->willReturnCallback(static function (Employee $_emp, int $year) use ($currentYearEntitlement, $carryoverEntitlement): array {
                return match ($year) {
                    2025 => [$currentYearEntitlement, $carryoverEntitlement],
                    default => [],
                };
            });

        $summary = $this->service->execute($this->employee, $exitDate);

        // Regular 240-80=160, carryover expired by Jul 15 (expiresAt=Mar 31) → 0
        self::assertEqualsWithDelta(160.0, $summary->totalRemainingHours, 0.01);
    }

    #[Test]
    public function executeReturnsCompanyExitHandlingInSummary(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);
        $this->company->setExitLeaveHandling(ExitLeaveHandling::MandatoryConsumption);

        $summary = $this->service->execute($this->employee, new \DateTimeImmutable('2025-07-15'));

        self::assertSame(ExitLeaveHandling::MandatoryConsumption, $summary->exitLeaveHandling);
    }

    #[Test]
    public function executeReturnsExitDateInSummary(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);
        $exitDate = new \DateTimeImmutable('2025-09-30');

        $summary = $this->service->execute($this->employee, $exitDate);

        self::assertEquals($exitDate, $summary->exitDate);
    }

    #[Test]
    public function executeMarksTrueWhenUserDeactivated(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);
        $user = new User($this->company, 'hannah@example.com', UserRole::Employee);
        $this->employee->linkUser($user);

        $summary = $this->service->execute($this->employee, new \DateTimeImmutable('2025-07-15'));

        self::assertTrue($summary->userDeactivated);
    }

    #[Test]
    public function executeDeactivatesUserWhenExitDateIsToday(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);
        $user = new User($this->company, 'hannah@example.com', UserRole::Employee);
        $this->employee->linkUser($user);

        $summary = $this->service->execute($this->employee, new \DateTimeImmutable('2025-07-16'));

        self::assertFalse($user->isActive());
        self::assertTrue($summary->userDeactivated);
    }

    #[Test]
    public function executeDoesNotDeactivateUserForFutureExitDate(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);
        $user = new User($this->company, 'hannah@example.com', UserRole::Employee);
        $this->employee->linkUser($user);

        $summary = $this->service->execute($this->employee, new \DateTimeImmutable('2026-01-01'));

        self::assertTrue($user->isActive(), 'User must stay active until the exit date arrives');
        self::assertFalse($summary->userDeactivated);
    }

    private function makeRegularEntitlement(int $year, float $granted, float $used): LeaveEntitlement
    {
        $entry = new LeaveEntitlement($this->employee, $year, LeaveEntitlementType::Regular, $granted);
        if ($used > 0) {
            $entry->consume($used);
        }

        return $entry;
    }

    private function makeCarryoverEntitlement(int $year, float $granted, float $used, \DateTimeImmutable $expiresAt): LeaveEntitlement
    {
        $entry = new LeaveEntitlement($this->employee, $year, LeaveEntitlementType::Carryover, $granted, $expiresAt);
        if ($used > 0) {
            $entry->consume($used);
        }

        return $entry;
    }
}
