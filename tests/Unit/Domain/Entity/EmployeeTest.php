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

use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Employee::class)]
final class EmployeeTest extends TestCase
{
    private Company $acme;
    private Location $hq;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $this->hq = new Location($this->acme, 'HQ', 'DE', 'DE-BY', 'München');
    }

    #[Test]
    public function storesCoreFieldsAndNormalizesWhitespace(): void
    {
        $employee = new Employee(
            $this->acme,
            '  Jane Doe  ',
            '  EMP-001  ',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2026-01-01'),
        );

        self::assertSame('Jane Doe', $employee->getFullName());
        self::assertSame('EMP-001', $employee->getEmployeeNumber());
        self::assertSame($this->hq, $employee->getLocation());
        self::assertSame($this->acme, $employee->getCompany());
        self::assertFalse($employee->hasUser());
        self::assertNull($employee->getLeftAt());
    }

    #[Test]
    public function rejectsBlankFullName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fullName');

        new Employee($this->acme, '   ', 'EMP-001', $this->hq, WorkSchedule::standardFullTime(), new \DateTimeImmutable());
    }

    #[Test]
    public function rejectsBlankEmployeeNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('employeeNumber');

        new Employee($this->acme, 'Jane', '', $this->hq, WorkSchedule::standardFullTime(), new \DateTimeImmutable());
    }

    #[Test]
    public function rejectsLocationFromDifferentCompany(): void
    {
        $other = new Company('Other GmbH');
        $foreignLocation = new Location($other, 'HQ', 'DE', 'DE-BY', 'München');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Location must belong');

        new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $foreignLocation,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable(),
        );
    }

    #[Test]
    public function rejectsUserFromDifferentCompany(): void
    {
        $other = new Company('Other GmbH');
        $foreignUser = new User($other, 'foreign@example.com', UserRole::Employee);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Linked user must belong');

        new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable(),
            $foreignUser,
        );
    }

    #[Test]
    public function rejectsLeftBeforeJoined(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('leftAt');

        new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2026-06-01'),
            null,
            new \DateTimeImmutable('2026-01-01'),
        );
    }

    #[Test]
    public function isActiveOnBetweenJoinedAndLeftInclusive(): void
    {
        $employee = new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2026-01-01'),
            null,
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertFalse($employee->isActiveOn(new \DateTimeImmutable('2025-12-31')));
        self::assertTrue($employee->isActiveOn(new \DateTimeImmutable('2026-01-01')));
        self::assertTrue($employee->isActiveOn(new \DateTimeImmutable('2026-07-15')));
        self::assertTrue($employee->isActiveOn(new \DateTimeImmutable('2026-12-31')));
        self::assertFalse($employee->isActiveOn(new \DateTimeImmutable('2027-01-01')));
    }

    #[Test]
    public function isActiveOnWithoutLeftAtIsTrueForeverAfterJoined(): void
    {
        $employee = new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2026-01-01'),
        );

        self::assertTrue($employee->isActiveOn(new \DateTimeImmutable('2099-12-31')));
    }

    #[Test]
    public function updateJoinedAtChangesJoinedDate(): void
    {
        $employee = new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
        );

        $employee->updateJoinedAt(new \DateTimeImmutable('2023-03-01'));

        self::assertSame('2023-03-01', $employee->getJoinedAt()->format('Y-m-d'));
    }

    #[Test]
    public function updateJoinedAtRejectsDateAfterExistingLeftAt(): void
    {
        $employee = new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2023-01-01'),
            null,
            new \DateTimeImmutable('2023-07-01'),
        );

        $this->expectException(\InvalidArgumentException::class);

        $employee->updateJoinedAt(new \DateTimeImmutable('2023-08-01'));
    }

    #[Test]
    public function updateJoinedAtAllowsDateBeforeExistingLeftAt(): void
    {
        $employee = new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
            null,
            new \DateTimeImmutable('2024-07-01'),
        );

        $employee->updateJoinedAt(new \DateTimeImmutable('2023-03-01'));

        self::assertSame('2023-03-01', $employee->getJoinedAt()->format('Y-m-d'));
    }

    #[Test]
    public function markLeftRejectsDateBeforeJoined(): void
    {
        $employee = new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2026-06-01'),
        );

        $this->expectException(\InvalidArgumentException::class);

        $employee->markLeft(new \DateTimeImmutable('2026-01-01'));
    }

    #[Test]
    public function linkUserRejectsUserFromDifferentCompany(): void
    {
        $employee = new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2026-01-01'),
        );

        $other = new Company('Other');
        $foreignUser = new User($other, 'x@example.com', UserRole::Employee);

        $this->expectException(\InvalidArgumentException::class);

        $employee->linkUser($foreignUser);
    }

    #[Test]
    public function linkAndUnlinkUser(): void
    {
        $employee = new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2026-01-01'),
        );
        $user = new User($this->acme, 'jane@example.com', UserRole::Employee);

        $employee->linkUser($user);
        self::assertTrue($employee->hasUser());
        self::assertSame($user, $employee->getUser());

        $employee->unlinkUser();
        self::assertFalse($employee->hasUser());
    }

    #[Test]
    public function reassignLocationRejectsDifferentCompany(): void
    {
        $employee = new Employee(
            $this->acme,
            'Jane',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2026-01-01'),
        );
        $other = new Company('Other');
        $foreignLocation = new Location($other, 'X', 'DE', 'DE-BY', 'München');

        $this->expectException(\InvalidArgumentException::class);

        $employee->reassignLocation($foreignLocation);
    }

    #[Test]
    public function departmentIsNullByDefault(): void
    {
        $employee = $this->buildEmployee();

        self::assertNull($employee->getDepartment());
    }

    #[Test]
    public function assignToDepartmentStoresReference(): void
    {
        $employee = $this->buildEmployee();
        $department = new Department($this->acme, 'Engineering');

        $employee->assignToDepartment($department);

        self::assertSame($department, $employee->getDepartment());
    }

    #[Test]
    public function assignToDepartmentAcceptsNullToClear(): void
    {
        $employee = $this->buildEmployee();
        $employee->assignToDepartment(new Department($this->acme, 'Engineering'));

        $employee->assignToDepartment(null);

        self::assertNull($employee->getDepartment());
    }

    #[Test]
    public function assignToDepartmentRejectsForeignCompany(): void
    {
        $employee = $this->buildEmployee();
        $otherCompany = new Company('Other GmbH');
        $foreignDepartment = new Department($otherCompany, 'Sales');

        $this->expectException(\InvalidArgumentException::class);

        $employee->assignToDepartment($foreignDepartment);
    }

    #[Test]
    public function isNotInProbationWhenNoProbationEndDateIsSet(): void
    {
        $employee = $this->buildEmployee();

        self::assertNull($employee->getProbationEndsAt());
        self::assertFalse($employee->isInProbation(new \DateTimeImmutable('2026-03-01')));
    }

    #[Test]
    public function isInProbationWhenOnDateIsBeforeOrEqualToProbationEnd(): void
    {
        $employee = $this->buildEmployee();
        $employee->updateProbationEndsAt(new \DateTimeImmutable('2026-06-30'));

        self::assertTrue($employee->isInProbation(new \DateTimeImmutable('2026-06-30')));
        self::assertTrue($employee->isInProbation(new \DateTimeImmutable('2026-06-30 23:59:59')));
        self::assertTrue($employee->isInProbation(new \DateTimeImmutable('2026-01-15 14:30:00')));
        self::assertFalse($employee->isInProbation(new \DateTimeImmutable('2026-07-01')));
    }

    #[Test]
    public function updateProbationEndsAtCanClearTheField(): void
    {
        $employee = $this->buildEmployee();
        $employee->updateProbationEndsAt(new \DateTimeImmutable('2026-06-30'));
        self::assertNotNull($employee->getProbationEndsAt());

        $employee->updateProbationEndsAt(null);
        self::assertNull($employee->getProbationEndsAt());
        self::assertFalse($employee->isInProbation(new \DateTimeImmutable('2026-03-01')));
    }

    #[Test]
    public function updateProbationEndsAtRejectsDateBeforeJoinDate(): void
    {
        $employee = $this->buildEmployee(); // joinedAt = 2026-01-01

        $this->expectException(\InvalidArgumentException::class);

        $employee->updateProbationEndsAt(new \DateTimeImmutable('2025-12-31'));
    }

    private function buildEmployee(): Employee
    {
        return new Employee(
            $this->acme,
            'Jane Doe',
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2026-01-01'),
        );
    }
}
