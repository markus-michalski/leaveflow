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

use App\Application\Entitlement\EntitlementConsumer;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntitlementConsumer::class)]
final class EntitlementConsumerTest extends TestCase
{
    private EntitlementConsumer $consumer;
    private Employee $employee;

    protected function setUp(): void
    {
        $this->consumer = new EntitlementConsumer();

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
    public function consumesFromSingleRegularEntitlement(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);

        $this->consumer->consume([$regular], 40.0, new \DateTimeImmutable('2026-06-01'));

        self::assertSame(40.0, $regular->getHoursUsed());
        self::assertSame(200.0, $regular->getHoursRemaining());
    }

    #[Test]
    public function prefersCarryoverOverRegular(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $carryover = $this->entitlement(
            2025,
            LeaveEntitlementType::Carryover,
            40.0,
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->consumer->consume([$regular, $carryover], 30.0, new \DateTimeImmutable('2026-02-01'));

        self::assertSame(30.0, $carryover->getHoursUsed());
        self::assertSame(0.0, $regular->getHoursUsed());
    }

    #[Test]
    public function spillsOverToRegularWhenCarryoverExhausted(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $carryover = $this->entitlement(
            2025,
            LeaveEntitlementType::Carryover,
            10.0,
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->consumer->consume([$regular, $carryover], 30.0, new \DateTimeImmutable('2026-02-01'));

        self::assertSame(10.0, $carryover->getHoursUsed());
        self::assertSame(20.0, $regular->getHoursUsed());
    }

    #[Test]
    public function consumesEarliestExpiringCarryoverFirst(): void
    {
        $laterCarryover = $this->entitlement(
            2025,
            LeaveEntitlementType::Carryover,
            20.0,
            new \DateTimeImmutable('2026-06-30'),
        );
        // Two Carryover entries would violate the unique (employee, year, type) constraint,
        // but PHP-side the consumer is fed a plain list and must order by expiry regardless.
        $earlierCarryover = new LeaveEntitlement(
            $this->employee,
            2024,
            LeaveEntitlementType::Carryover,
            20.0,
            new \DateTimeImmutable('2025-03-31'),
        );

        $this->consumer->consume(
            [$laterCarryover, $earlierCarryover],
            15.0,
            new \DateTimeImmutable('2025-01-15'),
        );

        self::assertSame(15.0, $earlierCarryover->getHoursUsed());
        self::assertSame(0.0, $laterCarryover->getHoursUsed());
    }

    #[Test]
    public function skipsExpiredEntitlement(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 40.0);
        $expiredCarryover = $this->entitlement(
            2025,
            LeaveEntitlementType::Carryover,
            20.0,
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->consumer->consume([$regular, $expiredCarryover], 10.0, new \DateTimeImmutable('2026-04-01'));

        self::assertSame(0.0, $expiredCarryover->getHoursUsed());
        self::assertSame(10.0, $regular->getHoursUsed());
    }

    #[Test]
    public function throwsWhenCombinedBalanceInsufficient(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 8.0);
        $carryover = $this->entitlement(
            2025,
            LeaveEntitlementType::Carryover,
            8.0,
            new \DateTimeImmutable('2026-03-31'),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Insufficient');

        $this->consumer->consume([$regular, $carryover], 20.0, new \DateTimeImmutable('2026-02-01'));
    }

    #[Test]
    public function doesNotMutateEntitlementsWhenPreflightFails(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 8.0);

        try {
            $this->consumer->consume([$regular], 20.0, new \DateTimeImmutable('2026-02-01'));
        } catch (\DomainException) {
        }

        self::assertSame(0.0, $regular->getHoursUsed(), 'Preflight check must prevent partial consumption.');
    }

    #[Test]
    public function zeroHoursIsNoop(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);

        $this->consumer->consume([$regular], 0.0, new \DateTimeImmutable('2026-02-01'));

        self::assertSame(0.0, $regular->getHoursUsed());
    }

