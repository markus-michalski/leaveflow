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

namespace App\Tests\Unit\Domain\Calculator;

use App\Domain\Calculator\LeaveCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Enum\ExclusionReason;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\Holiday;
use App\Domain\ValueObject\HolidayScope;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeaveCalculator::class)]
#[CoversClass(LeaveBreakdown::class)]
#[CoversClass(LeaveDay::class)]
#[CoversClass(LeaveDayType::class)]
#[CoversClass(LeaveDayStatus::class)]
#[CoversClass(ExclusionReason::class)]
final class LeaveCalculatorTest extends TestCase
{
    private LeaveCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new LeaveCalculator();
    }

    // -----------------------------------------------------------------
    // Core scenarios from the roadmap
    // -----------------------------------------------------------------

    #[Test]
    public function fullTimeSixWeeksWithoutHolidaysConsumesTwoHundredFortyHours(): void
    {
        // 03.02.2025 (Mon) .. 14.03.2025 (Fri) — 6 weeks of Mon-Fri work
        // in Berlin where this window contains no public holidays.
        $employee = $this->fullTimeEmployee();
        $breakdown = $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-03-14'),
            LeaveDayType::FullDay,
            holidays: [],
        );

        self::assertSame(240.0, $breakdown->totalHours());
        self::assertCount(30, $breakdown->workingDays());
        self::assertCount(10, $breakdown->excludedDays(), '5 Saturdays + 5 Sundays');
        foreach ($breakdown->excludedDays() as $day) {
            self::assertSame(ExclusionReason::Weekend, $day->reason);
            self::assertSame(0.0, $day->hours);
        }
    }

    #[Test]
    public function partTimeTwentyFourHoursFourWeeksConsumesNinetySixHours(): void
    {
        // Part-time Mon/Wed/Fri, 8h each. Range: 03.02.2025 (Mon) .. 02.03.2025 (Sun).
        // Four full Mon-Sun weeks => 12 working days (Mon/Wed/Fri) * 8h = 96h.
        // 8 Tue/Thu = NonWorkingDay, 8 Sat/Sun = Weekend.
        $employee = $this->partTimeMonWedFriEmployee();
        $breakdown = $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-03-02'),
            LeaveDayType::FullDay,
            holidays: [],
        );

        self::assertSame(96.0, $breakdown->totalHours());
        self::assertCount(12, $breakdown->workingDays());

        $nonWorking = array_filter(
            $breakdown->excludedDays(),
            static fn (LeaveDay $d): bool => ExclusionReason::NonWorkingDay === $d->reason,
        );
        $weekend = array_filter(
            $breakdown->excludedDays(),
            static fn (LeaveDay $d): bool => ExclusionReason::Weekend === $d->reason,
        );
        self::assertCount(8, $nonWorking, 'Tuesdays + Thursdays');
        self::assertCount(8, $weekend, 'Saturdays + Sundays');
    }

    #[Test]
    public function bridgeDayHolidayInRangeIsExcluded(): void
    {
        // Christi Himmelfahrt 2025 = Thu 29.05. Range Mon 26.05 .. Fri 30.05.
        $employee = $this->fullTimeEmployee();
        $breakdown = $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-05-26'),
            new \DateTimeImmutable('2025-05-30'),
            LeaveDayType::FullDay,
            holidays: [
                new Holiday(
                    new \DateTimeImmutable('2025-05-29'),
                    'holiday.christi_himmelfahrt',
                    HolidayScope::National,
                ),
            ],
        );

        self::assertSame(32.0, $breakdown->totalHours());
        self::assertCount(4, $breakdown->workingDays());
        self::assertCount(1, $breakdown->excludedDays());
        self::assertSame(ExclusionReason::Holiday, $breakdown->excludedDays()[0]->reason);
        self::assertSame('2025-05-29', $breakdown->excludedDays()[0]->date->format('Y-m-d'));
    }

    // -----------------------------------------------------------------
    // Half-day semantics (rule (a): only single-day or start/end boundary)
    // -----------------------------------------------------------------

    #[Test]
    public function halfDaySingleDayFullTimeIsFourHours(): void
    {
        $employee = $this->fullTimeEmployee();
        $monday = new \DateTimeImmutable('2025-02-03');
        $breakdown = $this->calculator->calculate($employee, $monday, $monday, LeaveDayType::HalfDayAm, []);

        self::assertSame(4.0, $breakdown->totalHours());
        self::assertCount(1, $breakdown->days);
        self::assertSame(LeaveDayStatus::HalfDay, $breakdown->days[0]->status);
    }

    #[Test]
    public function halfDaySingleDayWithPmIsAlsoFourHours(): void
    {
        // For single-day, Am and Pm mean the same: that one day is half.
        $employee = $this->fullTimeEmployee();
        $monday = new \DateTimeImmutable('2025-02-03');
        $breakdown = $this->calculator->calculate($employee, $monday, $monday, LeaveDayType::HalfDayPm, []);

        self::assertSame(4.0, $breakdown->totalHours());
        self::assertSame(LeaveDayStatus::HalfDay, $breakdown->days[0]->status);
    }

    #[Test]
    public function halfDayOnMultiDayRangeThrows(): void
    {
        // Rule C: half-day day-types are valid only for single-day ranges.
        // Mix-scenarios (Mon half + Wed full + Fri half) must be submitted
        // as separate requests.
        $employee = $this->fullTimeEmployee();

        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::HalfDayAm,
            holidays: [],
        );
    }

    #[Test]
    public function halfDayPmOnMultiDayRangeAlsoThrows(): void
    {
        $employee = $this->fullTimeEmployee();

        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::HalfDayPm,
            holidays: [],
        );
    }

    // -----------------------------------------------------------------
    // Calendar boundaries
    // -----------------------------------------------------------------

    #[Test]
    public function weekendsInsideRangeAreAlwaysExcluded(): void
    {
        // Fri 07.02 .. Mon 10.02.2025.
        $employee = $this->fullTimeEmployee();
        $breakdown = $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-02-07'),
            new \DateTimeImmutable('2025-02-10'),
            LeaveDayType::FullDay,
            holidays: [],
        );

        self::assertSame(16.0, $breakdown->totalHours());
        self::assertCount(2, $breakdown->workingDays());
        self::assertCount(2, $breakdown->excludedDays());

        foreach ($breakdown->excludedDays() as $day) {
            self::assertSame(ExclusionReason::Weekend, $day->reason);
        }
    }

    #[Test]
    public function yearBoundarySpanRequiresCallerToSupplyHolidaysFromBothYears(): void
    {
        // Mon 29.12.2025 .. Fri 02.01.2026. Thu 01.01.2026 = Neujahr.
        $employee = $this->fullTimeEmployee();
        $breakdown = $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-12-29'),
            new \DateTimeImmutable('2026-01-02'),
            LeaveDayType::FullDay,
            holidays: [
                new Holiday(
                    new \DateTimeImmutable('2026-01-01'),
                    'holiday.neujahr',
                    HolidayScope::National,
                ),
            ],
        );

        self::assertSame(32.0, $breakdown->totalHours(), '4 working days * 8h');
        self::assertCount(4, $breakdown->workingDays());
        self::assertCount(1, $breakdown->excludedDays());
        self::assertSame(ExclusionReason::Holiday, $breakdown->excludedDays()[0]->reason);
    }

    #[Test]
    public function singleDayFullTimeWorkingDayIsEightHours(): void
    {
        $employee = $this->fullTimeEmployee();
        $wednesday = new \DateTimeImmutable('2025-02-05');
        $breakdown = $this->calculator->calculate($employee, $wednesday, $wednesday, LeaveDayType::FullDay, []);

        self::assertSame(8.0, $breakdown->totalHours());
        self::assertCount(1, $breakdown->days);
        self::assertSame(LeaveDayStatus::Working, $breakdown->days[0]->status);
    }

    // -----------------------------------------------------------------
    // Employee active window
    // -----------------------------------------------------------------

    #[Test]
    public function daysAfterEmployeeLeftDateAreExcluded(): void
    {
        // leftAt 2025-02-05 (Wed). Range Mon 03.02 .. Fri 07.02.
        // Mon/Tue/Wed working, Thu/Fri excluded/EmployeeInactive.
        $employee = $this->fullTimeEmployeeWithLeftAt(new \DateTimeImmutable('2025-02-05'));
        $breakdown = $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
            holidays: [],
        );

        self::assertSame(24.0, $breakdown->totalHours());
        self::assertCount(3, $breakdown->workingDays());

        $inactive = array_filter(
            $breakdown->excludedDays(),
            static fn (LeaveDay $d): bool => ExclusionReason::EmployeeInactive === $d->reason,
        );
        self::assertCount(2, $inactive);
    }

    #[Test]
    public function daysBeforeEmployeeJoinedAreExcluded(): void
    {
        // joinedAt 2025-02-05 (Wed). Range Mon 03.02 .. Fri 07.02.
        // Mon/Tue excluded/EmployeeInactive, Wed/Thu/Fri working.
        $employee = $this->fullTimeEmployeeWithJoinedAt(new \DateTimeImmutable('2025-02-05'));
        $breakdown = $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-02-03'),
            new \DateTimeImmutable('2025-02-07'),
            LeaveDayType::FullDay,
            holidays: [],
        );

        self::assertSame(24.0, $breakdown->totalHours());
        self::assertCount(3, $breakdown->workingDays());

        $inactive = array_filter(
            $breakdown->excludedDays(),
            static fn (LeaveDay $d): bool => ExclusionReason::EmployeeInactive === $d->reason,
        );
        self::assertCount(2, $inactive);
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    #[Test]
    public function endDateBeforeStartDateThrows(): void
    {
        $employee = $this->fullTimeEmployee();
        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate(
            $employee,
            new \DateTimeImmutable('2025-02-10'),
            new \DateTimeImmutable('2025-02-03'),
            LeaveDayType::FullDay,
            holidays: [],
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function fullTimeEmployee(): Employee
    {
        return $this->buildEmployee(
            schedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            leftAt: null,
        );
    }

    private function fullTimeEmployeeWithJoinedAt(\DateTimeImmutable $joinedAt): Employee
    {
        return $this->buildEmployee(
            schedule: WorkSchedule::standardFullTime(),
            joinedAt: $joinedAt,
            leftAt: null,
        );
    }

    private function fullTimeEmployeeWithLeftAt(\DateTimeImmutable $leftAt): Employee
    {
        return $this->buildEmployee(
            schedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            leftAt: $leftAt,
        );
    }

    private function partTimeMonWedFriEmployee(): Employee
    {
        return $this->buildEmployee(
            schedule: WorkSchedule::autoDistribute(24.0, [Weekday::Monday, Weekday::Wednesday, Weekday::Friday]),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            leftAt: null,
        );
    }

    private function buildEmployee(
        WorkSchedule $schedule,
        \DateTimeImmutable $joinedAt,
        ?\DateTimeImmutable $leftAt,
    ): Employee {
        $company = new Company('Acme GmbH');
        $location = new Location($company, 'HQ', 'DE', 'DE-BE', 'Berlin');

        return new Employee(
            company: $company,
            fullName: 'Max Mustermann',
            employeeNumber: 'EMP-001',
            location: $location,
            workSchedule: $schedule,
            joinedAt: $joinedAt,
            user: null,
            leftAt: $leftAt,
        );
    }
}
