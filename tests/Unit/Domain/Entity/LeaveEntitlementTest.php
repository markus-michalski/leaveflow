<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeaveEntitlement::class)]
final class LeaveEntitlementTest extends TestCase
{
    private Employee $employee;

    protected function setUp(): void
    {
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
    public function storesCoreFields(): void
    {
        $entitlement = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Regular,
            240.0,
        );

        self::assertSame($this->employee, $entitlement->getEmployee());
        self::assertSame(2026, $entitlement->getYear());
        self::assertSame(LeaveEntitlementType::Regular, $entitlement->getType());
        self::assertSame(240.0, $entitlement->getHoursGranted());
        self::assertSame(0.0, $entitlement->getHoursUsed());
        self::assertSame(240.0, $entitlement->getHoursRemaining());
        self::assertNull($entitlement->getExpiresAt());
    }

    #[Test]
    public function storesOptionalExpiresAt(): void
    {
        $expires = new \DateTimeImmutable('2027-03-31');
        $entitlement = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            40.0,
            $expires,
        );

        self::assertSame('2027-03-31', $entitlement->getExpiresAt()?->format('Y-m-d'));
    }

    #[Test]
    public function normalizesExpiresAtToMidnight(): void
    {
        $expires = new \DateTimeImmutable('2027-03-31 14:37:00');
        $entitlement = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            40.0,
            $expires,
        );

        self::assertSame('00:00:00', $entitlement->getExpiresAt()?->format('H:i:s'));
    }

    #[Test]
    #[DataProvider('invalidYearProvider')]
    public function rejectsYearOutOfSaneRange(int $year): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('year');

        new LeaveEntitlement($this->employee, $year, LeaveEntitlementType::Regular, 240.0);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function invalidYearProvider(): iterable
    {
        yield 'before 1970' => [1969];
        yield 'after 2200' => [2201];
    }

    #[Test]
    public function rejectsNegativeHoursGranted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hoursGranted');

        new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, -1.0);
    }

    #[Test]
    public function consumeDeductsFromRemaining(): void
    {
        $entitlement = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 240.0);

        $entitlement->consume(40.0);

        self::assertSame(40.0, $entitlement->getHoursUsed());
        self::assertSame(200.0, $entitlement->getHoursRemaining());
    }

    #[Test]
    public function consumeMayExactlyDrainBalance(): void
    {
        $entitlement = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 40.0);

        $entitlement->consume(40.0);

        self::assertSame(0.0, $entitlement->getHoursRemaining());
    }

    #[Test]
    public function consumeRejectsNegativeAmount(): void
    {
        $entitlement = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 40.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hours');

        $entitlement->consume(-1.0);
    }

    #[Test]
    public function consumeRejectsZeroAmountAsNoop(): void
    {
        $entitlement = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 40.0);

        $entitlement->consume(0.0);

        self::assertSame(0.0, $entitlement->getHoursUsed());
    }

    #[Test]
    public function consumeRejectsOverdraft(): void
    {
        $entitlement = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 40.0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('exceed');

        $entitlement->consume(40.01);
    }

    #[Test]
    public function isExpiredFalseWhenNoExpiryDateSet(): void
    {
        $entitlement = new LeaveEntitlement($this->employee, 2026, LeaveEntitlementType::Regular, 240.0);

        self::assertFalse($entitlement->isExpiredOn(new \DateTimeImmutable('2099-12-31')));
    }

    #[Test]
    public function isExpiredFalseBeforeExpiryDate(): void
    {
        $entitlement = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            40.0,
            new \DateTimeImmutable('2027-03-31'),
        );

        self::assertFalse($entitlement->isExpiredOn(new \DateTimeImmutable('2027-03-31')));
        self::assertFalse($entitlement->isExpiredOn(new \DateTimeImmutable('2027-03-30')));
    }

    #[Test]
    public function isExpiredTrueAfterExpiryDate(): void
    {
        $entitlement = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            40.0,
            new \DateTimeImmutable('2027-03-31'),
        );

        self::assertTrue($entitlement->isExpiredOn(new \DateTimeImmutable('2027-04-01')));
    }

    #[Test]
    public function adjustExpiresAtMovesDate(): void
    {
        $entitlement = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            40.0,
            new \DateTimeImmutable('2027-03-31'),
        );

        $entitlement->adjustExpiresAt(new \DateTimeImmutable('2027-12-31'));

        self::assertSame('2027-12-31', $entitlement->getExpiresAt()?->format('Y-m-d'));
    }

    #[Test]
    public function adjustExpiresAtAcceptsNullToClear(): void
    {
        $entitlement = new LeaveEntitlement(
            $this->employee,
            2026,
            LeaveEntitlementType::Carryover,
            40.0,
            new \DateTimeImmutable('2027-03-31'),
        );

        $entitlement->adjustExpiresAt(null);

        self::assertNull($entitlement->getExpiresAt());
    }
}
