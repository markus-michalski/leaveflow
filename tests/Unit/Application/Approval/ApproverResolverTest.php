<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Approval;

use App\Application\Approval\ApproverResolver;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayType;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Unit tests for ApproverResolver.
 *
 * Rules:
 * - dept.lead if active on clock.now and not the requesting employee
 * - fallback dept.deputy if active and not the requesting employee
 * - returns null when no lead/deputy can take it → caller escalates to Admin
 * - inactive department → null (straight to Admin)
 * - no department → null
 */
#[CoversClass(ApproverResolver::class)]
final class ApproverResolverTest extends TestCase
{
    private Company $acme;
    private Location $hq;
    private AbsenceType $urlaub;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $this->hq = new Location($this->acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->urlaub = new AbsenceType(
            company: $this->acme,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
    }

    #[Test]
    public function returnsLeadWhenActiveAndNotRequester(): void
    {
        $lead = $this->buildEmployee('EMP-LEAD', 'Max Lead');
        $deputy = $this->buildEmployee('EMP-DEP', 'Maria Deputy');
        $requester = $this->buildEmployee('EMP-001', 'Jane Doe');
        $department = $this->buildDepartment($lead, $deputy);
        $requester->assignToDepartment($department);
        $request = $this->buildRequest($requester);

        $resolver = new ApproverResolver(new MockClock('2026-05-01 09:00:00'));

        self::assertSame($lead, $resolver->resolve($request));
    }

    #[Test]
    public function fallsBackToDeputyWhenLeadIsTheRequester(): void
    {
        $requester = $this->buildEmployee('EMP-LEAD', 'Max Lead');
        $deputy = $this->buildEmployee('EMP-DEP', 'Maria Deputy');
        $department = $this->buildDepartment($requester, $deputy);
        $requester->assignToDepartment($department);
        $request = $this->buildRequest($requester);

        $resolver = new ApproverResolver(new MockClock('2026-05-01 09:00:00'));

        self::assertSame($deputy, $resolver->resolve($request));
    }

    #[Test]
    public function fallsBackToDeputyWhenLeadIsNull(): void
    {
        $deputy = $this->buildEmployee('EMP-DEP', 'Maria Deputy');
        $requester = $this->buildEmployee('EMP-001', 'Jane Doe');
        $department = $this->buildDepartment(null, $deputy);
        $requester->assignToDepartment($department);
        $request = $this->buildRequest($requester);

        $resolver = new ApproverResolver(new MockClock('2026-05-01 09:00:00'));

        self::assertSame($deputy, $resolver->resolve($request));
    }

    #[Test]
    public function fallsBackToDeputyWhenLeadHasLeftTheCompany(): void
    {
        $lead = $this->buildEmployee('EMP-LEAD', 'Max Lead');
        $lead->markLeft(new \DateTimeImmutable('2026-01-31'));
        $deputy = $this->buildEmployee('EMP-DEP', 'Maria Deputy');
        $requester = $this->buildEmployee('EMP-001', 'Jane Doe');
        $department = $this->buildDepartment($lead, $deputy);
        $requester->assignToDepartment($department);
        $request = $this->buildRequest($requester);

        $resolver = new ApproverResolver(new MockClock('2026-05-01 09:00:00'));

        self::assertSame($deputy, $resolver->resolve($request));
    }

    #[Test]
    public function returnsNullWhenEmployeeHasNoDepartment(): void
    {
        $requester = $this->buildEmployee('EMP-001', 'Jane Doe');
        $request = $this->buildRequest($requester);

        $resolver = new ApproverResolver(new MockClock('2026-05-01 09:00:00'));

        self::assertNull($resolver->resolve($request));
    }

    #[Test]
    public function returnsNullWhenDepartmentIsInactive(): void
    {
        $lead = $this->buildEmployee('EMP-LEAD', 'Max Lead');
        $deputy = $this->buildEmployee('EMP-DEP', 'Maria Deputy');
        $requester = $this->buildEmployee('EMP-001', 'Jane Doe');
        $department = $this->buildDepartment($lead, $deputy);
        $department->deactivate();
        $requester->assignToDepartment($department);
        $request = $this->buildRequest($requester);

        $resolver = new ApproverResolver(new MockClock('2026-05-01 09:00:00'));

        self::assertNull($resolver->resolve($request));
    }

    #[Test]
    public function returnsNullWhenRequesterIsLeadAndDeputyIsNull(): void
    {
        $requester = $this->buildEmployee('EMP-LEAD', 'Max Lead');
        $department = $this->buildDepartment($requester, null);
        $requester->assignToDepartment($department);
        $request = $this->buildRequest($requester);

        $resolver = new ApproverResolver(new MockClock('2026-05-01 09:00:00'));

        self::assertNull($resolver->resolve($request));
    }

    #[Test]
    public function returnsNullWhenDeputyHasLeftAndLeadIsRequester(): void
    {
        $requester = $this->buildEmployee('EMP-LEAD', 'Max Lead');
        $deputy = $this->buildEmployee('EMP-DEP', 'Maria Deputy');
        $deputy->markLeft(new \DateTimeImmutable('2026-01-15'));
        $department = $this->buildDepartment($requester, $deputy);
        $requester->assignToDepartment($department);
        $request = $this->buildRequest($requester);

        $resolver = new ApproverResolver(new MockClock('2026-05-01 09:00:00'));

        self::assertNull($resolver->resolve($request));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function buildEmployee(string $number, string $name): Employee
    {
        return new Employee(
            company: $this->acme,
            fullName: $name,
            employeeNumber: $number,
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
    }

    private function buildDepartment(?Employee $lead, ?Employee $deputy): Department
    {
        return new Department(
            company: $this->acme,
            name: 'Engineering',
            lead: $lead,
            deputy: $deputy,
        );
    }

    private function buildRequest(Employee $employee): LeaveRequest
    {
        return new LeaveRequest(
            employee: $employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:00:00'),
        );
    }
}
