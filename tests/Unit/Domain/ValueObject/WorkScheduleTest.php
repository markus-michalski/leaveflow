<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkSchedule::class)]
final class WorkScheduleTest extends TestCase
{
    #[Test]
    public function autoDistributeStandardFullTimeFortyHoursMondayToFriday(): void
    {
        $schedule = WorkSchedule::autoDistribute(
            40.0,
            [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday],
        );

        self::assertSame(40.0, $schedule->weeklyHours());
        self::assertSame(8.0, $schedule->hoursForDay(Weekday::Monday));
        self::assertSame(8.0, $schedule->hoursForDay(Weekday::Friday));
        self::assertSame(0.0, $schedule->hoursForDay(Weekday::Saturday));
        self::assertSame(0.0, $schedule->hoursForDay(Weekday::Sunday));
    }

    #[Test]
    public function autoDistributePartTimeTwentyHoursMondayToFriday(): void
    {
        $schedule = WorkSchedule::autoDistribute(
            20.0,
            [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday],
        );

        foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
            self::assertSame(4.0, $schedule->hoursForDay($day), 'Failed on '.$day->name);
        }
    }

    #[Test]
    public function autoDistributeThirtyHoursAcrossFourDaysIsSevenPointFive(): void
    {
        $schedule = WorkSchedule::autoDistribute(
            30.0,
            [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Friday],
        );

        self::assertSame(7.5, $schedule->hoursForDay(Weekday::Monday));
        self::assertSame(7.5, $schedule->hoursForDay(Weekday::Friday));
        self::assertSame(0.0, $schedule->hoursForDay(Weekday::Thursday));
    }

    #[Test]
    public function manualDistributionAcceptsUnevenHours(): void
    {
        $schedule = WorkSchedule::manual(32.0, [
            Weekday::Monday->value => 10.0,
            Weekday::Tuesday->value => 6.0,
            Weekday::Wednesday->value => 10.0,
            Weekday::Friday->value => 6.0,
        ]);

        self::assertSame(32.0, $schedule->weeklyHours());
        self::assertSame(10.0, $schedule->hoursForDay(Weekday::Monday));
        self::assertSame(6.0, $schedule->hoursForDay(Weekday::Friday));
        self::assertTrue($schedule->isWorkingDay(Weekday::Monday));
        self::assertFalse($schedule->isWorkingDay(Weekday::Thursday));
    }

    #[Test]
    public function manualDistributionRejectsSumMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match weekly hours');

        WorkSchedule::manual(40.0, [
            Weekday::Monday->value => 8.0,
            Weekday::Tuesday->value => 8.0,
        ]);
    }

    #[Test]
    public function manualDistributionAllowsFloatingPointDrift(): void
    {
        // 0.1 + 0.2 = 0.30000000000000004 territory — epsilon must tolerate this
        $schedule = WorkSchedule::manual(0.3, [
            Weekday::Monday->value => 0.1,
            Weekday::Tuesday->value => 0.2,
        ]);

        self::assertSame(0.3, $schedule->weeklyHours());
    }

    #[Test]
    public function autoDistributionRejectsEmptyWorkingDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one working day');

        WorkSchedule::autoDistribute(40.0, []);
    }

    #[Test]
    public function autoDistributionRejectsDuplicateWorkingDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate');

        WorkSchedule::autoDistribute(40.0, [Weekday::Monday, Weekday::Monday]);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideInvalidWeeklyHours(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-8.0];
    }

    #[Test]
    #[DataProvider('provideInvalidWeeklyHours')]
    public function rejectsNonPositiveWeeklyHours(float $hours): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than zero');

        WorkSchedule::autoDistribute($hours, [Weekday::Monday]);
    }

    #[Test]
    public function manualDistributionRejectsNegativeHoursPerDay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('negative');

        WorkSchedule::manual(8.0, [
            Weekday::Monday->value => 10.0,
            Weekday::Tuesday->value => -2.0,
        ]);
    }

    #[Test]
    public function manualDistributionRejectsInvalidWeekdayKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid weekday');

        WorkSchedule::manual(8.0, [
            42 => 8.0,
        ]);
    }

    #[Test]
    public function manualDistributionSkipsZeroHoursAsNonWorkingDay(): void
    {
        $schedule = WorkSchedule::manual(16.0, [
            Weekday::Monday->value => 8.0,
            Weekday::Wednesday->value => 0.0,
            Weekday::Friday->value => 8.0,
        ]);

        self::assertTrue($schedule->isWorkingDay(Weekday::Monday));
        self::assertFalse($schedule->isWorkingDay(Weekday::Wednesday));
        self::assertFalse($schedule->isWorkingDay(Weekday::Tuesday));
    }

    #[Test]
    public function standardFullTimeFactoryIsFortyHoursMondayToFridayEightPerDay(): void
    {
        $schedule = WorkSchedule::standardFullTime();

        self::assertSame(40.0, $schedule->weeklyHours());
        self::assertSame(8.0, $schedule->hoursForDay(Weekday::Monday));
        self::assertSame(8.0, $schedule->hoursForDay(Weekday::Friday));
        self::assertFalse($schedule->isWorkingDay(Weekday::Saturday));
        self::assertFalse($schedule->isWorkingDay(Weekday::Sunday));
    }

    #[Test]
    public function workingDaysReturnsOrderedListOfWeekdayEnums(): void
    {
        $schedule = WorkSchedule::autoDistribute(
            24.0,
            [Weekday::Friday, Weekday::Monday, Weekday::Wednesday],
        );

        self::assertSame(
            [Weekday::Monday, Weekday::Wednesday, Weekday::Friday],
            $schedule->workingDays(),
        );
    }

    /**
     * @return iterable<string, array{WorkSchedule, WorkSchedule, bool}>
     */
    public static function provideEqualityPairs(): iterable
    {
        $full = WorkSchedule::standardFullTime();
        $same = WorkSchedule::autoDistribute(
            40.0,
            [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday],
        );
        $half = WorkSchedule::autoDistribute(20.0, [Weekday::Monday, Weekday::Tuesday]);

        yield 'same schedules equal' => [$full, $same, true];
        yield 'different hours not equal' => [$full, $half, false];
    }

    #[Test]
    #[DataProvider('provideEqualityPairs')]
    public function equalsComparesHoursAndDistribution(WorkSchedule $a, WorkSchedule $b, bool $expected): void
    {
        self::assertSame($expected, $a->equals($b));
    }
}
