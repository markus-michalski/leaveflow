<?php

declare(strict_types=1);

namespace App\Tests\Integration\My;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MyLeaveRequestControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Employee $employee;
    private AbsenceType $urlaub;
    private AbsenceType $krankheit;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function employeeSeesEmptyOwnList(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/my/leave-requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Meine Urlaubsanträge');
        self::assertSelectorExists('[data-testid="my-leave-requests-empty"]');
    }

    #[Test]
    public function employeeSeesOwnRequestInList(): void
    {
        $this->createStoredRequest();
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $this->client->request('GET', '/my/leave-requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid^="my-leave-request-row-"]');
        self::assertSelectorTextContains('body', 'Urlaub');
    }

    #[Test]
    public function employeeCreatesLeaveRequestSuccessfully(): void
    {
        $this->grantEntitlement(2099, 240.0);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $crawler = $this->client->request('GET', '/my/leave-requests/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[data-testid="my-leave-request-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[startDate]' => '06.07.2099',
            $formName.'[endDate]' => '10.07.2099',
            $formName.'[dayType]' => 'full_day',
            $formName.'[absenceType]' => (string) $this->urlaub->getId(),
        ]);

        self::assertResponseRedirects('/my/leave-requests');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Antrag');

        /** @var LeaveRequest|null $created */
        $created = $this->em->getRepository(LeaveRequest::class)->findOneBy(['employee' => $this->employee]);
        self::assertNotNull($created);
        self::assertSame(40.0, $created->getTotalHours());
        self::assertSame(LeaveRequestStatus::Pending, $created->getStatus());
        self::assertCount(5, $created->getDays());
    }

    #[Test]
    public function submittingWithInsufficientBalanceShowsInlineError(): void
    {
        // Only 16h granted — request asks for 40h.
        $this->grantEntitlement(2099, 16.0);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $crawler = $this->client->request('GET', '/my/leave-requests/new');
        $form = $crawler->filter('form[data-testid="my-leave-request-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[startDate]' => '06.07.2099',
            $formName.'[endDate]' => '10.07.2099',
            $formName.'[dayType]' => 'full_day',
            $formName.'[absenceType]' => (string) $this->urlaub->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('nicht ausreichend', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(LeaveRequest::class)->count([]));
    }

    #[Test]
    public function submittingEndBeforeStartShowsInlineError(): void
    {
        $this->grantEntitlement(2099, 240.0);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $crawler = $this->client->request('GET', '/my/leave-requests/new');
        $form = $crawler->filter('form[data-testid="my-leave-request-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[startDate]' => '10.07.2099',
            $formName.'[endDate]' => '06.07.2099',
            $formName.'[dayType]' => 'full_day',
            $formName.'[absenceType]' => (string) $this->urlaub->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enddatum', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(LeaveRequest::class)->count([]));
    }

    #[Test]
    public function submittingNonDeductingTypeSkipsBalanceCheck(): void
    {
        // No entitlement at all — but Krankheit is non-deducting.
        $this->loginAs('employee@leaveflow.test');
        $crawler = $this->client->request('GET', '/my/leave-requests/new');
        $form = $crawler->filter('form[data-testid="my-leave-request-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[startDate]' => '06.07.2099',
            $formName.'[endDate]' => '06.07.2099',
            $formName.'[dayType]' => 'half_day_am',
            $formName.'[absenceType]' => (string) $this->krankheit->getId(),
        ]);

        self::assertResponseRedirects('/my/leave-requests');

        /** @var LeaveRequest|null $created */
        $created = $this->em->getRepository(LeaveRequest::class)->findOneBy(['employee' => $this->employee]);
        self::assertNotNull($created);
        self::assertSame(4.0, $created->getTotalHours(), 'full-time 40h/5d => 8h * 0.5 = 4h');
        self::assertSame(LeaveRequestStatus::Recorded, $created->getStatus(), 'non-approval type lands in Recorded, not Pending');
    }

    #[Test]
    public function previewEndpointReturnsBreakdownFrame(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/my/leave-requests/preview', [
            'start_date' => '06.07.2099',
            'end_date' => '10.07.2099',
            'day_type' => 'full_day',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="leave-preview-content"]');
        self::assertSelectorExists('[data-testid="leave-preview-summary"]');
    }

    #[Test]
    public function previewEndpointReportsEndBeforeStart(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/my/leave-requests/preview', [
            'start_date' => '10.07.2099',
            'end_date' => '06.07.2099',
            'day_type' => 'full_day',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="leave-preview-error"]');
    }

    #[Test]
    public function previewWithMissingDatesRendersPlaceholder(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/my/leave-requests/preview');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="leave-preview-error"]');
    }

    #[Test]
    public function showingOwnRequestDisplaysDayBreakdown(): void
    {
        $request = $this->createStoredRequest();
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $this->client->request('GET', '/my/leave-requests/'.$request->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="leave-request-total-hours"]', '40,00');
    }

    #[Test]
    public function showingForeignRequestReturnsNotFound(): void
    {
        // Request by a different employee from same company.
        $other = new Employee(
            $this->company,
            'Other Olga',
            'EMP-9999',
            $this->employee->getLocation(),
            $this->employee->getWorkSchedule(),
            new \DateTimeImmutable('2020-01-01'),
        );
        $this->em->persist($other);
        $this->em->flush();

        $foreignRequest = $this->createStoredRequestFor($other);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $this->client->request('GET', '/my/leave-requests/'.$foreignRequest->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function employeeCanCancelOwnPendingRequest(): void
    {
        $request = $this->createStoredRequest();
        $this->em->flush();
        $id = $request->getId();

        $this->loginAs('employee@leaveflow.test');
        $crawler = $this->client->request('GET', '/my/leave-requests/'.$id);
        self::assertResponseIsSuccessful();

        $cancelButton = $crawler->filter('[data-testid="leave-request-cancel"]');
        self::assertCount(1, $cancelButton, 'Cancel button must be rendered for pending requests.');

        $form = $cancelButton->form();
        // The confirm() dialog on the form would block the browser submit but
        // doesn't affect the HTTP request from the test client.
        $this->client->submit($form);

        self::assertResponseRedirects('/my/leave-requests/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'storniert');

        $this->em->clear();
        /** @var LeaveRequest $reloaded */
        $reloaded = $this->em->getRepository(LeaveRequest::class)->find($id);
        self::assertSame(LeaveRequestStatus::Cancelled, $reloaded->getStatus());
    }

    #[Test]
    public function cancelButtonHiddenForCancelledRequest(): void
    {
        $request = $this->createStoredRequest();
        $request->setStatus(LeaveRequestStatus::Cancelled);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $this->client->request('GET', '/my/leave-requests/'.$request->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="leave-request-cancel"]');
        self::assertSelectorNotExists('[data-testid="leave-request-request-cancel"]');
    }

    #[Test]
    public function employeeCanRequestCancellationOfApprovedFutureLeave(): void
    {
        $request = $this->createStoredRequest();
        $request->setStatus(LeaveRequestStatus::Approved);
        $this->em->flush();
        $id = $request->getId();

        $this->loginAs('employee@leaveflow.test');
        $crawler = $this->client->request('GET', '/my/leave-requests/'.$id);
        self::assertResponseIsSuccessful();

        $requestCancelButton = $crawler->filter('[data-testid="leave-request-request-cancel"]');
        self::assertCount(1, $requestCancelButton, 'Request-cancel button must appear on approved future leave.');

        $this->client->submit($requestCancelButton->form());

        self::assertResponseRedirects('/my/leave-requests/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Stornierung');

        $this->em->clear();
        /** @var LeaveRequest $reloaded */
        $reloaded = $this->em->getRepository(LeaveRequest::class)->find($id);
        self::assertSame(LeaveRequestStatus::CancelRequested, $reloaded->getStatus());
    }

    #[Test]
    public function requestCancelButtonHiddenWhenLeaveAlreadyStarted(): void
    {
        // Build a request whose start date is in the past — clock can't be
        // moved, so we pick dates before the fixture seed's "now".
        $request = new LeaveRequest(
            $this->employee,
            $this->urlaub,
            new \DateTimeImmutable('2020-01-06'),
            new \DateTimeImmutable('2020-01-10'),
            \App\Domain\Enum\LeaveDayType::FullDay,
            new \DateTimeImmutable('2019-12-01 09:00:00'),
        );
        $request->applyBreakdown(new \App\Domain\ValueObject\LeaveBreakdown([
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2020-01-06'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2020-01-07'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2020-01-08'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2020-01-09'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2020-01-10'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
        ]));
        $request->setStatus(LeaveRequestStatus::Approved);
        $this->em->persist($request);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $this->client->request('GET', '/my/leave-requests/'.$request->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="leave-request-request-cancel"]');
    }

    #[Test]
    public function requestingCancellationOnForeignRequestReturnsNotFound(): void
    {
        $other = new Employee(
            $this->company,
            'Other Olga',
            'EMP-7777',
            $this->employee->getLocation(),
            $this->employee->getWorkSchedule(),
            new \DateTimeImmutable('2020-01-01'),
        );
        $this->em->persist($other);
        $this->em->flush();

        $foreignRequest = $this->createStoredRequestFor($other);
        $foreignRequest->setStatus(LeaveRequestStatus::Approved);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        $this->client->request('POST', '/my/leave-requests/'.$foreignRequest->getId().'/request-cancel', [
            '_token' => 'ignored',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function cancellingForeignRequestReturnsNotFound(): void
    {
        $other = new Employee(
            $this->company,
            'Other Olga',
            'EMP-8888',
            $this->employee->getLocation(),
            $this->employee->getWorkSchedule(),
            new \DateTimeImmutable('2020-01-01'),
        );
        $this->em->persist($other);
        $this->em->flush();

        $foreignRequest = $this->createStoredRequestFor($other);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');
        // 404 fires before the CSRF check, so any token value works here.
        $this->client->request('POST', '/my/leave-requests/'.$foreignRequest->getId().'/cancel', [
            '_token' => 'ignored',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function userWithoutEmployeeIsRedirectedToProfile(): void
    {
        // admin user has no Employee link in this test seed.
        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/my/leave-requests');

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Mitarbeiterprofil');
    }

    private function createStoredRequest(): LeaveRequest
    {
        return $this->createStoredRequestFor($this->employee);
    }

    private function createStoredRequestFor(Employee $employee): LeaveRequest
    {
        $request = new LeaveRequest(
            $employee,
            $this->urlaub,
            new \DateTimeImmutable('2099-07-06'),
            new \DateTimeImmutable('2099-07-10'),
            \App\Domain\Enum\LeaveDayType::FullDay,
            new \DateTimeImmutable('2099-04-01 09:00:00'),
        );
        // Full-time 40h/5d: Mon-Fri = 5 working days * 8h = 40h.
        $request->applyBreakdown(new \App\Domain\ValueObject\LeaveBreakdown([
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2099-07-06'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2099-07-07'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2099-07-08'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2099-07-09'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
            new \App\Domain\ValueObject\LeaveDay(new \DateTimeImmutable('2099-07-10'), 8.0, \App\Domain\Enum\LeaveDayStatus::Working),
        ]));
        $this->em->persist($request);

        return $request;
    }

    private function grantEntitlement(int $year, float $hours): LeaveEntitlement
    {
        $e = new LeaveEntitlement($this->employee, $year, LeaveEntitlementType::Regular, $hours);
        $this->em->persist($e);

        return $e;
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        // Berlin as work location — minimal holiday footprint in summer.
        $location = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->em->persist($location);

        // Full-time schedule for the employee — 5 days * 8h = 40h.
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

        $this->krankheit = new AbsenceType($this->company, 'Krankheit', false, false, '#EF4444');
        $this->em->persist($this->krankheit);

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
