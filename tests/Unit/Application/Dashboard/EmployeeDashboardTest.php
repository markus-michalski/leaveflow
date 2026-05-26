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

namespace App\Tests\Unit\Application\Dashboard;

use App\Application\Dashboard\EmployeeDashboard;
use App\Application\Entitlement\BalanceSnapshot;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmployeeDashboard::class)]
final class EmployeeDashboardTest extends TestCase
{
    private Company $acme;
    private Location $hq;
    private AbsenceType $urlaub;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $this->hq = new Location($this->acme, 'HQ', 'DE', 'DE-BY', 'München');
        $this->urlaub = new AbsenceType(
            company: $this->acme,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
    }

    #[Test]
    public function plannedHoursReturnsZeroWhenNoUpcomingRequests(): void
    {
        $dashboard = $this->dashboardWithRequests([]);

        self::assertSame(0.0, $dashboard->plannedHours());
    }

    #[Test]
    public function plannedHoursSumsPendingRequestHoursOnly(): void
    {
        $employee = $this->employee('Maya Manager');

        $pending = $this->request($employee, LeaveRequestStatus::Pending, 40.0);
        $approved = $this->request($employee, LeaveRequestStatus::Approved, 24.0);
        $recorded = $this->request($employee, LeaveRequestStatus::Recorded, 8.0);

        $dashboard = $this->dashboardWithRequests([$pending, $approved, $recorded]);

        // Only Pending is not yet booked in hoursUsed; Approved and Recorded are already reflected
        self::assertSame(40.0, $dashboard->plannedHours());
    }

    #[Test]
    public function plannedHoursSumsMultiplePendingRequests(): void
    {
        $employee = $this->employee('Maya Manager');

        $pending1 = $this->request($employee, LeaveRequestStatus::Pending, 16.0);
        $pending2 = $this->request($employee, LeaveRequestStatus::Pending, 24.0);

        $dashboard = $this->dashboardWithRequests([$pending1, $pending2]);

        self::assertSame(40.0, $dashboard->plannedHours());
    }

    #[Test]
    public function plannedHoursIgnoresCancelRequestedRequests(): void
    {
        $employee = $this->employee('Maya Manager');

        // CancelRequested is still booked in hoursUsed (still approved)
        $cancelRequested = $this->request($employee, LeaveRequestStatus::CancelRequested, 16.0);

        $dashboard = $this->dashboardWithRequests([$cancelRequested]);

        self::assertSame(0.0, $dashboard->plannedHours());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function employee(string $name): Employee
    {
        return new Employee(
            company: $this->acme,
            fullName: $name,
            employeeNumber: 'EMP-'.str_replace(' ', '', $name),
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
    }

    private function request(Employee $employee, LeaveRequestStatus $status, float $hours): LeaveRequest
    {
        $request = new LeaveRequest(
            employee: $employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-06-01'),
            endDate: new \DateTimeImmutable('2026-06-05'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );
        $request->setStatus($status);

        $ref = new \ReflectionProperty($request, 'totalHours');
        $ref->setValue($request, $hours);

        return $request;
    }

    /** @param list<LeaveRequest> $requests */
    private function dashboardWithRequests(array $requests): EmployeeDashboard
    {
        return new EmployeeDashboard(
            balance: new BalanceSnapshot(240.0, 0.0, 240.0, 0.0, 0.0, 0.0, null),
            balanceYear: 2026,
            upcomingRequests: $requests,
            teamAbsencesToday: [],
            hasDepartment: false,
        );
    }
}
