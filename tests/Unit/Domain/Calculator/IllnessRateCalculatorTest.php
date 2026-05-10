<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Calculator;

use App\Domain\Calculator\IllnessRateCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\IllnessRateBreakdown;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IllnessRateCalculator::class)]
#[CoversClass(IllnessRateBreakdown::class)]
final class IllnessRateCalculatorTest extends TestCase
{
    private IllnessRateCalculator $calculator;
    private Company $acme;
    private Location $hq;

    protected function setUp(): void
    {
        $this->calculator = new IllnessRateCalculator();
        $this->acme = new Company('Acme GmbH');
        $this->hq = new Location($this->acme, 'HQ', 'DE', 'DE-BY', 'München');
    }

    #[Test]
    public function returnsZeroBreakdownForEmptyEmployees(): void
    {
        $breakdown = $this->calculator->calculate(
            [],
            [],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertSame(0.0, $breakdown->totalIllnessHours);
        self::assertSame(0.0, $breakdown->totalScheduledHours);
        self::assertSame(0.0, $breakdown->illnessRatePercent);
    }

    #[Test]
    public function calculatesScheduledHoursOverFullYearForFullTimeEmployee(): void
    {
        // 2026 has 261 working days (Mon-Fri), full-time = 8h/day → 2088h
        $alice = $this->makeEmployee('Alice', 'EMP-1', WorkSchedule::standardFullTime(), '2025-01-01');

        $breakdown = $this->calculator->calculate(
            [$alice],
            [],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertSame(2088.0, $breakdown->totalScheduledHours);
        self::assertSame(0.0, $breakdown->totalIllnessHours);
        self::assertSame(0.0, $breakdown->illnessRatePercent);
    }

    #[Test]
    public function illnessHoursAreLookedUpByEmployeeId(): void
    {
        $alice = $this->makeEmployeeWithId('Alice', 'EMP-1', WorkSchedule::standardFullTime(), '2025-01-01', 42);

        $breakdown = $this->calculator->calculate(
            [$alice],
            [42 => 16.0],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertSame(16.0, $breakdown->totalIllnessHours);
        self::assertSame(2088.0, $breakdown->totalScheduledHours);
        // 16 / 2088 * 100 = 0.7662... → 0.8
        self::assertSame(0.8, $breakdown->illnessRatePercent);
    }

    #[Test]
    public function ignoresIllnessHoursForEmployeesNotInList(): void
    {
        $alice = $this->makeEmployeeWithId('Alice', 'EMP-1', WorkSchedule::standardFullTime(), '2025-01-01', 42);

        // Employee 99 is in the illness map but not in the employee list
        $breakdown = $this->calculator->calculate(
            [$alice],
            [42 => 8.0, 99 => 16.0],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertSame(8.0, $breakdown->totalIllnessHours);
    }

    #[Test]
    public function proratesScheduledHoursForLateJoiner(): void
    {
        // Joins on 2026-07-01 (Wed). Working days from 2026-07-01 to 2026-12-31 = 132 → 1056h
        $alice = $this->makeEmployee('Alice', 'EMP-1', WorkSchedule::standardFullTime(), '2026-07-01');

        $breakdown = $this->calculator->calculate(
            [$alice],
            [],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertSame(1056.0, $breakdown->totalScheduledHours);
    }

    #[Test]
    public function proratesScheduledHoursForLeavingEmployee(): void
    {
        // Joined long ago, leaves 2026-06-30. Working days 2026-01-01..2026-06-30 = 129 → 1032h
        $alice = $this->makeEmployee('Alice', 'EMP-1', WorkSchedule::standardFullTime(), '2024-01-01');
        $alice->markLeft(new \DateTimeImmutable('2026-06-30'));

        $breakdown = $this->calculator->calculate(
            [$alice],
            [],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertSame(1032.0, $breakdown->totalScheduledHours);
    }

    #[Test]
    public function rangeEndCapsScheduleWhenYearIsCurrent(): void
    {
        $alice = $this->makeEmployee('Alice', 'EMP-1', WorkSchedule::standardFullTime(), '2024-01-01');

        // Current year 2026, range cut to 2026-05-10 (the day this is being written).
        // Working days 2026-01-01..2026-05-10 = 92 → 736h
        $breakdown = $this->calculator->calculate(
            [$alice],
            [],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-05-10'),
        );

        self::assertSame(736.0, $breakdown->totalScheduledHours);
    }

    #[Test]
    public function partTimeEmployeeUsesTheirOwnWorkSchedule(): void
    {
        // 4 days/week, 32h, only Mon-Thu working days
        $partTime = WorkSchedule::autoDistribute(32.0, [
            Weekday::Monday,
            Weekday::Tuesday,
            Weekday::Wednesday,
            Weekday::Thursday,
        ]);
        $alice = $this->makeEmployee('Alice', 'EMP-1', $partTime, '2024-01-01');

        // 2026 has 209 Mon-Thu days (262 working - 53 Fridays). Each = 8h. Total: 1672h
        $breakdown = $this->calculator->calculate(
            [$alice],
            [],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertSame(1672.0, $breakdown->totalScheduledHours);
    }

    #[Test]
    public function illnessRateRoundedToOneDecimal(): void
    {
        $alice = $this->makeEmployeeWithId('Alice', 'EMP-1', WorkSchedule::standardFullTime(), '2025-01-01', 42);

        // 100h illness / 2088h scheduled = 4.7892... → 4.8
        $breakdown = $this->calculator->calculate(
            [$alice],
            [42 => 100.0],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertSame(4.8, $breakdown->illnessRatePercent);
    }

    private function makeEmployee(string $name, string $number, WorkSchedule $schedule, string $joinedAt): Employee
    {
        return new Employee(
            $this->acme,
            $name,
            $number,
            $this->hq,
            $schedule,
            new \DateTimeImmutable($joinedAt),
        );
    }

    private function makeEmployeeWithId(string $name, string $number, WorkSchedule $schedule, string $joinedAt, int $id): Employee
    {
        $employee = $this->makeEmployee($name, $number, $schedule, $joinedAt);
        // Set private $id via reflection so the calculator can key by id without
        // requiring a persisted entity in unit tests.
        $reflection = new \ReflectionProperty(Employee::class, 'id');
        $reflection->setValue($employee, $id);

        return $employee;
    }
}
