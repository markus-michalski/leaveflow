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

namespace App\Tests\Unit\Application\Approval;

use App\Application\Approval\LeaveRequestEntitlementBooker;
use App\Application\Entitlement\EntitlementConsumer;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\ExclusionReason;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\LeaveEntitlementRepository;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(LeaveRequestEntitlementBooker::class)]
final class LeaveRequestEntitlementBookerTest extends TestCase
{
    private Company $acme;
    private Employee $employee;
    private AbsenceType $deducting;
    private AbsenceType $nonDeducting;
    private LeaveEntitlementRepository&MockObject $repository;

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
        $this->deducting = new AbsenceType($this->acme, 'Urlaub', true, true, '#3B82F6');
        $this->nonDeducting = new AbsenceType($this->acme, 'Krankheit', false, false, '#EF4444');
        $this->repository = $this->createMock(LeaveEntitlementRepository::class);
    }

    #[Test]
    public function consumeDeductsSingleYearHoursFromRegularEntitlement(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $this->repository->expects(self::once())
            ->method('findByEmployeeAndYear')
            ->with($this->employee, 2026)
            ->willReturn([$regular]);

        $request = $this->requestFromDays($this->deducting, [
            ['2026-07-06', 8.0, LeaveDayStatus::Working],
            ['2026-07-07', 8.0, LeaveDayStatus::Working],
            ['2026-07-08', 8.0, LeaveDayStatus::Working],
            ['2026-07-09', 8.0, LeaveDayStatus::Working],
            ['2026-07-10', 8.0, LeaveDayStatus::Working],
        ]);

        $this->booker()->consume($request);

        self::assertSame(40.0, $regular->getHoursUsed());
    }

    #[Test]
    public function consumeSplitsMultiYearRequestByYear(): void
    {
        $e2025 = $this->entitlement(2025, LeaveEntitlementType::Regular, 240.0);
        $e2026 = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);

        $this->repository->expects(self::exactly(2))
            ->method('findByEmployeeAndYear')
            ->willReturnCallback(static fn (Employee $_employee, int $year): array => match ($year) {
                2025 => [$e2025],
                2026 => [$e2026],
                default => [],
            });

        $request = $this->requestFromDays($this->deducting, [
            ['2025-12-30', 8.0, LeaveDayStatus::Working],
            ['2025-12-31', 8.0, LeaveDayStatus::Working],
            ['2026-01-01', 0.0, LeaveDayStatus::Excluded],
            ['2026-01-02', 8.0, LeaveDayStatus::Working],
        ]);

        $this->booker()->consume($request);

        self::assertSame(16.0, $e2025->getHoursUsed());
        self::assertSame(8.0, $e2026->getHoursUsed());
    }

    #[Test]
    public function consumeIgnoresExcludedDays(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $this->repository->method('findByEmployeeAndYear')->willReturn([$regular]);

        $request = $this->requestFromDays($this->deducting, [
            ['2026-07-06', 8.0, LeaveDayStatus::Working],
            ['2026-07-07', 0.0, LeaveDayStatus::Excluded],
            ['2026-07-08', 8.0, LeaveDayStatus::Working],
        ]);

        $this->booker()->consume($request);

        self::assertSame(16.0, $regular->getHoursUsed());
    }

    #[Test]
    public function consumeIsNoopForNonDeductingType(): void
    {
        $this->repository->expects(self::never())->method('findByEmployeeAndYear');

        $request = $this->requestFromDays($this->nonDeducting, [
            ['2026-07-06', 8.0, LeaveDayStatus::Working],
        ]);

        $this->booker()->consume($request);
    }

    #[Test]
    public function releaseReturnsHoursPerYear(): void
    {
        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(40.0);

        $this->repository->expects(self::once())
            ->method('findByEmployeeAndYear')
            ->with($this->employee, 2026)
            ->willReturn([$regular]);

        $request = $this->requestFromDays($this->deducting, [
            ['2026-07-06', 8.0, LeaveDayStatus::Working],
            ['2026-07-07', 8.0, LeaveDayStatus::Working],
            ['2026-07-08', 8.0, LeaveDayStatus::Working],
        ]);

        $this->booker()->release($request);

        self::assertSame(16.0, $regular->getHoursUsed());
    }

    #[Test]
    public function releaseIsNoopForNonDeductingType(): void
    {
        $this->repository->expects(self::never())->method('findByEmployeeAndYear');

        $request = $this->requestFromDays($this->nonDeducting, [
            ['2026-07-06', 8.0, LeaveDayStatus::Working],
        ]);

        $this->booker()->release($request);
    }

    /**
     * @param list<array{0: string, 1: float, 2: LeaveDayStatus}> $days
     */
    private function requestFromDays(AbsenceType $absenceType, array $days): LeaveRequest
    {
        $start = new \DateTimeImmutable($days[0][0]);
        $end = new \DateTimeImmutable($days[\count($days) - 1][0]);
        $request = new LeaveRequest(
            employee: $this->employee,
            absenceType: $absenceType,
            startDate: $start,
            endDate: $end,
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-01-01 09:00:00'),
        );

        $leaveDays = [];
        foreach ($days as [$date, $hours, $status]) {
            $reason = LeaveDayStatus::Excluded === $status ? ExclusionReason::Holiday : null;
            $leaveDays[] = new LeaveDay(new \DateTimeImmutable($date), $hours, $status, $reason);
        }
        $request->applyBreakdown(new LeaveBreakdown($leaveDays));

        return $request;
    }

    private function entitlement(
        int $year,
        LeaveEntitlementType $type,
        float $granted,
        ?\DateTimeImmutable $expiresAt = null,
    ): LeaveEntitlement {
        return new LeaveEntitlement($this->employee, $year, $type, $granted, $expiresAt);
    }

    #[Test]
    public function consumeRespectsAbsenceTypeBucketBindingToRegularOnly(): void
    {
        // Urlaub bound to Regular only — Carryover with hours stays untouched.
        $urlaubRegularOnly = new AbsenceType($this->acme, 'Urlaub', true, true, '#3B82F6', true, LeaveEntitlementType::Regular);

        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $carryover = $this->entitlement(2026, LeaveEntitlementType::Carryover, 16.0, new \DateTimeImmutable('2026-03-31'));
        $this->repository->method('findByEmployeeAndYear')->willReturn([$regular, $carryover]);

        $request = $this->requestFromDays($urlaubRegularOnly, [
            ['2026-07-06', 8.0, LeaveDayStatus::Working],
        ]);

        $this->booker()->consume($request);

        self::assertSame(8.0, $regular->getHoursUsed());
        self::assertSame(0.0, $carryover->getHoursUsed());  // untouched
    }

    #[Test]
    public function consumeRespectsAbsenceTypeBucketBindingToCarryoverOnly(): void
    {
        // Resturlaub bound to Carryover only — even though Regular has plenty,
        // the Carryover bucket is what gets consumed.
        $resturlaub = new AbsenceType($this->acme, 'Resturlaub', true, true, '#6366F1', true, LeaveEntitlementType::Carryover);

        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $carryover = $this->entitlement(2026, LeaveEntitlementType::Carryover, 16.0, new \DateTimeImmutable('2026-12-31'));
        $this->repository->method('findByEmployeeAndYear')->willReturn([$regular, $carryover]);

        $request = $this->requestFromDays($resturlaub, [
            ['2026-07-06', 8.0, LeaveDayStatus::Working],
        ]);

        $this->booker()->consume($request);

        self::assertSame(0.0, $regular->getHoursUsed());  // untouched
        self::assertSame(8.0, $carryover->getHoursUsed());
    }

    #[Test]
    public function consumeBucketBoundResturlaubThrowsWhenCarryoverInsufficient(): void
    {
        // Carryover bucket only has 4h, request needs 8h, Regular has 200h
        // available — bucket binding must prevent the spillover.
        $resturlaub = new AbsenceType($this->acme, 'Resturlaub', true, true, '#6366F1', true, LeaveEntitlementType::Carryover);

        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $carryover = $this->entitlement(2026, LeaveEntitlementType::Carryover, 4.0, new \DateTimeImmutable('2026-12-31'));
        $this->repository->method('findByEmployeeAndYear')->willReturn([$regular, $carryover]);

        $request = $this->requestFromDays($resturlaub, [
            ['2026-07-06', 8.0, LeaveDayStatus::Working],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Insufficient');

        $this->booker()->consume($request);
    }

    #[Test]
    public function releaseRespectsBucketBindingToOnlyReleaseToBoundBucket(): void
    {
        // Pre-consumed Resturlaub: 8h taken from Carryover. Release must only
        // refund Carryover even though Regular also has hoursUsed (from a
        // previous Urlaub request, simulated here).
        $resturlaub = new AbsenceType($this->acme, 'Resturlaub', true, true, '#6366F1', true, LeaveEntitlementType::Carryover);

        $regular = $this->entitlement(2026, LeaveEntitlementType::Regular, 240.0);
        $regular->consume(40.0);  // unrelated prior Urlaub
        $carryover = $this->entitlement(2026, LeaveEntitlementType::Carryover, 16.0, new \DateTimeImmutable('2026-12-31'));
        $carryover->consume(8.0);  // the Resturlaub request being released
        $this->repository->method('findByEmployeeAndYear')->willReturn([$regular, $carryover]);

        $request = $this->requestFromDays($resturlaub, [
            ['2026-07-06', 8.0, LeaveDayStatus::Working],
        ]);

        $this->booker()->release($request);

        self::assertSame(40.0, $regular->getHoursUsed());  // unchanged
        self::assertSame(0.0, $carryover->getHoursUsed());  // refunded
    }

    private function booker(): LeaveRequestEntitlementBooker
    {
        return new LeaveRequestEntitlementBooker(
            $this->repository,
            new EntitlementConsumer(),
            new MockClock('2026-05-01 12:00:00'),
        );
    }
}
