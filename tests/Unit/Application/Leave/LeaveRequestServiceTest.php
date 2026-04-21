<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Leave;

use App\Application\Holiday\HolidayService;
use App\Application\Leave\LeaveRequestService;
use App\Domain\Calculator\HolidayCalculator;
use App\Domain\Calculator\LeaveCalculator;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Repository\CompanyHolidayRepository;
use App\Domain\Repository\HolidayOverrideRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(LeaveRequestService::class)]
#[AllowMockObjectsWithoutExpectations]
final class LeaveRequestServiceTest extends TestCase
{
    private HolidayOverrideRepository&MockObject $overrideRepository;
    private CompanyHolidayRepository&MockObject $companyHolidayRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private MockClock $clock;
    private Company $acme;
    private Employee $employee;
    private AbsenceType $urlaub;

    protected function setUp(): void
    {
        $this->overrideRepository = $this->createMock(HolidayOverrideRepository::class);
        $this->companyHolidayRepository = $this->createMock(CompanyHolidayRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->clock = new MockClock('2026-04-21 10:00:00');

        $this->acme = new Company('Acme GmbH');
        $location = new Location($this->acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->employee = new Employee(
            company: $this->acme,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-0001',
            location: $location,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->urlaub = new AbsenceType(
            company: $this->acme,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
    }

    // -----------------------------------------------------------------
    // preview()
    // -----------------------------------------------------------------

    #[Test]
    public function previewFullWorkWeekWithoutHolidaysReturnsFortyHours(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        $service = $this->buildService();

        // Week 03.02.2025 (Mon) .. 07.02.2025 (Fri) — Berlin, no holidays in that window.
        $breakdown = $service->preview(
            $this->employee,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
        );

        self::assertSame(40.0, $breakdown->totalHours());
        self::assertCount(5, $breakdown->workingDays());
        self::assertCount(0, $breakdown->excludedDays());
    }

    #[Test]
    public function previewRangeCoveringChristiHimmelfahrtExcludesTheHoliday(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        $service = $this->buildService();

        // Week 26.05.2025 (Mon) .. 30.05.2025 (Fri), Thu 29.05 = Christi Himmelfahrt.
        $breakdown = $service->preview(
            $this->employee,
            new \DateTimeImmutable('2025-05-26'),
            new \DateTimeImmutable('2025-05-30'),
            LeaveDayType::FullDay,
        );

        self::assertSame(32.0, $breakdown->totalHours());
        self::assertCount(4, $breakdown->workingDays());
        self::assertCount(1, $breakdown->excludedDays());
        self::assertSame('2025-05-29', $breakdown->excludedDays()[0]->date->format('Y-m-d'));
    }

    #[Test]
    public function previewYearBoundaryRangeQueriesHolidaysForBothYears(): void
    {
        $this->overrideRepository
            ->expects(self::exactly(2))
            ->method('findByCompanyYearAndState')
            ->willReturnCallback(function (Company $c, int $year, FederalState $state): array {
                self::assertSame($this->acme, $c);
                self::assertSame(FederalState::Berlin, $state);
                self::assertContains($year, [2025, 2026]);

                return [];
            });
        $this->companyHolidayRepository
            ->expects(self::exactly(2))
            ->method('findByCompanyAndYear')
            ->willReturn([]);

        $service = $this->buildService();

        // Mon 29.12.2025 .. Fri 02.01.2026, Thu 01.01.2026 = Neujahr.
        $breakdown = $service->preview(
            $this->employee,
            new \DateTimeImmutable('2025-12-29'),
            new \DateTimeImmutable('2026-01-02'),
            LeaveDayType::FullDay,
        );

        self::assertSame(32.0, $breakdown->totalHours());
        self::assertCount(1, $breakdown->excludedDays());
        self::assertSame('2026-01-01', $breakdown->excludedDays()[0]->date->format('Y-m-d'));
    }

    // -----------------------------------------------------------------
    // create()
    // -----------------------------------------------------------------

    #[Test]
    public function createPersistsPendingRequestWithBreakdownSnapshot(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(LeaveRequest::class));
        $this->entityManager->expects(self::once())->method('flush');

        $service = $this->buildService();

        $request = $service->create(
            $this->employee,
            $this->urlaub,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
        );

        self::assertSame(LeaveRequestStatus::Pending, $request->getStatus());
        self::assertSame(40.0, $request->getTotalHours());
        self::assertCount(5, $request->getDays());
        self::assertSame($this->employee, $request->getEmployee());
        self::assertSame($this->urlaub, $request->getAbsenceType());
        self::assertSame(LeaveDayType::FullDay, $request->getDayType());
    }

    #[Test]
    public function createStampsRequestedAtFromClock(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        $service = $this->buildService();

        $request = $service->create(
            $this->employee,
            $this->urlaub,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
        );

        self::assertSame('2026-04-21 10:00:00', $request->getRequestedAt()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function createRejectsAbsenceTypeFromDifferentCompany(): void
    {
        $other = new Company('Other Inc');
        $foreignUrlaub = new AbsenceType(
            company: $other,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#000000',
        );

        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $service = $this->buildService();

        $this->expectException(\InvalidArgumentException::class);

        $service->create(
            $this->employee,
            $foreignUrlaub,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function buildService(): LeaveRequestService
    {
        $holidayService = new HolidayService(
            new HolidayCalculator(),
            $this->overrideRepository,
            $this->companyHolidayRepository,
        );

        return new LeaveRequestService(
            $holidayService,
            new LeaveCalculator(),
            $this->entityManager,
            $this->clock,
        );
    }
}
