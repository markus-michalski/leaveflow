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

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Enum\LeaveDayType;
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

final class AdminEmployeeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Location $hq;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function adminSeesEmployeeList(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/employees');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mitarbeiter');
    }

    #[Test]
    public function adminCreatesEmployeeWithAutoDistributedSchedule(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/employees/new');
        $form = $crawler->filter('form[data-testid="admin-employee-form"]')->form();

        $formName = $form->getName();
        $this->client->submit($form, [
            $formName.'[fullName]' => 'Tina Test',
            $formName.'[employeeNumber]' => 'EMP-9999',
            $formName.'[location]' => (string) $this->hq->getId(),
            $formName.'[weeklyHours]' => '40',
            $formName.'[workingDays]' => ['1', '2', '3', '4', '5'],
            $formName.'[joinedAt]' => '01.01.2026',
        ]);

        self::assertResponseRedirects('/admin/employees');

        /** @var Employee|null $created */
        $created = $this->em->getRepository(Employee::class)->findOneBy(['employeeNumber' => 'EMP-9999']);
        self::assertNotNull($created);
        self::assertSame('Tina Test', $created->getFullName());
        self::assertSame(40.0, $created->getWorkSchedule()->weeklyHours());
        self::assertSame(8.0, $created->getWorkSchedule()->hoursForDay(Weekday::Monday));
        self::assertFalse($created->hasUser());
    }

    #[Test]
    public function adminCreatesEmployeeWithManualPerDayDistribution(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/employees/new');
        $form = $crawler->filter('form[data-testid="admin-employee-form"]')->form();
        $formName = $form->getName();

        // Mon+Wed 8h, Fri 4h — 20h split asymmetrically across 3 days.
        $this->client->submit($form, [
            $formName.'[fullName]' => 'Pia Parttime',
            $formName.'[employeeNumber]' => 'EMP-PT-1',
            $formName.'[location]' => (string) $this->hq->getId(),
            $formName.'[weeklyHours]' => '20',
            $formName.'[distributionMode]' => 'manual',
            $formName.'[hoursMonday]' => '8',
            $formName.'[hoursWednesday]' => '8',
            $formName.'[hoursFriday]' => '4',
            $formName.'[joinedAt]' => '01.01.2026',
        ]);

        self::assertResponseRedirects('/admin/employees');

        /** @var Employee|null $created */
        $created = $this->em->getRepository(Employee::class)->findOneBy(['employeeNumber' => 'EMP-PT-1']);
        self::assertNotNull($created);
        self::assertSame(20.0, $created->getWorkSchedule()->weeklyHours());
        self::assertSame(8.0, $created->getWorkSchedule()->hoursForDay(Weekday::Monday));
        self::assertSame(0.0, $created->getWorkSchedule()->hoursForDay(Weekday::Tuesday));
        self::assertSame(8.0, $created->getWorkSchedule()->hoursForDay(Weekday::Wednesday));
        self::assertSame(4.0, $created->getWorkSchedule()->hoursForDay(Weekday::Friday));
        self::assertSame([
            Weekday::Monday,
            Weekday::Wednesday,
            Weekday::Friday,
        ], $created->getWorkSchedule()->workingDays());
    }

    #[Test]
    public function manualModeRejectsHoursThatDontSumToWeekly(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/employees/new');
        $form = $crawler->filter('form[data-testid="admin-employee-form"]')->form();
        $formName = $form->getName();

        // weeklyHours=40, but per-day sum = 30 → server rejects.
        $this->client->submit($form, [
            $formName.'[fullName]' => 'Mismatch Mike',
            $formName.'[employeeNumber]' => 'EMP-MM-1',
            $formName.'[location]' => (string) $this->hq->getId(),
            $formName.'[weeklyHours]' => '40',
            $formName.'[distributionMode]' => 'manual',
            $formName.'[hoursMonday]' => '8',
            $formName.'[hoursTuesday]' => '8',
            $formName.'[hoursWednesday]' => '8',
            $formName.'[hoursThursday]' => '6',
            $formName.'[joinedAt]' => '01.01.2026',
        ]);

        // Form re-renders with error; no employee persisted.
        self::assertNull($this->em->getRepository(Employee::class)->findOneBy(['employeeNumber' => 'EMP-MM-1']));
        self::assertStringContainsString(
            'Sum of daily hours',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    #[Test]
    public function editPrefillsManualModeWhenScheduleIsUneven(): void
    {
        $company = $this->em->getRepository(Company::class)->findOneBy([]);
        \assert($company instanceof Company);

        $unevenSchedule = WorkSchedule::manual(20.0, [
            Weekday::Monday->value => 8.0,
            Weekday::Wednesday->value => 8.0,
            Weekday::Friday->value => 4.0,
        ]);
        $employee = new Employee(
            $company,
            'Pia Parttime',
            'EMP-PT-EDIT',
            $this->hq,
            $unevenSchedule,
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->em->persist($employee);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/employees/'.$employee->getId().'/edit');
        $form = $crawler->filter('form[data-testid="admin-employee-form"]')->form();
        $values = $form->getPhpValues();
        $employeeValues = $values['employee'] ?? [];
        \assert(\is_array($employeeValues));

        self::assertSame('manual', $employeeValues['distributionMode'] ?? null);
        self::assertSame('8.00', $employeeValues['hoursMonday'] ?? null);
        self::assertSame('8.00', $employeeValues['hoursWednesday'] ?? null);
        self::assertSame('4.00', $employeeValues['hoursFriday'] ?? null);
        // Non-working days are blank, not "0".
        self::assertSame('', $employeeValues['hoursTuesday'] ?? null);
    }

    #[Test]
    public function duplicateEmployeeNumberShowsFormErrorNotCrash(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $this->submitEmployeeForm('First Jane', 'EMP-0001');
        self::assertResponseRedirects('/admin/employees');
        $this->client->followRedirect();

        $this->submitEmployeeForm('Duplicate Jane', 'EMP-0001');

        // 422 Unprocessable Content is Symfony's standard response for form validation errors.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'EMP-0001 ist im Unternehmen bereits vergeben',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    private function submitEmployeeForm(string $fullName, string $employeeNumber): void
    {
        $crawler = $this->client->request('GET', '/admin/employees/new');
        $form = $crawler->filter('form[data-testid="admin-employee-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[fullName]' => $fullName,
            $formName.'[employeeNumber]' => $employeeNumber,
            $formName.'[location]' => (string) $this->hq->getId(),
            $formName.'[weeklyHours]' => '40',
            $formName.'[workingDays]' => ['1', '2', '3', '4', '5'],
            $formName.'[joinedAt]' => '01.01.2026',
        ]);
    }

    #[Test]
    public function managerIsForbiddenFromEmployeeManagement(): void
    {
        $this->loginAs('manager@leaveflow.test');

        $this->client->request('GET', '/admin/employees');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function leaveRequestsDrilldownShowsEmployeeHistory(): void
    {
        [$employee, $request] = $this->seedEmployeeWithRequest('2026-07-06');
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/employees/'.$employee->getId().'/leave-requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $employee->getFullName());
        self::assertSelectorExists('[data-testid="employee-leave-request-row-'.$request->getId().'"]');
        self::assertSelectorExists('[data-testid="leave-summary-2026"]');
    }

    #[Test]
    public function leaveRequestsDrilldownYearFilterScopesByStartYear(): void
    {
        [$employee, $req2026] = $this->seedEmployeeWithRequest('2026-07-06');
        $req2027 = $this->addRequestForEmployee($employee, '2027-03-15');
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/employees/'.$employee->getId().'/leave-requests?year=2027');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="employee-leave-request-row-'.$req2027->getId().'"]');
        self::assertSelectorNotExists('[data-testid="employee-leave-request-row-'.$req2026->getId().'"]');
    }

    #[Test]
    public function leaveRequestsDrilldownEmptyForEmployeeWithNoHistory(): void
    {
        [$employee] = $this->seedEmployeeWithoutRequests();
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/employees/'.$employee->getId().'/leave-requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="employee-leave-requests-empty"]');
    }

    #[Test]
    public function managerCannotAccessAdminLeaveRequestsDrilldown(): void
    {
        [$employee] = $this->seedEmployeeWithoutRequests();
        $this->em->flush();

        $this->loginAs('manager@leaveflow.test');
        $this->client->request('GET', '/admin/employees/'.$employee->getId().'/leave-requests');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function exportCsvAllReturnsFileWithBothActiveAndInactive(): void
    {
        $company = $this->em->getRepository(Company::class)->findOneBy([]);
        \assert($company instanceof Company);

        $active = new Employee($company, 'Anna Aktiv', 'EMP-A01', $this->hq, WorkSchedule::standardFullTime(), new \DateTimeImmutable('2024-01-01'));
        $inactive = new Employee($company, 'Berta Beendet', 'EMP-I01', $this->hq, WorkSchedule::standardFullTime(), new \DateTimeImmutable('2023-01-01'));
        $inactive->markLeft(new \DateTimeImmutable('2024-12-31'));
        $this->em->persist($active);
        $this->em->persist($inactive);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $csrfToken = $this->fetchExportCsrfToken();
        $this->client->request('POST', '/admin/employees/export', ['filter' => 'all', '_token' => $csrfToken]);

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('mitarbeiter-alle.csv', (string) $response->headers->get('Content-Disposition'));
        self::assertStringStartsWith("\xEF\xBB\xBF", (string) $response->getContent());
        self::assertStringContainsString('Anna Aktiv', (string) $response->getContent());
        self::assertStringContainsString('Berta Beendet', (string) $response->getContent());
    }

    #[Test]
    public function exportCsvActiveFiltersOutInactiveEmployees(): void
    {
        $company = $this->em->getRepository(Company::class)->findOneBy([]);
        \assert($company instanceof Company);

        $active = new Employee($company, 'Clara Current', 'EMP-A02', $this->hq, WorkSchedule::standardFullTime(), new \DateTimeImmutable('2024-01-01'));
        $inactive = new Employee($company, 'Dora Done', 'EMP-I02', $this->hq, WorkSchedule::standardFullTime(), new \DateTimeImmutable('2022-01-01'));
        $inactive->markLeft(new \DateTimeImmutable('2023-12-31'));
        $this->em->persist($active);
        $this->em->persist($inactive);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $csrfToken = $this->fetchExportCsrfToken();
        $this->client->request('POST', '/admin/employees/export', ['filter' => 'active', '_token' => $csrfToken]);

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('mitarbeiter-aktive.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('Clara Current', $body);
        self::assertStringNotContainsString('Dora Done', $body);
    }

    #[Test]
    public function exportCsvInactiveFiltersOutActiveEmployees(): void
    {
        $company = $this->em->getRepository(Company::class)->findOneBy([]);
        \assert($company instanceof Company);

        $active = new Employee($company, 'Emil Engagiert', 'EMP-A03', $this->hq, WorkSchedule::standardFullTime(), new \DateTimeImmutable('2024-01-01'));
        $inactive = new Employee($company, 'Franz Fort', 'EMP-I03', $this->hq, WorkSchedule::standardFullTime(), new \DateTimeImmutable('2021-01-01'));
        $inactive->markLeft(new \DateTimeImmutable('2022-06-30'));
        $this->em->persist($active);
        $this->em->persist($inactive);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $csrfToken = $this->fetchExportCsrfToken();
        $this->client->request('POST', '/admin/employees/export', ['filter' => 'inactive', '_token' => $csrfToken]);

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('mitarbeiter-inaktive.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('Franz Fort', $body);
        self::assertStringNotContainsString('Emil Engagiert', $body);
    }

    #[Test]
    public function exportCsvRejectsInvalidCsrfToken(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('POST', '/admin/employees/export', ['filter' => 'all', '_token' => 'invalid-token']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function exportCsvRequiresAdminRole(): void
    {
        $this->loginAs('manager@leaveflow.test');
        // Role check fires before CSRF validation — any token value will do.
        $this->client->request('POST', '/admin/employees/export', ['filter' => 'all', '_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function fetchExportCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/employees');
        $token = $crawler->filter('dialog#export-modal input[name="_token"]')->attr('value');
        self::assertNotEmpty($token, 'CSRF token not found in export modal');

        return (string) $token;
    }

    /**
     * @return array{0: Employee, 1: LeaveRequest}
     */
    private function seedEmployeeWithRequest(string $startDate): array
    {
        [$employee] = $this->seedEmployeeWithoutRequests();
        $request = $this->addRequestForEmployee($employee, $startDate);

        return [$employee, $request];
    }

    /**
     * @return array{0: Employee}
     */
    private function seedEmployeeWithoutRequests(): array
    {
        $company = $this->em->getRepository(Company::class)->findOneBy([]);
        \assert($company instanceof Company);

        $employee = new Employee(
            $company,
            'Erik Employee',
            'EMP-DRILL',
            $this->hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->em->persist($employee);

        return [$employee];
    }

    private function addRequestForEmployee(Employee $employee, string $startDate): LeaveRequest
    {
        $company = $employee->getCompany();
        $absenceType = $this->em->getRepository(AbsenceType::class)
            ->findOneBy(['company' => $company, 'name' => 'Urlaub']);
        \assert($absenceType instanceof AbsenceType);

        $start = new \DateTimeImmutable($startDate);
        if (\in_array($start->format('N'), ['6', '7'], true)) {
            $start = $start->modify('next monday');
        }

        $request = new LeaveRequest(
            $employee,
            $absenceType,
            $start,
            $start,
            LeaveDayType::FullDay,
            new \DateTimeImmutable($startDate.' 09:00:00'),
        );
        $request->applyBreakdown(new LeaveBreakdown([
            new LeaveDay($start, 8.0, LeaveDayStatus::Working),
        ]));
        $this->em->persist($request);

        return $request;
    }

    private function seed(): void
    {
        $company = new Company('Acme GmbH', 36);
        $this->em->persist($company);

        $this->hq = new Location($company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->em->persist($this->hq);

        // Pre-seeded AbsenceType so the leave-requests drilldown tests can
        // attach LeaveRequests without each test re-creating it.
        $this->em->persist(new AbsenceType($company, 'Urlaub', true, true, '#3B82F6'));

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
            ['manager@leaveflow.test', UserRole::Manager],
            ['employee@leaveflow.test', UserRole::Employee],
        ] as [$email, $role]) {
            $user = new User($company, $email, $role);
            $user->setHashedPassword($hasher->hashPassword($user, AppFixtures::DEFAULT_PASSWORD));
            $this->em->persist($user);
        }

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
