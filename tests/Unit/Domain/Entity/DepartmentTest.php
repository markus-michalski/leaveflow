<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Department — the org-hierarchy aggregate that owns the
 * approval chain (lead + optional deputy).
 *
 * Invariants:
 * - Company-scoped (tenant integrity)
 * - name must not be blank
 * - lead and deputy, if assigned, must belong to the same company
 * - active defaults to true; inactive departments route approvals to the
 *   Admin fallback (ApproverResolver concern, asserted there)
 */
#[CoversClass(Department::class)]
final class DepartmentTest extends TestCase
{
    private Company $acme;
    private Employee $lead;
    private Employee $deputy;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $hq = new Location($this->acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->lead = new Employee(
            company: $this->acme,
            fullName: 'Max Manager',
            employeeNumber: 'EMP-LEAD',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2018-01-01'),
        );
        $this->deputy = new Employee(
            company: $this->acme,
            fullName: 'Maria Deputy',
            employeeNumber: 'EMP-DEP',
            location: $hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2019-01-01'),
        );
    }

    #[Test]
    public function constructsWithMinimalFields(): void
    {
        $department = new Department($this->acme, 'Engineering');

        self::assertSame($this->acme, $department->getCompany());
        self::assertSame('Engineering', $department->getName());
        self::assertNull($department->getLead());
        self::assertNull($department->getDeputy());
        self::assertTrue($department->isActive());
    }

    #[Test]
    public function storesLeadAndDeputyWhenProvided(): void
    {
        $department = new Department(
            company: $this->acme,
            name: 'Engineering',
            lead: $this->lead,
            deputy: $this->deputy,
        );

        self::assertSame($this->lead, $department->getLead());
        self::assertSame($this->deputy, $department->getDeputy());
    }

    #[Test]
    public function rejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        new Department($this->acme, '   ');
    }

    #[Test]
    public function rejectsLeadFromDifferentCompany(): void
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

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same company');

        new Department($this->acme, 'Engineering', lead: $foreignLead);
    }

    #[Test]
    public function rejectsDeputyFromDifferentCompany(): void
    {
        $otherCompany = new Company('Other GmbH');
        $otherHq = new Location($otherCompany, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $foreignDeputy = new Employee(
            company: $otherCompany,
            fullName: 'Foreign Deputy',
            employeeNumber: 'X-002',
            location: $otherHq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same company');

        new Department($this->acme, 'Engineering', deputy: $foreignDeputy);
    }

    #[Test]
    public function rejectsLeadAndDeputyBeingTheSamePerson(): void
    {
        // Makes no operational sense: if the lead is also deputy, the fallback
        // chain collapses the moment the lead is out — which is exactly when
        // deputy matters.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('distinct');

        new Department(
            company: $this->acme,
            name: 'Engineering',
            lead: $this->lead,
            deputy: $this->lead,
        );
    }

    #[Test]
    public function renameUpdatesName(): void
    {
        $department = new Department($this->acme, 'Engineering');
        $department->rename('Platform Engineering');

        self::assertSame('Platform Engineering', $department->getName());
    }

    #[Test]
    public function renameRejectsBlankName(): void
    {
        $department = new Department($this->acme, 'Engineering');

        $this->expectException(\InvalidArgumentException::class);

        $department->rename('');
    }

    #[Test]
    public function assignLeadAcceptsSameCompanyEmployee(): void
    {
        $department = new Department($this->acme, 'Engineering');
        $department->assignLead($this->lead);

        self::assertSame($this->lead, $department->getLead());
    }

    #[Test]
    public function assignLeadAcceptsNullToClear(): void
    {
        $department = new Department($this->acme, 'Engineering', lead: $this->lead);
        $department->assignLead(null);

        self::assertNull($department->getLead());
    }

    #[Test]
    public function assignLeadRejectsForeignCompanyEmployee(): void
    {
        $department = new Department($this->acme, 'Engineering');
        $otherCompany = new Company('Other GmbH');
        $otherHq = new Location($otherCompany, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $foreign = new Employee(
            company: $otherCompany,
            fullName: 'Foreign',
            employeeNumber: 'X-001',
            location: $otherHq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );

        $this->expectException(\InvalidArgumentException::class);

        $department->assignLead($foreign);
    }

    #[Test]
    public function assignLeadRejectsCurrentDeputy(): void
    {
        $department = new Department(
            company: $this->acme,
            name: 'Engineering',
            deputy: $this->deputy,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('distinct');

        $department->assignLead($this->deputy);
    }

    #[Test]
    public function assignDeputyAcceptsSameCompanyEmployee(): void
    {
        $department = new Department($this->acme, 'Engineering');
        $department->assignDeputy($this->deputy);

        self::assertSame($this->deputy, $department->getDeputy());
    }

    #[Test]
    public function assignDeputyRejectsCurrentLead(): void
    {
        $department = new Department(
            company: $this->acme,
            name: 'Engineering',
            lead: $this->lead,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('distinct');

        $department->assignDeputy($this->lead);
    }

    #[Test]
    public function deactivateMarksInactive(): void
    {
        $department = new Department($this->acme, 'Engineering');
        $department->deactivate();

        self::assertFalse($department->isActive());
    }

    #[Test]
    public function activateMarksActive(): void
    {
        $department = new Department($this->acme, 'Engineering');
        $department->deactivate();
        $department->activate();

        self::assertTrue($department->isActive());
    }
}
