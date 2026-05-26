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
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Employee::class)]
final class EmployeeAnonymizationTest extends TestCase
{
    private Company $company;
    private Location $hq;

    protected function setUp(): void
    {
        $this->company = new Company('Acme GmbH');
        $this->hq = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');
    }

    #[Test]
    public function notAnonymizedByDefault(): void
    {
        $employee = $this->buildEmployee();

        self::assertFalse($employee->isAnonymized());
        self::assertNull($employee->getAnonymizedAt());
    }

    #[Test]
    public function anonymizeOverwritesFullNameAndSetsTimestamp(): void
    {
        $employee = $this->buildEmployee('Jane Doe');
        $at = new \DateTimeImmutable('2026-05-21 10:00:00');

        $employee->anonymize('Ehemaliger Mitarbeiter #42', $at);

        self::assertSame('Ehemaliger Mitarbeiter #42', $employee->getFullName());
        self::assertTrue($employee->isAnonymized());
        self::assertEquals($at, $employee->getAnonymizedAt());
    }

    #[Test]
    public function anonymizeThrowsWhenAlreadyAnonymized(): void
    {
        $employee = $this->buildEmployee();
        $employee->anonymize('Ehemaliger Mitarbeiter #42', new \DateTimeImmutable('2026-01-01'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already anonymized');

        $employee->anonymize('Ehemaliger Mitarbeiter #42', new \DateTimeImmutable('2026-05-21'));
    }

    private function buildEmployee(string $fullName = 'Jane Doe'): Employee
    {
        return new Employee(
            $this->company,
            $fullName,
            'EMP-001',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2023-01-01'),
        );
    }
}
