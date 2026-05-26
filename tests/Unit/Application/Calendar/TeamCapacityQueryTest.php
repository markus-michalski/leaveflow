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

use App\Application\Calendar\TeamCapacityQuery;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Repository\LeaveRequestRepository;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TeamCapacityQuery — counts distinct department peers with
 * approved leaves overlapping a date range. Returns 0 when the employee has
 * no department.
 */
#[CoversClass(TeamCapacityQuery::class)]
final class TeamCapacityQueryTest extends TestCase
{
    private Company $acme;
    private Department $engineering;
    private Location $hq;
    private AbsenceType $urlaub;
    private Employee $requester;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $this->hq = new Location($this->acme, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->engineering = new Department($this->acme, 'Engineering');
        $this->urlaub = new AbsenceType(
            company: $this->acme,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
        $this->requester = $this->buildEmployee('Requester', 'EMP-REQ');
        $this->requester->assignToDepartment($this->engineering);
    }

    #[Test]
    public function returnsZeroWhenEmployeeHasNoDepartment(): void
    {
        $solo = $this->buildEmployee('Solo', 'EMP-SOLO');
        // No department assigned.

        $repo = $this->createStub(LeaveRequestRepository::class);
        $query = new TeamCapacityQuery($repo);

        self::assertSame(0, $query->countOverlappingPeers(
            $solo,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-05'),
        ));
    }

    #[Test]
    public function countsDistinctPeers(): void
    {
        $alice = $this->buildEmployee('Alice', 'EMP-A');
        $bob = $this->buildEmployee('Bob', 'EMP-B');

        // Alice has two overlapping leave requests; should count as 1 peer.
        $req1 = $this->buildApprovedRequest($alice, '2026-06-01', '2026-06-03');
        $req2 = $this->buildApprovedRequest($alice, '2026-06-04', '2026-06-05');
        $req3 = $this->buildApprovedRequest($bob, '2026-06-02', '2026-06-04');

        $repo = $this->createStub(LeaveRequestRepository::class);
        $repo->method('findActiveOverlapping')->willReturn([$req1, $req2, $req3]);

        $query = new TeamCapacityQuery($repo);

        self::assertSame(2, $query->countOverlappingPeers(
            $this->requester,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-05'),
        ));
    }

    #[Test]
    public function returnsZeroWhenRepositoryReturnsNoOverlap(): void
    {
        $repo = $this->createStub(LeaveRequestRepository::class);
        $repo->method('findActiveOverlapping')->willReturn([]);

        $query = new TeamCapacityQuery($repo);

        self::assertSame(0, $query->countOverlappingPeers(
            $this->requester,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-05'),
        ));
    }

    private function buildEmployee(string $name, string $number): Employee
    {
        $employee = new Employee(
            company: $this->acme,
            fullName: $name,
            employeeNumber: $number,
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        // Tests need stable ids. Reflection sidesteps the lack of a setter.
        $reflection = new \ReflectionProperty(Employee::class, 'id');
        $reflection->setValue($employee, crc32($number));

        return $employee;
    }

    private function buildApprovedRequest(Employee $employee, string $start, string $end): LeaveRequest
    {
        return new LeaveRequest(
            employee: $employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-01-01'),
        );
    }
}
