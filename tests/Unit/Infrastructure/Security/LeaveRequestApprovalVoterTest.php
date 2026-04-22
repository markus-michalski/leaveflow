<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\UserRole;
use App\Domain\ValueObject\WorkSchedule;
use App\Infrastructure\Security\LeaveRequestApprovalVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Unit tests for LeaveRequestApprovalVoter.
 *
 * Rules (the four approval attributes share the same gate):
 * - DENY anonymous/unauthenticated
 * - DENY when currentUser has no Employee link (pure admin-only accounts may
 *   still be granted via the admin-role branch below)
 * - DENY self-approval (currentUser.employee === request.employee) — four-eyes,
 *   applies even to admins who happen to also have an employee record
 * - ALLOW if currentUser has ROLE_ADMIN
 * - ALLOW if currentUser.employee is the request.employee.department.lead
 * - ALLOW if currentUser.employee is the request.employee.department.deputy
 * - DENY all other cases
 */
#[CoversClass(LeaveRequestApprovalVoter::class)]
final class LeaveRequestApprovalVoterTest extends TestCase
{
    private Company $acme;
    private Location $hq;
    private AbsenceType $urlaub;
    private LeaveRequestApprovalVoter $voter;

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
        $this->voter = new LeaveRequestApprovalVoter();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function approvalAttributeProvider(): iterable
    {
        yield 'APPROVE' => [LeaveRequestApprovalVoter::APPROVE];
        yield 'REJECT' => [LeaveRequestApprovalVoter::REJECT];
        yield 'CONFIRM_CANCEL' => [LeaveRequestApprovalVoter::CONFIRM_CANCEL];
        yield 'DENY_CANCEL' => [LeaveRequestApprovalVoter::DENY_CANCEL];
    }

    #[Test]
    #[DataProvider('approvalAttributeProvider')]
    public function abstainsOnUnsupportedSubject(string $attribute): void
    {
        $token = $this->tokenFor($this->buildUser('u@x.de', UserRole::Admin));

        $result = $this->voter->vote($token, new \stdClass(), [$attribute]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function abstainsOnUnsupportedAttribute(): void
    {
        $token = $this->tokenFor($this->buildUser('u@x.de', UserRole::Admin));
        $request = $this->buildRequest();

        $result = $this->voter->vote($token, $request, ['SOMETHING_ELSE']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    #[DataProvider('approvalAttributeProvider')]
    public function adminCanActOnAnyRequest(string $attribute): void
    {
        $admin = $this->buildUser('admin@x.de', UserRole::Admin);
        $request = $this->buildRequest();

        $result = $this->voter->vote($this->tokenFor($admin), $request, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    #[DataProvider('approvalAttributeProvider')]
    public function departmentLeadCanActOnRequestOfTheirTeamMember(string $attribute): void
    {
        $leadUser = $this->buildUser('lead@x.de', UserRole::Manager);
        $lead = $this->linkEmployee($leadUser, 'EMP-LEAD', 'Max Lead');
        $requester = $this->buildEmployee('EMP-001', 'Jane Doe');
        $department = new Department($this->acme, 'Engineering', lead: $lead);
        $requester->assignToDepartment($department);
        $request = $this->buildRequest($requester);

        $result = $this->voter->vote($this->tokenFor($leadUser), $request, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    #[DataProvider('approvalAttributeProvider')]
    public function departmentDeputyCanAct(string $attribute): void
    {
        $deputyUser = $this->buildUser('deputy@x.de', UserRole::Manager);
        $deputy = $this->linkEmployee($deputyUser, 'EMP-DEP', 'Maria Deputy');
        $requester = $this->buildEmployee('EMP-001', 'Jane Doe');
        $department = new Department($this->acme, 'Engineering', deputy: $deputy);
        $requester->assignToDepartment($department);
        $request = $this->buildRequest($requester);

        $result = $this->voter->vote($this->tokenFor($deputyUser), $request, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    #[DataProvider('approvalAttributeProvider')]
    public function deniesSelfApprovalEvenWhenLead(string $attribute): void
    {
        // Four-eyes principle: even if you lead the department, you cannot
        // approve your own absence.
        $leadUser = $this->buildUser('lead@x.de', UserRole::Manager);
        $lead = $this->linkEmployee($leadUser, 'EMP-LEAD', 'Max Lead');
        $department = new Department($this->acme, 'Engineering', lead: $lead);
        $lead->assignToDepartment($department);
        $request = $this->buildRequest($lead);

        $result = $this->voter->vote($this->tokenFor($leadUser), $request, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    #[DataProvider('approvalAttributeProvider')]
    public function deniesSelfApprovalEvenWhenAdmin(string $attribute): void
    {
        $adminUser = $this->buildUser('admin@x.de', UserRole::Admin);
        $adminEmployee = $this->linkEmployee($adminUser, 'EMP-ADM', 'Alice Admin');
        $request = $this->buildRequest($adminEmployee);

        $result = $this->voter->vote($this->tokenFor($adminUser), $request, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    #[DataProvider('approvalAttributeProvider')]
    public function deniesUnrelatedManagerWithoutDepartmentRole(string $attribute): void
    {
        $otherLeadUser = $this->buildUser('other@x.de', UserRole::Manager);
        $otherLead = $this->linkEmployee($otherLeadUser, 'EMP-OTH', 'Other Lead');
        $otherDepartment = new Department($this->acme, 'Sales', lead: $otherLead);
        $otherLead->assignToDepartment($otherDepartment);

        $requesterDept = new Department($this->acme, 'Engineering');
        $requester = $this->buildEmployee('EMP-001', 'Jane Doe');
        $requester->assignToDepartment($requesterDept);
        $request = $this->buildRequest($requester);

        $result = $this->voter->vote($this->tokenFor($otherLeadUser), $request, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    #[DataProvider('approvalAttributeProvider')]
    public function deniesUserWithoutEmployeeLinkAndNonAdminRole(string $attribute): void
    {
        $managerUser = $this->buildUser('manager@x.de', UserRole::Manager);
        // No employee link — cannot be dept lead/deputy.
        $request = $this->buildRequest();

        $result = $this->voter->vote($this->tokenFor($managerUser), $request, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function buildUser(string $email, UserRole $role): User
    {
        return new User($this->acme, $email, $role);
    }

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

    private function linkEmployee(User $user, string $number, string $name): Employee
    {
        $employee = new Employee(
            company: $this->acme,
            fullName: $name,
            employeeNumber: $number,
            location: $this->hq,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            user: $user,
        );

        // Wire the inverse side manually — Doctrine would do this via
        // hydration, but in unit tests we hand-wire the OneToOne.
        $reflection = new \ReflectionClass(User::class);
        $property = $reflection->getProperty('employee');
        $property->setValue($user, $employee);

        return $employee;
    }

    private function buildRequest(?Employee $employee = null): LeaveRequest
    {
        $requester = $employee ?? $this->buildEmployee('EMP-REQ', 'Default Requester');

        return new LeaveRequest(
            employee: $requester,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-07-06'),
            endDate: new \DateTimeImmutable('2026-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01 09:00:00'),
        );
    }

    private function tokenFor(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
