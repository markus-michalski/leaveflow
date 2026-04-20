<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Entitlement;

use App\Application\Entitlement\EntitlementBalanceReader;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntitlementBalanceReader::class)]
final class EntitlementBalanceReaderTest extends TestCase
{
    private LeaveEntitlementRepository&Stub $repository;
    private EntitlementBalanceReader $reader;
    private Employee $employee;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(LeaveEntitlementRepository::class);
        $this->reader = new EntitlementBalanceReader($this->repository);

        $acme = new Company('Acme GmbH');
        $hq = new Location($acme, 'HQ', 'DE', 'DE-BY', 'München');
        $this->employee = new Employee(
            $acme,
            'Jane Doe',
            'EMP-0001',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2025-01-01'),
        );
    }

    #[Test]
    public function returnsZeroBalanceWhenNoEntitlementsExist(): void
    {
        $this->repository->method('findByEmployeeAndYear')->willReturn([]);

        $snapshot = $this->reader->forEmployee($this->employee, 2026, new \DateTimeImmutable('2026-06-01'));

        self::assertSame(0.0, $snapshot->regularRemaining);
        self::assertSame(0.0, $snapshot->carryoverRemaining);
        self::assertNull($snapshot->nextExpiry);
        self::assertSame(0.0, $snapshot->totalRemaining());
    }

    #[Test]
    public function splitsRegularAndCarryoverHours(): void
    {
        $regular = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(40.0);
        $carryover = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->repository->method('findByEmployeeAndYear')->willReturn([$regular, $carryover]);

        $snapshot = $this->reader->forEmployee($this->employee, 2026, new \DateTimeImmutable('2026-02-01'));

        self::assertSame(200.0, $snapshot->regularRemaining);
        self::assertSame(16.0, $snapshot->carryoverRemaining);
        self::assertSame(216.0, $snapshot->totalRemaining());
    }

    #[Test]
    public function ignoresExpiredCarryoverInAggregate(): void
    {
        $regular = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 240.0);
        $expiredCarryover = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->repository->method('findByEmployeeAndYear')->willReturn([$regular, $expiredCarryover]);

        $snapshot = $this->reader->forEmployee($this->employee, 2026, new \DateTimeImmutable('2026-04-01'));

        self::assertSame(240.0, $snapshot->regularRemaining);
        self::assertSame(0.0, $snapshot->carryoverRemaining);
    }

    #[Test]
    public function nextExpiryReturnsEarliestUpcomingCarryoverExpiry(): void
    {
        $carryoverEarly = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            10.0,
            new \DateTimeImmutable('2026-03-31'),
        );
        $regular = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 240.0);

        $this->repository->method('findByEmployeeAndYear')->willReturn([$regular, $carryoverEarly]);

        $snapshot = $this->reader->forEmployee($this->employee, 2026, new \DateTimeImmutable('2026-02-15'));

        self::assertSame('2026-03-31', $snapshot->nextExpiry?->format('Y-m-d'));
    }

    #[Test]
    public function nextExpiryIgnoresDrainedCarryover(): void
    {
        $drained = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            10.0,
            new \DateTimeImmutable('2026-03-31'),
        );
        $drained->consume(10.0);

        $this->repository->method('findByEmployeeAndYear')->willReturn([$drained]);

        $snapshot = $this->reader->forEmployee($this->employee, 2026, new \DateTimeImmutable('2026-02-15'));

        self::assertNull($snapshot->nextExpiry);
    }

    #[Test]
    public function nextExpiryNullWhenOnlyRegularEntitlementPresent(): void
    {
        $regular = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 240.0);

        $this->repository->method('findByEmployeeAndYear')->willReturn([$regular]);

        $snapshot = $this->reader->forEmployee($this->employee, 2026, new \DateTimeImmutable('2026-02-15'));

        self::assertNull($snapshot->nextExpiry);
    }
}
