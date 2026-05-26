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

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\BlackoutPeriod;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BlackoutPeriod — admin-managed hard-block date ranges that
 * prevent leave requests from being created within them.
 *
 * Invariants:
 * - Company-scoped (tenant integrity)
 * - Department is optional; null = company-wide block
 * - If Department is set, it must belong to the same company
 * - startDate and endDate are inclusive; endDate >= startDate
 * - reason must not be blank
 */
#[CoversClass(BlackoutPeriod::class)]
final class BlackoutPeriodTest extends TestCase
{
    private Company $acme;
    private Department $engineering;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $hq = new Location($this->acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $lead = new Employee(
            company: $this->acme,
            fullName: 'Max Manager',
            employeeNumber: 'EMP-LEAD',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2018-01-01'),
        );
        $this->engineering = new Department($this->acme, 'Engineering', lead: $lead);
    }

    #[Test]
    public function constructsCompanyWideBlackout(): void
    {
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Werksferien',
        );

        self::assertSame($this->acme, $period->getCompany());
        self::assertNull($period->getDepartment());
        self::assertEquals(new \DateTimeImmutable('2026-12-23'), $period->getStartDate());
        self::assertEquals(new \DateTimeImmutable('2026-12-31'), $period->getEndDate());
        self::assertSame('Werksferien', $period->getReason());
    }

    #[Test]
    public function constructsDepartmentScopedBlackout(): void
    {
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-07-01'),
            endDate: new \DateTimeImmutable('2026-07-14'),
            reason: 'Release-Freeze',
            department: $this->engineering,
        );

        self::assertSame($this->engineering, $period->getDepartment());
    }

    #[Test]
    public function normalizesStartAndEndToMidnight(): void
    {
        // Time component must not leak — blackouts are full-day inclusive.
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23 14:30:00'),
            endDate: new \DateTimeImmutable('2026-12-31 22:59:59'),
            reason: 'Werksferien',
        );

        self::assertSame('2026-12-23 00:00:00', $period->getStartDate()->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-31 00:00:00', $period->getEndDate()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function acceptsSingleDayBlackout(): void
    {
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-05-01'),
            endDate: new \DateTimeImmutable('2026-05-01'),
            reason: 'Tag der Arbeit — geschlossen',
        );

        self::assertEquals($period->getStartDate(), $period->getEndDate());
    }

    #[Test]
    public function rejectsEndBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('end');

        new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-31'),
            endDate: new \DateTimeImmutable('2026-12-23'),
            reason: 'invalid',
        );
    }

    #[Test]
    public function rejectsBlankReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reason');

        new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: '   ',
        );
    }

    #[Test]
    public function rejectsDepartmentFromDifferentCompany(): void
    {
        $otherCompany = new Company('Other GmbH');
        $otherHq = new Location($otherCompany, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $foreignLead = new Employee(
            company: $otherCompany,
            fullName: 'Foreign Lead',
            employeeNumber: 'X-001',
            location: $otherHq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $foreignDept = new Department($otherCompany, 'Foreign Eng', lead: $foreignLead);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same company');

        new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'invalid scope',
            department: $foreignDept,
        );
    }

    #[Test]
    public function trimsReason(): void
    {
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: '  Werksferien  ',
        );

        self::assertSame('Werksferien', $period->getReason());
    }

    #[Test]
    public function coversReturnsTrueForDateInsideRange(): void
    {
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Werksferien',
        );

        self::assertTrue($period->covers(new \DateTimeImmutable('2026-12-23')));
        self::assertTrue($period->covers(new \DateTimeImmutable('2026-12-27')));
        self::assertTrue($period->covers(new \DateTimeImmutable('2026-12-31')));
    }

    #[Test]
    public function coversReturnsFalseForDateOutsideRange(): void
    {
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Werksferien',
        );

        self::assertFalse($period->covers(new \DateTimeImmutable('2026-12-22')));
        self::assertFalse($period->covers(new \DateTimeImmutable('2027-01-01')));
    }

    #[Test]
    public function coversIgnoresTimeComponent(): void
    {
        // Even if the input has 23:59:59, the period covers the full day.
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Werksferien',
        );

        self::assertTrue($period->covers(new \DateTimeImmutable('2026-12-31 23:59:59')));
    }

    #[Test]
    public function appliesToReturnsTrueForDepartmentScopedMatch(): void
    {
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-07-01'),
            endDate: new \DateTimeImmutable('2026-07-14'),
            reason: 'Release-Freeze',
            department: $this->engineering,
        );

        self::assertTrue($period->appliesTo($this->engineering));
    }

    #[Test]
    public function appliesToReturnsFalseForDifferentDepartment(): void
    {
        $sales = new Department($this->acme, 'Sales');
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-07-01'),
            endDate: new \DateTimeImmutable('2026-07-14'),
            reason: 'Release-Freeze',
            department: $this->engineering,
        );

        self::assertFalse($period->appliesTo($sales));
    }

    #[Test]
    public function appliesToReturnsTrueForCompanyWideRegardlessOfDepartment(): void
    {
        $period = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Werksferien',
        );

        self::assertTrue($period->appliesTo($this->engineering));
        self::assertTrue($period->appliesTo(null));
    }
}
