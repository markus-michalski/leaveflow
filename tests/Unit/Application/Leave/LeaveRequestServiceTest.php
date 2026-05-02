<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Leave;

use App\Application\Approval\ApproverResolverInterface;
use App\Application\Calendar\BlackoutPeriodChecker;
use App\Application\Calendar\BlackoutPeriodViolationException;
use App\Application\Entitlement\EntitlementBalanceReader;
use App\Application\Holiday\HolidayService;
use App\Application\Leave\BackdatedLeaveRequestException;
use App\Application\Leave\InsufficientLeaveBalanceException;
use App\Application\Leave\LeaveRequestService;
use App\Application\Leave\MultiDayHalfDayException;
use App\Application\Leave\NoEntitlementForYearException;
use App\Application\Notification\NotificationDispatcherInterface;
use App\Domain\Calculator\HolidayCalculator;
use App\Domain\Calculator\LeaveCalculator;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\BlackoutPeriod;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\FederalState;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Repository\BlackoutPeriodRepository;
use App\Domain\Repository\CompanyHolidayRepository;
use App\Domain\Repository\HolidayOverrideRepository;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\Repository\LeaveRequestDayRepository;
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
    private LeaveEntitlementRepository&MockObject $entitlementRepository;
    private LeaveRequestDayRepository&MockObject $dayRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private BlackoutPeriodRepository&MockObject $blackoutRepository;
    private NotificationDispatcherInterface&MockObject $notificationDispatcher;
    private ApproverResolverInterface&MockObject $approverResolver;
    /** @var list<BlackoutPeriod> */
    private array $overlappingBlackouts = [];
    private MockClock $clock;
    private Company $acme;
    private Employee $employee;
    private AbsenceType $urlaub;
    private AbsenceType $krankheit;

    protected function setUp(): void
    {
        $this->overrideRepository = $this->createMock(HolidayOverrideRepository::class);
        $this->companyHolidayRepository = $this->createMock(CompanyHolidayRepository::class);
        $this->entitlementRepository = $this->createMock(LeaveEntitlementRepository::class);
        $this->dayRepository = $this->createMock(LeaveRequestDayRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->blackoutRepository = $this->createMock(BlackoutPeriodRepository::class);
        $this->notificationDispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $this->approverResolver = $this->createMock(ApproverResolverInterface::class);
        $this->overlappingBlackouts = [];
        // Tests can populate $this->overlappingBlackouts to simulate a hit.
        $this->blackoutRepository->method('findOverlapping')
            ->willReturnCallback(fn (): array => $this->overlappingBlackouts);
        // Pin the clock before any of the fixture dates (2025+) so the
        // backdated-request guard treats them as future.
        $this->clock = new MockClock('2024-01-01 10:00:00');

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
        $this->krankheit = new AbsenceType(
            company: $this->acme,
            name: 'Krankheit',
            deductsFromLeave: false,
            requiresApproval: false,
            color: '#EF4444',
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
        $this->stubAmpleBalance();

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
        $this->stubAmpleBalance();

        $service = $this->buildService();

        $request = $service->create(
            $this->employee,
            $this->urlaub,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
        );

        self::assertSame('2024-01-01 10:00:00', $request->getRequestedAt()->format('Y-m-d H:i:s'));
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
        $this->stubAmpleBalance();

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
    // Balance check (deducting absence types only)
    // -----------------------------------------------------------------

    #[Test]
    public function createRejectsWhenBalanceIsInsufficient(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        // Only 24h remaining for 2025, request asks for 40h (5 working days * 8h).
        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([
            new LeaveEntitlement($this->employee, 2025, LeaveEntitlementType::Regular, 24.0),
        ]);
        $this->dayRepository->method('sumPendingHoursByYear')->willReturn([]);

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $service = $this->buildService();

        try {
            $service->create(
                $this->employee,
                $this->urlaub,
                new \DateTimeImmutable('2025-02-03'),
                new \DateTimeImmutable('2025-02-07'),
                LeaveDayType::FullDay,
            );
            self::fail('Expected InsufficientLeaveBalanceException');
        } catch (InsufficientLeaveBalanceException $e) {
            self::assertSame(2025, $e->year);
            self::assertSame(40.0, $e->requestedHours);
            self::assertSame(24.0, $e->availableHours);
        }
    }

    #[Test]
    public function createCountsExistingPendingRequestsAgainstBalance(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        // 200h granted, 160h already pending from other requests, new request wants 40h:
        // 200 - 160 = 40 available, but new request asks 40h exactly — passes only by
        // being <= available. Push pending to 161h to break it.
        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([
            new LeaveEntitlement($this->employee, 2025, LeaveEntitlementType::Regular, 200.0),
        ]);
        $this->dayRepository->method('sumPendingHoursByYear')->willReturn([2025 => 161.0]);

        $service = $this->buildService();

        $this->expectException(InsufficientLeaveBalanceException::class);

        $service->create(
            $this->employee,
            $this->urlaub,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
        );
    }

    #[Test]
    public function createAllowsRequestWhenBalanceExactlyCovers(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        // 40h granted, no pending. Request asks 40h. Allowed.
        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([
            new LeaveEntitlement($this->employee, 2025, LeaveEntitlementType::Regular, 40.0),
        ]);
        $this->dayRepository->method('sumPendingHoursByYear')->willReturn([]);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $service = $this->buildService();

        $request = $service->create(
            $this->employee,
            $this->urlaub,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
        );

        self::assertSame(40.0, $request->getTotalHours());
    }

    #[Test]
    public function createSkipsBalanceCheckForNonDeductingAbsenceType(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        // No entitlement at all, but Krankheit doesn't deduct — must pass.
        $this->entitlementRepository
            ->expects(self::never())
            ->method('findByEmployeeAndYear');
        $this->dayRepository
            ->expects(self::never())
            ->method('sumPendingHoursByYear');

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $service = $this->buildService();

        $request = $service->create(
            $this->employee,
            $this->krankheit,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
        );

        self::assertSame(40.0, $request->getTotalHours());
    }

    #[Test]
    public function createChecksBalanceSeparatelyForEachYearInRange(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        // Range 29.12.2025 .. 02.01.2026. Thu 01.01.2026 = Neujahr = excluded.
        // So 2025 needs Mon+Tue+Wed = 24h, 2026 needs Fri = 8h.
        // Balance: 2025 has plenty (100h), 2026 has only 4h -> should fail on 2026.
        $this->entitlementRepository
            ->method('findByEmployeeAndYear')
            ->willReturnCallback(function ($employee, int $year): array {
                if (2025 === $year) {
                    return [new LeaveEntitlement($this->employee, 2025, LeaveEntitlementType::Regular, 100.0)];
                }

                return [new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 4.0)];
            });
        $this->dayRepository->method('sumPendingHoursByYear')->willReturn([]);

        $service = $this->buildService();

        try {
            $service->create(
                $this->employee,
                $this->urlaub,
                new \DateTimeImmutable('2025-12-29'),
                new \DateTimeImmutable('2026-01-02'),
                LeaveDayType::FullDay,
            );
            self::fail('Expected InsufficientLeaveBalanceException for 2026');
        } catch (InsufficientLeaveBalanceException $e) {
            self::assertSame(2026, $e->year);
            self::assertSame(8.0, $e->requestedHours);
            self::assertSame(4.0, $e->availableHours);
        }
    }

    // -----------------------------------------------------------------
    // Backdating guard + missing entitlement guard
    // -----------------------------------------------------------------

    #[Test]
    public function previewRejectsHalfDayOnMultiDayRange(): void
    {
        $service = $this->buildService();

        $this->expectException(MultiDayHalfDayException::class);

        $service->preview(
            $this->employee,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::HalfDayAm,
        );
    }

    #[Test]
    public function createRejectsHalfDayOnMultiDayRange(): void
    {
        $this->entityManager->expects(self::never())->method('persist');

        $service = $this->buildService();

        $this->expectException(MultiDayHalfDayException::class);

        $service->create(
            $this->employee,
            $this->urlaub,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::HalfDayPm,
        );
    }

    #[Test]
    public function createRejectsBackdatedRequest(): void
    {
        // Clock stands on 2024-01-01; asking for leave in 2023 is backdated.
        $this->entityManager->expects(self::never())->method('persist');

        $service = $this->buildService();

        $this->expectException(BackdatedLeaveRequestException::class);

        $service->create(
            $this->employee,
            $this->urlaub,
            new \DateTimeImmutable('2023-12-04'),
            new \DateTimeImmutable('2023-12-08'),
            LeaveDayType::FullDay,
        );
    }

    #[Test]
    public function previewRejectsBackdatedRange(): void
    {
        // Clock pinned at 2024-01-01. 2023 is yesterday.
        $service = $this->buildService();

        $this->expectException(BackdatedLeaveRequestException::class);

        $service->preview(
            $this->employee,
            new \DateTimeImmutable('2023-12-04'),
            new \DateTimeImmutable('2023-12-08'),
            LeaveDayType::FullDay,
        );
    }

    #[Test]
    public function previewPropagatesBlackoutPeriodViolation(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);
        $this->stubOverlappingBlackout();

        $service = $this->buildService();

        $this->expectException(BlackoutPeriodViolationException::class);

        $service->preview(
            $this->employee,
            new \DateTimeImmutable('2026-12-23'),
            new \DateTimeImmutable('2026-12-31'),
            LeaveDayType::FullDay,
        );
    }

    #[Test]
    public function createDoesNotPersistWhenBlackoutCheckerThrows(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);
        $this->stubOverlappingBlackout();

        // The blackout fires inside preview() — long before persist.
        $this->entityManager->expects(self::never())->method('persist');

        $service = $this->buildService();

        $this->expectException(BlackoutPeriodViolationException::class);

        $service->create(
            $this->employee,
            $this->urlaub,
            new \DateTimeImmutable('2026-12-23'),
            new \DateTimeImmutable('2026-12-31'),
            LeaveDayType::FullDay,
        );
    }

    #[Test]
    public function createRejectsYearWithoutAnyEntitlementForDeductingType(): void
    {
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        // No entitlement row at all for the target year.
        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn([]);
        $this->dayRepository->method('sumPendingHoursByYear')->willReturn([]);

        $this->entityManager->expects(self::never())->method('persist');

        $service = $this->buildService();

        try {
            $service->create(
                $this->employee,
                $this->urlaub,
                new \DateTimeImmutable('2025-02-03'),
                new \DateTimeImmutable('2025-02-07'),
                LeaveDayType::FullDay,
            );
            self::fail('Expected NoEntitlementForYearException');
        } catch (NoEntitlementForYearException $e) {
            self::assertSame(2025, $e->year);
        }
    }

    #[Test]
    public function createAllowsYearWithoutEntitlementForNonDeductingType(): void
    {
        // Krankheit: no entitlement needed, should pass.
        $this->overrideRepository->method('findByCompanyYearAndState')->willReturn([]);
        $this->companyHolidayRepository->method('findByCompanyAndYear')->willReturn([]);

        $this->entitlementRepository->expects(self::never())->method('findByEmployeeAndYear');
        $this->dayRepository->expects(self::never())->method('sumPendingHoursByYear');

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $service = $this->buildService();

        $request = $service->create(
            $this->employee,
            $this->krankheit,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-03'),
            LeaveDayType::HalfDayAm,
        );

        self::assertSame(4.0, $request->getTotalHours());
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

        $balanceReader = new EntitlementBalanceReader($this->entitlementRepository);

        return new LeaveRequestService(
            $holidayService,
            new LeaveCalculator(),
            $balanceReader,
            $this->entitlementRepository,
            $this->dayRepository,
            $this->entityManager,
            $this->clock,
            new BlackoutPeriodChecker($this->blackoutRepository),
            $this->notificationDispatcher,
            $this->approverResolver,
        );
    }

    /**
     * Stub the entitlement + pending-hours lookups with enough headroom that
     * the balance check always passes. Used by tests that are not about the
     * balance check itself.
     *
     * @param list<LeaveEntitlement> $entitlements
     */
    private function stubAmpleBalance(array $entitlements = []): void
    {
        if ([] === $entitlements) {
            $entitlements = [
                new LeaveEntitlement($this->employee, 2025, LeaveEntitlementType::Regular, 10000.0),
                new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 10000.0),
            ];
        }
        $this->entitlementRepository->method('findByEmployeeAndYear')->willReturn($entitlements);
        $this->dayRepository->method('sumPendingHoursByYear')->willReturn([]);
    }

    private function stubOverlappingBlackout(): void
    {
        $this->overlappingBlackouts = [
            new BlackoutPeriod(
                company: $this->acme,
                startDate: new \DateTimeImmutable('2026-12-23'),
                endDate: new \DateTimeImmutable('2026-12-31'),
                reason: 'Werksferien',
            ),
        ];
    }
}
