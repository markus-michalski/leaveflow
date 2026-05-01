<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\BlackoutPeriod;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveDayType;
use App\Domain\Enum\LeaveRequestStatus;
use App\Domain\Enum\UserRole;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TeamCalendarControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Department $engineering;
    private Department $sales;
    private Employee $employee;
    private Employee $alice;
    private Employee $bob;
    private AbsenceType $urlaub;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function pageRendersForLoggedInUser(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/team/calendar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Team-Kalender');
        self::assertSelectorExists('[data-testid="team-calendar"]');
        self::assertSelectorExists('[data-testid="team-calendar-filters"]');
    }

    #[Test]
    public function pageIsForbiddenForGuests(): void
    {
        $this->client->request('GET', '/team/calendar');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function feedReturnsApprovedLeavesInRange(): void
    {
        $this->createApprovedLeave($this->alice, '2026-06-08', '2026-06-12');
        $this->createApprovedLeave($this->bob, '2026-07-01', '2026-07-05');

        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/team/calendar/events.json', [
            'start' => '2026-06-01',
            'end' => '2026-06-30',
            'team' => (string) $this->engineering->getId(),
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        $titles = array_map(static fn (array $e): string => $e['title'], $payload);
        self::assertContains('Alice — Urlaub', $titles, 'June leave should appear');
        self::assertNotContains('Bob — Urlaub', $titles, 'July leave should NOT appear in June range');
    }

    #[Test]
    public function feedExcludesPendingRequests(): void
    {
        // Pending leave should not show on the team calendar.
        $pending = new LeaveRequest(
            employee: $this->alice,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable('2026-06-08'),
            endDate: new \DateTimeImmutable('2026-06-12'),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-05-01'),
        );
        $this->em->persist($pending);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/team/calendar/events.json', [
            'start' => '2026-06-01',
            'end' => '2026-06-30',
            'team' => (string) $this->engineering->getId(),
        ]);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame([], $payload, 'Pending leaves must not leak into the team-calendar feed');
    }

    #[Test]
    public function feedScopesToRequestedDepartment(): void
    {
        $this->createApprovedLeave($this->alice, '2026-06-08', '2026-06-12'); // Engineering
        $charlie = $this->buildEmployee('Charlie', 'EMP-CH', $this->sales);
        $this->em->persist($charlie);
        $this->em->flush();
        $this->createApprovedLeave($charlie, '2026-06-08', '2026-06-12'); // Sales

        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/team/calendar/events.json', [
            'start' => '2026-06-01',
            'end' => '2026-06-30',
            'team' => (string) $this->sales->getId(),
        ]);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $titles = array_map(static fn (array $e): string => $e['title'], $payload);
        self::assertContains('Charlie — Urlaub', $titles);
        self::assertNotContains('Alice — Urlaub', $titles, 'Engineering leave must not leak into Sales filter');
    }

    #[Test]
    public function feedIncludesBlackoutsAsBackgroundEvents(): void
    {
        $blackout = new BlackoutPeriod(
            company: $this->company,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Werksferien',
        );
        $this->em->persist($blackout);
        $this->em->flush();

        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/team/calendar/events.json', [
            'start' => '2026-12-01',
            'end' => '2026-12-31',
        ]);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $blackoutEvents = array_filter($payload, static fn (array $e): bool => 'background' === ($e['display'] ?? null));
        self::assertCount(1, $blackoutEvents);
        $first = array_values($blackoutEvents)[0];
        self::assertSame('Werksferien', $first['title']);
        self::assertSame('blackout', $first['extendedProps']['kind']);
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        $location = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->em->persist($location);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $employeeUser = new User($this->company, 'employee@leaveflow.test', UserRole::Employee);
        $employeeUser->setHashedPassword($hasher->hashPassword($employeeUser, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($employeeUser);

        $this->engineering = new Department($this->company, 'Engineering');
        $this->em->persist($this->engineering);

        $this->sales = new Department($this->company, 'Sales');
        $this->em->persist($this->sales);

        $this->employee = new Employee(
            company: $this->company,
            fullName: 'Test User',
            employeeNumber: 'EMP-USER',
            location: $location,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->employee->assignToDepartment($this->engineering);
        $this->employee->linkUser($employeeUser);
        $this->em->persist($this->employee);

        $this->alice = $this->buildEmployee('Alice', 'EMP-A', $this->engineering, $location);
        $this->em->persist($this->alice);

        $this->bob = $this->buildEmployee('Bob', 'EMP-B', $this->engineering, $location);
        $this->em->persist($this->bob);

        $this->urlaub = new AbsenceType(
            company: $this->company,
            name: 'Urlaub',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );
        $this->em->persist($this->urlaub);

        $this->em->flush();
    }

    private function buildEmployee(
        string $name,
        string $number,
        Department $department,
        ?Location $location = null,
    ): Employee {
        if (null === $location) {
            $location = $this->em->getRepository(Location::class)->findOneBy([]);
        }
        if (!$location instanceof Location) {
            throw new \LogicException('Test seed expected at least one Location.');
        }
        $employee = new Employee(
            company: $this->company,
            fullName: $name,
            employeeNumber: $number,
            location: $location,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $employee->assignToDepartment($department);

        return $employee;
    }

    private function createApprovedLeave(Employee $employee, string $start, string $end): LeaveRequest
    {
        $request = new LeaveRequest(
            employee: $employee,
            absenceType: $this->urlaub,
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            dayType: LeaveDayType::FullDay,
            requestedAt: new \DateTimeImmutable('2026-01-01'),
        );
        // Force into Approved state — we don't want to wire the full workflow.
        $reflection = new \ReflectionProperty(LeaveRequest::class, 'status');
        $reflection->setValue($request, LeaveRequestStatus::Approved);

        $this->em->persist($request);
        $this->em->flush();

        return $request;
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