    #[Test]
    public function rejectsNegativeHours(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);

        $this->expectException(\InvalidArgumentException::class);

        $this->consumer->consume([$regular], -1.0, new \DateTimeImmutable('2026-02-01'));
    }

    #[Test]
    public function throwsWhenNoEntitlementsProvided(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Insufficient');

        $this->consumer->consume([], 5.0, new \DateTimeImmutable('2026-02-01'));
    }

    #[Test]
    public function exactMatchDrainsEntitlement(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 40.0);

        $this->consumer->consume([$regular], 40.0, new \DateTimeImmutable('2026-06-01'));

        self::assertSame(0.0, $regular->getHoursRemaining());
    }

    #[Test]
    public function skipsFullyConsumedEntitlement(): void
    {
        $drained = $this->entitlement(2026, LeaveEntitlementType::Regular, 40.0);
        $drained->consume(40.0);
        $fresh = $this->entitlement(2027, LeaveEntitlementType::Regular, 40.0);

        $this->consumer->consume([$drained, $fresh], 10.0, new \DateTimeImmutable('2026-06-01'));

        self::assertSame(10.0, $fresh->getHoursUsed());
    }

    #[Test]
    public function releaseReturnsHoursToSingleEntitlement(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(40.0);

        $this->consumer->release([$regular], 16.0);

        self::assertSame(24.0, $regular->getHoursUsed());
        self::assertSame(216.0, $regular->getHoursRemaining());
    }

    #[Test]
    public function releasePrefersReturningToNewestEntitlementFirst(): void
    {
        // Symmetrical to consume (carryover-first): release returns hours to
        // the *latest*-expiring entitlement first, so carryover stays drained
        // and won't silently extend past its statutory expiry window.
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(40.0);
        $carryover = $this->entitlement(
            2025,
            LeaveEntitlementType::Carryover,
            40.0,
            new \DateTimeImmutable('2026-03-31'),
        );
        $carryover->consume(30.0);

        $this->consumer->release([$regular, $carryover], 20.0);

        self::assertSame(20.0, $regular->getHoursUsed(), 'Regular entitlement receives release first.');
        self::assertSame(30.0, $carryover->getHoursUsed(), 'Carryover untouched when regular can absorb the refund.');
    }

    #[Test]
    public function releaseSpillsOverToOlderEntitlementWhenNewerIsEmpty(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(10.0);
        $carryover = $this->entitlement(
            2025,
            LeaveEntitlementType::Carryover,
            40.0,
            new \DateTimeImmutable('2026-03-31'),
        );
        $carryover->consume(30.0);

        $this->consumer->release([$regular, $carryover], 25.0);

        self::assertSame(0.0, $regular->getHoursUsed());
        self::assertSame(15.0, $carryover->getHoursUsed(), 'Remaining 15h spill back into carryover.');
    }

    #[Test]
    public function releaseZeroIsNoop(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 40.0);
        $regular->consume(10.0);

        $this->consumer->release([$regular], 0.0);

        self::assertSame(10.0, $regular->getHoursUsed());
    }

    #[Test]
    public function releaseRejectsNegativeAmount(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 40.0);

        $this->expectException(\InvalidArgumentException::class);

        $this->consumer->release([$regular], -1.0);
    }

    #[Test]
    public function releaseRejectsMoreThanTotalConsumed(): void
    {
        $a = $this->entitlement(2026, LeaveEntitlementType::Regular, 40.0);
        $a->consume(10.0);
        $b = $this->entitlement(2027, LeaveEntitlementType::Regular, 40.0);

        $this->expectException(\DomainException::class);

        $this->consumer->release([$a, $b], 20.0);
    }

    private function entitlement(
        int $year,
        LeaveEntitlementType $type,
        float $granted,
        ?\DateTimeImmutable $expiresAt = null,
    ): LeaveEntitlement {
        return new LeaveEntitlement($this->employee, $year, $type, $granted, $expiresAt);
    }
}
