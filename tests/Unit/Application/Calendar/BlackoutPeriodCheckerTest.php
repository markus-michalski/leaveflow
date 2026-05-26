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

namespace App\Tests\Unit\Application\Calendar;

use App\Application\Calendar\BlackoutPeriodChecker;
use App\Application\Calendar\BlackoutPeriodViolationException;
use App\Domain\Entity\BlackoutPeriod;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Repository\BlackoutPeriodRepository;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BlackoutPeriodChecker — the Application-layer guard that
 * prevents leave requests from being created within a blackout window.
 *
 * Behavior:
 * - Repository returns empty → no exception, void return.
 * - Repository returns blackouts → throws BlackoutPeriodViolationException
 *   with the matching blackouts attached.
 * - The employee's department is forwarded to the repository so that
 *   department-scoped blackouts are filtered correctly.
 */
#[CoversClass(BlackoutPeriodChecker::class)]
#[CoversClass(BlackoutPeriodViolationException::class)]
final class BlackoutPeriodCheckerTest extends TestCase
{
    private Company $acme;
    private Department $engineering;
    private Employee $employee;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $hq = new Location($this->acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->engineering = new Department($this->acme, 'Engineering');
        $this->employee = new Employee(
            company: $this->acme,
            fullName: 'Eve Engineer',
            employeeNumber: 'EMP-EVE',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->employee->assignToDepartment($this->engineering);
    }

    #[Test]
    public function passesWhenRepositoryReturnsNoOverlap(): void
    {
        $repo = $this->createMock(BlackoutPeriodRepository::class);
        $repo->expects(self::once())
            ->method('findOverlapping')
            ->with(
                $this->acme,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::isInstanceOf(\DateTimeImmutable::class),
                $this->engineering,
            )
            ->willReturn([]);

        $checker = new BlackoutPeriodChecker($repo);

        // No exception expected — pure void path.
        $checker->ensureRangeIsClear(
            $this->employee,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-05'),
        );
    }

    #[Test]
    public function throwsWhenRepositoryReturnsOverlappingBlackouts(): void
    {
        $blackout = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Werksferien',
        );
        $repo = $this->createStub(BlackoutPeriodRepository::class);
        $repo->method('findOverlapping')->willReturn([$blackout]);

        $checker = new BlackoutPeriodChecker($repo);

        try {
            $checker->ensureRangeIsClear(
                $this->employee,
                new \DateTimeImmutable('2026-12-27'),
                new \DateTimeImmutable('2026-12-30'),
            );
            self::fail('Expected BlackoutPeriodViolationException');
        } catch (BlackoutPeriodViolationException $e) {
            self::assertSame([$blackout], $e->blackoutPeriods);
            self::assertStringContainsString('Werksferien', $e->getMessage());
        }
    }

    #[Test]
    public function passesNullDepartmentWhenEmployeeHasNoDepartment(): void
    {
        $unassigned = new Employee(
            company: $this->acme,
            fullName: 'Solo Employee',
            employeeNumber: 'EMP-SOLO',
            location: new Location($this->acme, 'Remote', 'DE', 'DE-BE', 'Berlin'),
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        // No assignToDepartment call — department stays null.

        $repo = $this->createMock(BlackoutPeriodRepository::class);
        $repo->expects(self::once())
            ->method('findOverlapping')
            ->with(
                $this->acme,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::isInstanceOf(\DateTimeImmutable::class),
                null,
            )
            ->willReturn([]);

        $checker = new BlackoutPeriodChecker($repo);
        $checker->ensureRangeIsClear(
            $unassigned,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-05'),
        );
    }

    #[Test]
    public function exceptionMessageListsAllBlackoutReasons(): void
    {
        $b1 = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Werksferien',
        );
        $b2 = new BlackoutPeriod(
            company: $this->acme,
            startDate: new \DateTimeImmutable('2026-12-29'),
            endDate: new \DateTimeImmutable('2027-01-05'),
            reason: 'Release-Freeze',
            department: $this->engineering,
        );

        $exception = BlackoutPeriodViolationException::forBlackouts([$b1, $b2]);

        self::assertStringContainsString('Werksferien', $exception->getMessage());
        self::assertStringContainsString('Release-Freeze', $exception->getMessage());
        self::assertSame([$b1, $b2], $exception->blackoutPeriods);
    }
}
