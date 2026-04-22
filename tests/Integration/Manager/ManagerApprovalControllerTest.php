<?php

declare(strict_types=1);

namespace App\Tests\Integration\Manager;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestAuditEntry;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManagerApprovalControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Employee $leadEmployee;
    private Employee $deputyEmployee;
    private Employee $teamMember;
    private Employee $outsideEmployee;
    private AbsenceType $urlaub;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function adminSeesAllPendingRequestsCompanyWide(): void
    {
        $this->storeRequest($this->teamMember);
        $this->storeRequest($this->outsideEmployee);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/manager/approvals');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->client->getCrawler()->filter('[data-testid^="manager-approval-row-"]'));
    }

    #[Test]
    public function leadSeesOnlyTheirTeamsRequests(): void
    {
        $this->storeRequest($this->teamMember);
        $this->storeRequest($this->outsideEmployee);
        $this->em->flush();

        $this->loginAs('lead@leaveflow.test');
        $this->client->request('GET', '/manager/approvals');

        self::assertResponseIsSuccessful();
        // Only teamMember's request — outsideEmployee is in a different dept.
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid^="manager-approval-row-"]'));
    }

    #[Test]
    public function leadCannotSeeOwnRequestInApprovalList(): void
    {
        // Four-eyes: the lead's own request should NOT appear in their approval list.
        $this->storeRequest($this->leadEmployee);
        $this->em->flush();

        $this->loginAs('lead@leaveflow.test');
        $this->client->request('GET', '/manager/approvals');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="manager-approvals-empty"]');
    }

    #[Test]
    public function employeeIsForbidden(): void
    {
        $this->loginAs('member@leaveflow.test');

        $this->client->request('GET', '/manager/approvals');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function leadCanApproveTeamMemberRequest(): void
    {
        $request = $this->storeRequest($this->teamMember);
        $this->em->flush();

        $this->loginAs('lead@leaveflow.test');

        $crawler = $this->client->request('GET', '/manager/approvals/'.$request->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/manager/approvals/'.$request->getId().'/approve"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/manager/approvals');

        $this->em->clear();
        /** @var LeaveRequest $reloaded */
        $reloaded = $this->em->getRepository(LeaveRequest::class)->find($request->getId());
        self::assertSame(LeaveRequestStatus::Approved, $reloaded->getStatus());

        // Audit entry recorded with the lead as actor.
        $entries = $this->em->getRepository(LeaveRequestAuditEntry::class)->findAll();
        self::assertCount(1, $entries);
        self::assertSame('approve', $entries[0]->getTransition());
    }

    #[Test]
    public function leadCanRejectWithReason(): void
    {
        $request = $this->storeRequest($this->teamMember);
        $this->em->flush();

        $this->loginAs('lead@leaveflow.test');

        $crawler = $this->client->request('GET', '/manager/approvals/'.$request->getId());
        $rejectForm = $crawler->filter('form[data-testid="reject-form"]')->form();
        $name = $rejectForm->getName();
        $this->client->submit($rejectForm, [
            $name.'[reason]' => 'Teambesetzung im Zeitraum kritisch',
        ]);

        self::assertResponseRedirects('/manager/approvals');

        $this->em->clear();
        /** @var LeaveRequest $reloaded */
        $reloaded = $this->em->getRepository(LeaveRequest::class)->find($request->getId());
        self::assertSame(LeaveRequestStatus::Rejected, $reloaded->getStatus());

        $entry = $this->em->getRepository(LeaveRequestAuditEntry::class)->findOneBy(['transition' => 'reject']);
        self::assertNotNull($entry);
        self::assertSame('Teambesetzung im Zeitraum kritisch', $entry->getReason());
    }

    #[Test]
    public function rejectWithoutReasonIsUnprocessable(): void
    {
        $request = $this->storeRequest($this->teamMember);
        $this->em->flush();

        $this->loginAs('lead@leaveflow.test');

        $crawler = $this->client->request('GET', '/manager/approvals/'.$request->getId());
        $rejectForm = $crawler->filter('form[data-testid="reject-form"]')->form();
        $name = $rejectForm->getName();
        $this->client->submit($rejectForm, [
            $name.'[reason]' => '',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->em->clear();
        /** @var LeaveRequest $reloaded */
        $reloaded = $this->em->getRepository(LeaveRequest::class)->find($request->getId());
        self::assertSame(LeaveRequestStatus::Pending, $reloaded->getStatus());
    }

    #[Test]
    public function leadCannotAccessOwnRequestDetailPage(): void
    {
        $request = $this->storeRequest($this->leadEmployee);
        $this->em->flush();

        $this->loginAs('lead@leaveflow.test');

        $this->client->request('GET', '/manager/approvals/'.$request->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function storeRequest(Employee $employee): LeaveRequest
    {
        // Approval booking needs a 2099 entitlement to deduct from. One per
        // employee is enough — repeat calls hit the same row.
        $existing = $this->em->getRepository(\App\Domain\Entity\LeaveEntitlement::class)
            ->findOneBy(['employee' => $employee, 'year' => 2099]);
        if (null === $existing) {
            $this->em->persist(new \App\Domain\Entity\LeaveEntitlement(
                $employee,
                2099,
                \App\Domain\Enum\LeaveEntitlementType::Regular,
                240.0,
            ));
        }

        $request = new LeaveRequest(
            employee: $employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2099-07-06'),
            endDate: new \DateTimeImmutable('2099-07-10'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2099-04-01 09:00:00'),
        );
        $request->applyBreakdown(new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2099-07-06'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2099-07-07'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2099-07-08'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2099-07-09'), 8.0, LeaveDayStatus::Working),
            new LeaveDay(new \DateTimeImmutable('2099-07-10'), 8.0, LeaveDayStatus::Working),
        ]));
        $this->em->persist($request);

        return $request;
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        $location = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->em->persist($location);

        $schedule = WorkSchedule::autoDistribute(40.0, [
            Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday,
        ]);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        // admin — company-wide approver
        $adminUser = new User($this->company, 'admin@leaveflow.test', UserRole::Admin);
        $adminUser->setHashedPassword($hasher->hashPassword($adminUser, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($adminUser);

        // team lead — Manager role, also an employee
        $leadUser = new User($this->company, 'lead@leaveflow.test', UserRole::Manager);
        $leadUser->setHashedPassword($hasher->hashPassword($leadUser, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($leadUser);

        $this->leadEmployee = new Employee(
            company: $this->company,
            fullName: 'Max Lead',
            employeeNumber: 'EMP-LEAD',
            location: $location,
            workSchedule: $schedule,
            joinedAt: new \DateTimeImmutable('2018-01-01'),
            user: $leadUser,
        );
        $this->em->persist($this->leadEmployee);

        // deputy — Manager role
        $deputyUser = new User($this->company, 'deputy@leaveflow.test', UserRole::Manager);
        $deputyUser->setHashedPassword($hasher->hashPassword($deputyUser, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($deputyUser);

        $this->deputyEmployee = new Employee(
            company: $this->company,
            fullName: 'Maria Deputy',
            employeeNumber: 'EMP-DEP',
            location: $location,
            workSchedule: $schedule,
            joinedAt: new \DateTimeImmutable('2019-01-01'),
            user: $deputyUser,
        );
        $this->em->persist($this->deputyEmployee);

        // regular team member — Employee role
        $memberUser = new User($this->company, 'member@leaveflow.test', UserRole::Employee);
        $memberUser->setHashedPassword($hasher->hashPassword($memberUser, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($memberUser);

        $this->teamMember = new Employee(
            company: $this->company,
            fullName: 'Jane Member',
            employeeNumber: 'EMP-MEM',
            location: $location,
            workSchedule: $schedule,
            joinedAt: new \DateTimeImmutable('2020-01-01'),
            user: $memberUser,
        );
        $this->em->persist($this->teamMember);

        // Engineering dept: lead + deputy, teamMember assigned to it.
        $engineering = new Department($this->company, 'Engineering', lead: $this->leadEmployee, deputy: $this->deputyEmployee);
        $this->em->persist($engineering);

        $this->leadEmployee->assignToDepartment($engineering);
        $this->deputyEmployee->assignToDepartment($engineering);
        $this->teamMember->assignToDepartment($engineering);

        // Sales dept: a different team with its own lead — needed to assert
        // scope isolation.
        $salesLead = new Employee(
            company: $this->company,
            fullName: 'Sam Sales Lead',
            employeeNumber: 'EMP-SAL-LEAD',
            location: $location,
            workSchedule: $schedule,
            joinedAt: new \DateTimeImmutable('2017-01-01'),
        );
        $this->em->persist($salesLead);
        $sales = new Department($this->company, 'Sales', lead: $salesLead);
        $this->em->persist($sales);
        $salesLead->assignToDepartment($sales);

        $this->outsideEmployee = new Employee(
            company: $this->company,
            fullName: 'Oliver Outside',
            employeeNumber: 'EMP-OUT',
            location: $location,
            workSchedule: $schedule,
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->em->persist($this->outsideEmployee);
        $this->outsideEmployee->assignToDepartment($sales);

        $this->urlaub = new AbsenceType($this->company, 'Urlaub', true, true, '#3B82F6');
        $this->em->persist($this->urlaub);

        $this->em->flush();
    }

    private function loginAs(string $email): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form[data-testid="login-form"]')->form([
            '_username' => $email,
            '_password' => AppFixtures::DEFAULT_PASSWORD,
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();
    }
}
