<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestDay;
use App\Domain\Entity\Location;
use App\Domain\Enum\ExclusionReason;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeaveRequest::class)]
#[CoversClass(LeaveRequestDay::class)]
#[CoversClass(LeaveRequestStatus::class)]
final class LeaveRequestTest extends TestCase
{
    private Company $acme;
    private Employee $employee;
    private AbsenceType $urlaub;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $hq = new Location($this->acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->employee = new Employee(
            company: $this->acme,
            fullName: 'Jane Doe',
            employeeNumber: 'EMP-0001',
            location: $hq,
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
    // Construction
    // -----------------------------------------------------------------

    #[Test]
    public function storesCoreFields(): void
    {
        $start = new \DateTimeImmutable('2026-07-06');
        $end = new \DateTimeImmutable('2026-07-10');
        $requestedAt = new \DateTimeImmutable('2026-05-01 09:30:00');

        $request = new LeaveRequest(
            employee: $this->employee,
            absenceType: $this->urlaub,
            startDate: $start,
            endDate: $end,
            dayType: LeaveDayType::FullDay,
            requestedAt: $requestedAt,
        );

        self::assertSame($this->employee, $request->getEmployee());
        self::assertSame($this->urlaub, $request->getAbsenceType());
        self::assertSame('2026-07-06', $request->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-07-10', $request->getEndDate()->format('Y-m-d'));
        self::assertSame(LeaveDayType::FullDay, $request->getDayType());
        self::assertSame($requestedAt, $request->getRequestedAt());
    }

    #[Test]
    public function initialStatusIsPending(): void
    {
        $request = $this->buildSimpleRequest();

        self::assertSame(LeaveRequestStatus::Pending, $request->getStatus());
    }

    #[Test]
    public function initialTotalHoursIsZero(): void
    {
        $request = $this->buildSimpleRequest();

        self::assertSame(0.0, $request->getTotalHours());
    }

    #[Test]
    public function initialDaysCollectionIsEmpty(): void
    {
        $request = $this->buildSimpleRequest();

        self::assertCount(0, $request->getDays());
    }

    #[Test]
    public function startDateIsNormalizedToMidnight(): void
    {
        $start = new \DateTimeImmutable('2026-07-06 14:30:00');
        $request = new LeaveRequest(
            employee: $this->employee,
            absenceType: $this->urlaub,
            startDate: $start,
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:30:00'),
        );

        self::assertSame('00:00:00', $request->getStartDate()->format('H:i:s'));
    }

    // -----------------------------------------------------------------
    // Invariants
    // -----------------------------------------------------------------

    #[Test]
    public function endDateBeforeStartDateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LeaveRequest(
            employee: $this->employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-07-10'),
            endDate: new \DateTimeImmutable('2026-07-06'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );
    }

    #[Test]
    public function absenceTypeFromDifferentCompanyThrows(): void
    {
        $other = new Company('Other Inc');
        $foreignUrlaub = new AbsenceType(
            company: $other,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#000000',
        );

        $this->expectException(\InvalidArgumentException::class);

        new LeaveRequest(
            employee: $this->employee,
            absenceType: $foreignUrlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );
    }

    // -----------------------------------------------------------------
    // applyBreakdown — populates days + totalHours from calculator output
    // -----------------------------------------------------------------

    #[Test]
    public function applyBreakdownCopiesTotalHoursAndDays(): void
    {
        $request = $this->buildMondayToFridayRequest();

        $request->applyBreakdown($this->mondayToFridayFullTimeBreakdown());

        self::assertSame(40.0, $request->getTotalHours());
        self::assertCount(5, $request->getDays());
    }

    #[Test]
    public function applyBreakdownCopiesDateHoursStatusAndReason(): void
    {
        $request = $this->buildMondayToFridayRequest();
        $breakdown = new LeaveBreakdown([
            new LeaveDay(
                new \DateTimeImmutable('2026-07-06'),
                4.0,
                LeaveDayStatus::HalfDay,
            ),
            new LeaveDay(
                new \DateTimeImmutable('2026-07-07'),
                0.0,
                LeaveDayStatus::Excluded,
                ExclusionReason::Holiday,
            ),
        ]);
        // Narrow the request to match breakdown length.
        $narrow = new LeaveRequest(
            employee: $this->employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-07'),
            dayType: LeaveDayType::HalfDayAm,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );

        $narrow->applyBreakdown($breakdown);

        /** @var list<LeaveRequestDay> $days */
        $days = array_values(iterator_to_array($narrow->getDays()));

        self::assertSame('2026-07-06', $days[0]->getDate()->format('Y-m-d'));
        self::assertSame(4.0, $days[0]->getHours());
        self::assertSame(LeaveDayStatus::HalfDay, $days[0]->getStatus());
        self::assertNull($days[0]->getReason());

        self::assertSame('2026-07-07', $days[1]->getDate()->format('Y-m-d'));
        self::assertSame(0.0, $days[1]->getHours());
        self::assertSame(LeaveDayStatus::Excluded, $days[1]->getStatus());
        self::assertSame(ExclusionReason::Holiday, $days[1]->getReason());
    }

    #[Test]
    public function applyBreakdownIsIdempotent(): void
    {
        $request = $this->buildMondayToFridayRequest();
        $request->applyBreakdown($this->mondayToFridayFullTimeBreakdown());

        $request->applyBreakdown($this->mondayToFridayFullTimeBreakdown());

        self::assertSame(40.0, $request->getTotalHours());
        self::assertCount(5, $request->getDays(), 'previous days were replaced, not appended');
    }

    #[Test]
    public function applyBreakdownWithMismatchedRangeThrows(): void
    {
        // Request spans 5 days, breakdown only covers 3 => contract violation.
        $request = $this->buildMondayToFridayRequest();
        $shortBreakdown = new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2026-07-06'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-07'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-08'), 8.0, LeaveDayStatus::Working),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $request->applyBreakdown($shortBreakdown);
    }

    #[Test]
    public function applyBreakdownWithDatesOutsideRangeThrows(): void
    {
        $request = $this->buildMondayToFridayRequest();
        // First day shifted by one — same count, wrong dates.
        $misaligned = new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2026-07-05'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-06'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-07'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-08'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-09'), 8.0, LeaveDayStatus::Working),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $request->applyBreakdown($misaligned);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function buildSimpleRequest(): LeaveRequest
    {
        return new LeaveRequest(
            employee: $this->employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:30:00'),
        );
    }

    private function buildMondayToFridayRequest(): LeaveRequest
    {
        // 2026-07-06 is a Monday, 2026-07-10 is a Friday.
        return new LeaveRequest(
            employee: $this->employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );
    }

    private function mondayToFridayFullTimeBreakdown(): LeaveBreakdown
    {
        return new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2026-07-06'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-07'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-08'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-09'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2026-07-10'), 8.0, LeaveDayStatus::Working),
        ]);
    }
}
