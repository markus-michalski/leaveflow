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

namespace App\Tests\Unit\Application\Entitlement;

use App\Application\Entitlement\YearTransitionService;
use App\Application\Entitlement\YearTransitionStatus;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(YearTransitionService::class)]
#[AllowMockObjectsWithoutExpectations]
final class YearTransitionServiceTest extends TestCase
{
    private LeaveEntitlementRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private YearTransitionService $service;

    private Company $acme;
    private Location $hq;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(LeaveEntitlementRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new YearTransitionService($this->repository, $this->entityManager);

        $this->acme = new Company('Acme GmbH');
        $this->hq = new Location($this->acme, 'HQ', 'DE', 'DE-BY', 'München');
    }

    #[Test]
    public function createsCarryoverFromRemainingRegularBalance(): void
    {
        $jane = $this->employee('Jane Doe', 'EMP-0001');
        $regular = new LeaveEntitlement($jane, 2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(200.0);

        $this->repository->expects(self::once())
            ->method('findBy')
            ->with(['year' => 2026, 'type' => LeaveEntitlementType::Regular])
            ->willReturn([$regular]);
        $this->repository->method('findOneByEmployeeYearAndType')->willReturn(null);

        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $e) use (&$persisted): void {
                $persisted[] = $e;
            },
        );
        $this->entityManager->expects(self::once())->method('flush');

        $report = $this->service->transition(2026);

        self::assertCount(1, $report);
        self::assertSame(YearTransitionStatus::Created, $report[0]->status);
        self::assertSame(40.0, $report[0]->hoursCarriedOver);
        self::assertSame($jane, $report[0]->employee);
        self::assertCount(1, $persisted);
        self::assertInstanceOf(LeaveEntitlement::class, $persisted[0]);
        self::assertSame(2027, $persisted[0]->getYear());
        self::assertSame(LeaveEntitlementType::Carryover, $persisted[0]->getType());
        self::assertSame('2027-03-31', $persisted[0]->getExpiresAt()?->format('Y-m-d'));
    }

    #[Test]
    public function skipsEmployeeWithZeroRemaining(): void
    {
        $jane = $this->employee('Jane Doe', 'EMP-0001');
        $regular = new LeaveEntitlement($jane, 2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(240.0);

        $this->repository->method('findBy')->willReturn([$regular]);
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $report = $this->service->transition(2026);

        self::assertCount(1, $report);
        self::assertSame(YearTransitionStatus::SkippedEmptyBalance, $report[0]->status);
        self::assertSame(0.0, $report[0]->hoursCarriedOver);
    }

    #[Test]
    public function skipsEmployeeWhenCarryoverAlreadyExistsForTargetYear(): void
    {
        $jane = $this->employee('Jane Doe', 'EMP-0001');
        $regular = new LeaveEntitlement($jane, 2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(200.0);
        $existingCarryover = new LeaveEntitlement(
            $jane,
            2027,
            LeaveEntitlementType::Carryover,
            20.0,
            new \DateTimeImmutable('2027-03-31'),
        );

        $this->repository->method('findBy')->willReturn([$regular]);
        $this->repository->method('findOneByEmployeeYearAndType')
            ->with($jane, 2027, LeaveEntitlementType::Carryover)
            ->willReturn($existingCarryover);

        $this->entityManager->expects(self::never())->method('persist');

        $report = $this->service->transition(2026);

        self::assertCount(1, $report);
        self::assertSame(YearTransitionStatus::SkippedAlreadyExists, $report[0]->status);
    }

    #[Test]
    public function dryRunDoesNotPersistOrFlush(): void
    {
        $jane = $this->employee('Jane Doe', 'EMP-0001');
        $regular = new LeaveEntitlement($jane, 2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(200.0);

        $this->repository->method('findBy')->willReturn([$regular]);
        $this->repository->method('findOneByEmployeeYearAndType')->willReturn(null);

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $report = $this->service->transition(2026, dryRun: true);

        self::assertCount(1, $report);
        self::assertSame(YearTransitionStatus::Created, $report[0]->status);
        self::assertSame(40.0, $report[0]->hoursCarriedOver);
    }

    #[Test]
    public function processesMultipleEmployees(): void
    {
        $jane = $this->employee('Jane Doe', 'EMP-0001');
        $janeRegular = new LeaveEntitlement($jane, 2026, LeaveEntitlementType::Regular, 240.0);
        $janeRegular->consume(200.0);

        $john = $this->employee('John Doe', 'EMP-0002');
        $johnRegular = new LeaveEntitlement($john, 2026, LeaveEntitlementType::Regular, 240.0);
        $johnRegular->consume(240.0);

        $this->repository->method('findBy')->willReturn([$janeRegular, $johnRegular]);
        $this->repository->method('findOneByEmployeeYearAndType')->willReturn(null);

        $report = $this->service->transition(2026);

        self::assertCount(2, $report);
        $byName = [];
        foreach ($report as $entry) {
            $byName[$entry->employee->getFullName()] = $entry;
        }
        self::assertSame(YearTransitionStatus::Created, $byName['Jane Doe']->status);
        self::assertSame(YearTransitionStatus::SkippedEmptyBalance, $byName['John Doe']->status);
    }

    #[Test]
    public function skipsEmployeeWhoLeftBeforeTargetYear(): void
    {
        $jane = $this->employeeWithLeaveDate('Jane Doe', 'EMP-0001', new \DateTimeImmutable('2026-11-30'));
        $regular = new LeaveEntitlement($jane, 2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(200.0);

        $this->repository->method('findBy')->willReturn([$regular]);

        $this->entityManager->expects(self::never())->method('persist');

        $report = $this->service->transition(2026);

        self::assertCount(1, $report);
        self::assertSame(YearTransitionStatus::SkippedEmptyBalance, $report[0]->status);
        self::assertSame(0.0, $report[0]->hoursCarriedOver);
    }

    #[Test]
    public function usesMarch31OfTargetYearAsExpiryByDefault(): void
    {
        $jane = $this->employee('Jane Doe', 'EMP-0001');
        $regular = new LeaveEntitlement($jane, 2030, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(180.0);

        $this->repository->method('findBy')->willReturn([$regular]);
        $this->repository->method('findOneByEmployeeYearAndType')->willReturn(null);

        $persisted = null;
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $e) use (&$persisted): void {
                $persisted = $e;
            },
        );

        $this->service->transition(2030);

        self::assertInstanceOf(LeaveEntitlement::class, $persisted);
        self::assertSame('2031-03-31', $persisted->getExpiresAt()?->format('Y-m-d'));
    }

    #[Test]
    public function emptyInputProducesEmptyReport(): void
    {
        $this->repository->method('findBy')->willReturn([]);
        $this->entityManager->expects(self::once())->method('flush');

        $report = $this->service->transition(2026);

        self::assertSame([], $report);
    }

    private function employee(string $name, string $number): Employee
    {
        return new Employee(
            $this->acme,
            $name,
            $number,
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
        );
    }

    private function employeeWithLeaveDate(string $name, string $number, \DateTimeImmutable $leftAt): Employee
    {
        return new Employee(
            $this->acme,
            $name,
            $number,
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
            null,
            $leftAt,
        );
    }
}
