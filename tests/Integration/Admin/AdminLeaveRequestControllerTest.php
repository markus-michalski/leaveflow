<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestAuditEntry;
use App\Domain\Entity\Location;
use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\LeaveBreakdown;
use App\Domain\ValueObject\LeaveDay;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminLeaveRequestControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Employee $employee;
    private AbsenceType $urlaub;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function adminSeesAllLeaveRequestsInCompany(): void
    {
        $this->createPendingRequest();
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/leave-requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Urlaubsanträge');
        self::assertSelectorExists('[data-testid^="admin-leave-request-row-"]');
        self::assertSelectorTextContains('body', 'Test Employee');
    }

    #[Test]
    public function adminSeesEmptyListWhenNoRequestsExist(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/leave-requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="admin-leave-requests-empty"]');
    }

    #[Test]
    public function statusFilterNarrowsList(): void
    {
        $pending = $this->createPendingRequest();
        // Flip the other one to approved by hand — status mutation is a Phase 6 feature,
        // but Doctrine reflection in a test is a legitimate shortcut to seed state.
        $approved = $this->createPendingRequest('2099-08-03', '2099-08-07');
        $this->em->flush();

        $reflection = new \ReflectionProperty(LeaveRequest::class, 'status');
        $reflection->setValue($approved, LeaveRequestStatus::Approved);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/leave-requests?status=approved');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="admin-leave-request-row-'.$approved->getId().'"]');
        self::assertSelectorNotExists('[data-testid="admin-leave-request-row-'.$pending->getId().'"]');

        $this->client->request('GET', '/admin/leave-requests?status=pending');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="admin-leave-request-row-'.$pending->getId().'"]');
        self::assertSelectorNotExists('[data-testid="admin-leave-request-row-'.$approved->getId().'"]');
    }

    #[Test]
    public function showPageRendersRequestDetails(): void
    {
        $request = $this->createPendingRequest();
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/leave-requests/'.$request->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Test Employee');
        self::assertSelectorTextContains('body', 'Urlaub');
    }

    #[Test]
    public function showPageForRequestFromDifferentCompanyIs404(): void
    {
        // Seed a second company + request.
        $other = new Company('Other GmbH', 36);
        $this->em->persist($other);
        $otherLocation = new Location($other, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->em->persist($otherLocation);

        $otherEmployee = new Employee(
            $other,
            'Foreign Fiona',
            'EMP-0001',
            $otherLocation,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2020-01-01'),
        );
        $this->em->persist($otherEmployee);

        $otherAbsenceType = new AbsenceType($other, 'Urlaub', true, true, '#3B82F6');
        $this->em->persist($otherAbsenceType);

        $foreignRequest = new LeaveRequest(
            $otherEmployee,
            $otherAbsenceType,
            new \DateTimeImmutable('2099-07-06'),
            new \DateTimeImmutable('2099-07-06'),
            LeaveDayType::FullDay,
            new \DateTimeImmutable('2099-04-01 09:00:00'),
        );
        $foreignRequest->applyBreakdown(new LeaveBreakdown([
            new LeaveDay(new \DateTimeImmutable('2099-07-06'), 8.0, LeaveDayStatus::Working),
        ]));
        $this->em->persist($foreignRequest);

        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/leave-requests/'.$foreignRequest->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function employeeCannotAccessAdminList(): void
    {
        $this->loginAs('employee@leaveflow.test');
        $this->client->request('GET', '/admin/leave-requests');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function adminChangesTypeRebalancesEntitlementAndNotifies(): void
    {
        // Seed: 240h Regular entitlement, approved Urlaub request consuming 40h.
        $sonderurlaub = new AbsenceType($this->company, 'Sonderurlaub', false, true, '#F59E0B');
        $this->em->persist($sonderurlaub);

        $entitlement = new LeaveEntitlement(
            $this->employee,
            2099,
            LeaveEntitlementType::Regular,
            240.0,
        );
        $entitlement->consume(40.0);  // mirror the approved request below
        $this->em->persist($entitlement);

        $request = $this->createPendingRequest();
        $this->approveInPlace($request);
        $this->em->flush();
        $requestId = $request->getId();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/leave-requests/'.$requestId.'/change-type');
        $form = $crawler->filter('form[data-testid="admin-type-change-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[newType]' => (string) $sonderurlaub->getId(),
            $formName.'[reason]' => 'Bereavement leave should not deduct vacation.',
        ]);

        self::assertResponseRedirects('/admin/leave-requests/'.$requestId);

        $this->em->clear();

        /** @var LeaveRequest $reloaded */
        $reloaded = $this->em->find(LeaveRequest::class, $requestId);
        self::assertSame('Sonderurlaub', $reloaded->getAbsenceType()->getName());

        // Sonderurlaub does not deduct → entitlement returns to 0 used.
        /** @var LeaveEntitlement $reloadedEntitlement */
        $reloadedEntitlement = $this->em->find(LeaveEntitlement::class, $entitlement->getId());
        self::assertSame(0.0, $reloadedEntitlement->getHoursUsed());

        // Audit entry written with old/new type + reason.
        $audit = $this->em->getRepository(LeaveRequestAuditEntry::class)->findBy(
            ['leaveRequest' => $reloaded, 'transition' => 'admin_type_change'],
        );
        self::assertCount(1, $audit);
        self::assertSame('Urlaub', $audit[0]->getFromAbsenceType()?->getName());
        self::assertSame('Sonderurlaub', $audit[0]->getToAbsenceType()?->getName());
        self::assertSame('Bereavement leave should not deduct vacation.', $audit[0]->getReason());

        // Notification persisted for the employee.
        $notifications = $this->em->getRepository(Notification::class)->findBy(
            ['recipient' => $this->employee->getUser(), 'type' => NotificationType::AdminTypeChange],
        );
        self::assertCount(1, $notifications);
        self::assertSame('Urlaub', $notifications[0]->getPayload()['oldTypeName']);
        self::assertSame('Sonderurlaub', $notifications[0]->getPayload()['newTypeName']);
    }

    #[Test]
    public function changeTypeRedirectsForNonApprovedRequestWithFlash(): void
    {
        $sonderurlaub = new AbsenceType($this->company, 'Sonderurlaub', false, true, '#F59E0B');
        $this->em->persist($sonderurlaub);

        $request = $this->createPendingRequest();
        $this->em->flush();
        $requestId = $request->getId();

        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/leave-requests/'.$requestId.'/change-type');

        self::assertResponseRedirects('/admin/leave-requests/'.$requestId);
        // Flash error indicates the only-approved guard fired.
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'genehmigte Anträge');
    }

    #[Test]
    public function changeTypeButtonHiddenForPendingRequestOnShowPage(): void
    {
        $request = $this->createPendingRequest();
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/leave-requests/'.$request->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="admin-leave-request-change-type-link"]');
    }

    #[Test]
    public function changeTypeButtonVisibleForApprovedRequestOnShowPage(): void
    {
        $request = $this->createPendingRequest();
        $this->approveInPlace($request);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/leave-requests/'.$request->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="admin-leave-request-change-type-link"]');
    }

    #[Test]
    public function employeeCannotAccessChangeType(): void
    {
        $request = $this->createPendingRequest();
        $this->approveInPlace($request);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $this->client->request('GET', '/admin/leave-requests/'.$request->getId().'/change-type');

        self::assertResponseStatusCodeSame(403);
    }

    private function approveInPlace(LeaveRequest $request): void
    {
        // Bypass the workflow — Phase 6 covers the proper transition; this
        // shortcut just gets a request into Approved status for testing the
        // type-change flow.
        $reflection = new \ReflectionProperty(LeaveRequest::class, 'status');
        $reflection->setValue($request, LeaveRequestStatus::Approved);
    }

    private function createPendingRequest(string $start = '2099-07-06', string $end = '2099-07-10'): LeaveRequest
    {
        $startDate = new \DateTimeImmutable($start);
        $endDate = new \DateTimeImmutable($end);

        $days = [];
        for ($cursor = $startDate; $cursor <= $endDate; $cursor = $cursor->modify('+1 day')) {
            $weekday = (int) $cursor->format('N');
            if ($weekday >= 6) {
                $days[] = new LeaveDay($cursor, 0.0, LeaveDayStatus::Excluded, \App\Domain\Enum\ExclusionReason::Weekend);
                continue;
            }
            $days[] = new LeaveDay($cursor, 8.0, LeaveDayStatus::Working);
        }

        $request = new LeaveRequest(
            $this->employee,
            $this->urlaub,
            $startDate,
            $endDate,
            LeaveDayType::FullDay,
            new \DateTimeImmutable('2099-04-01 09:00:00'),
        );
        $request->applyBreakdown(new LeaveBreakdown($days));
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

        $admin = new User($this->company, 'admin@leaveflow.test', UserRole::Admin);
        $admin->setHashedPassword($hasher->hashPassword($admin, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($admin);

        $employeeUser = new User($this->company, 'employee@leaveflow.test', UserRole::Employee);
        $employeeUser->setHashedPassword($hasher->hashPassword($employeeUser, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($employeeUser);

        $this->employee = new Employee(
            $this->company,
            'Test Employee',
            'EMP-0001',
            $location,
            $schedule,
            new \DateTimeImmutable('2020-01-01'),
            $employeeUser,
        );
        $this->em->persist($this->employee);

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
