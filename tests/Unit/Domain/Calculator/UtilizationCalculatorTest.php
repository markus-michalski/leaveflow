<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Calculator;

use App\Domain\Calculator\UtilizationCalculator;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\ValueObject\UtilizationBreakdown;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UtilizationCalculator::class)]
#[CoversClass(UtilizationBreakdown::class)]
final class UtilizationCalculatorTest extends TestCase
{
    private UtilizationCalculator $calculator;
    private Employee $alice;
    private Employee $bob;

    protected function setUp(): void
    {
        $this->calculator = new UtilizationCalculator();

        $acme = new Company('Acme GmbH');
        $hq = new Location($acme, 'HQ', 'DE', 'DE-BY', 'München');
        $this->alice = new Employee(
            $acme,
            'Alice',
            'EMP-0001',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2025-01-01'),
        );
        $this->bob = new Employee(
            $acme,
            'Bob',
            'EMP-0002',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2025-01-01'),
        );
    }

    #[Test]
    public function returnsZeroBreakdownForEmptyList(): void
    {
        $breakdown = $this->calculator->calculate([], new \DateTimeImmutable('2026-06-01'));

        self::assertSame(0.0, $breakdown->totalGrantedHours);
        self::assertSame(0.0, $breakdown->totalUsedHours);
        self::assertSame(0.0, $breakdown->totalRemainingHours);
        self::assertSame(0.0, $breakdown->utilizationPercent);
    }

    #[Test]
    public function aggregatesGrantedAndUsedAcrossTypesAndEmployees(): void
    {
        $aliceRegular = new LeaveEntitlement($this->alice, 2026, LeaveEntitlementType::Regular, 240.0);
        $aliceRegular->consume(80.0);

        $aliceCarryover = new LeaveEntitlement(
            $this->alice,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-03-31'),
        );

        $bobRegular = new LeaveEntitlement($this->bob, 2026, LeaveEntitlementType::Regular, 200.0);
        $bobRegular->consume(40.0);

        $breakdown = $this->calculator->calculate(
            [$aliceRegular, $aliceCarryover, $bobRegular],
            new \DateTimeImmutable('2026-02-01'),
        );

        self::assertSame(456.0, $breakdown->totalGrantedHours);
        self::assertSame(120.0, $breakdown->totalUsedHours);
        self::assertSame(336.0, $breakdown->totalRemainingHours);
        // 120 / 456 * 100 = 26.31578... → rounded to one decimal
        self::assertSame(26.3, $breakdown->utilizationPercent);
    }

    #[Test]
    public function excludesExpiredCarryoverFromGrantedAndRemaining(): void
    {
        $regular = new LeaveEntitlement($this->alice, 2026, LeaveEntitlementType::Regular, 240.0);
        $expired = new LeaveEntitlement(
            $this->alice,
            2026,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2026-03-31'),
        );

        $breakdown = $this->calculator->calculate(
            [$regular, $expired],
            new \DateTimeImmutable('2026-04-01'),
        );

        self::assertSame(240.0, $breakdown->totalGrantedHours);
        self::assertSame(0.0, $breakdown->totalUsedHours);
        self::assertSame(240.0, $breakdown->totalRemainingHours);
    }

    #[Test]
    public function utilizationPercentIsZeroWhenNothingGranted(): void
    {
        $breakdown = $this->calculator->calculate([], new \DateTimeImmutable('2026-06-01'));

        self::assertSame(0.0, $breakdown->utilizationPercent);
    }

    #[Test]
    public function fullUtilizationYields100Percent(): void
    {
        $regular = new LeaveEntitlement($this->alice, 2026, LeaveEntitlementType::Regular, 100.0);
        $regular->consume(100.0);

        $breakdown = $this->calculator->calculate([$regular], new \DateTimeImmutable('2026-06-01'));

        self::assertSame(100.0, $breakdown->utilizationPercent);
        self::assertSame(0.0, $breakdown->totalRemainingHours);
    }
}
