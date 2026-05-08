<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminEntitlementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Employee $employee;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function adminSeesEntitlementList(): void
    {
        $this->createEntitlement(2025, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        // Default filter is the current year — pass `year=all` so the 2025
        // fixture row is visible regardless of when the test runs.
        $this->client->request('GET', '/admin/entitlements?year=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Urlaubskonten');
        self::assertSelectorExists('[data-testid^="entitlement-row-"]');
        self::assertSelectorTextContains('body', 'Max Mustermann');
    }

    #[Test]
    public function adminUpdatesExpiresAt(): void
    {
        $entitlement = $this->createEntitlement(
            2024,
            LeaveEntitlementType::Carryover,
            40.0,
            new \DateTimeImmutable('2025-03-31'),
        );
        $this->em->flush();
        $id = $entitlement->getId();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/entitlements/'.$id.'/expires');
        $form = $crawler->filter('form[data-testid="admin-entitlement-expiry-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[expiresAt]' => '30.09.2025',
        ]);

        self::assertResponseRedirects('/admin/entitlements');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Ablauffrist wurde aktualisiert');

        $this->em->clear();
        /** @var LeaveEntitlement $reloaded */
        $reloaded = $this->em->getRepository(LeaveEntitlement::class)->find($id);
        self::assertNotNull($reloaded->getExpiresAt());
        self::assertSame('2025-09-30', $reloaded->getExpiresAt()->format('Y-m-d'));
    }

    #[Test]
    public function adminClearsExpiresAt(): void
    {
        $entitlement = $this->createEntitlement(
            2024,
            LeaveEntitlementType::Carryover,
            40.0,
            new \DateTimeImmutable('2025-03-31'),
        );
        $this->em->flush();
        $id = $entitlement->getId();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/entitlements/'.$id.'/expires');
        $form = $crawler->filter('form[data-testid="admin-entitlement-expiry-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[expiresAt]' => '',
        ]);

        self::assertResponseRedirects('/admin/entitlements');

        $this->em->clear();
        /** @var LeaveEntitlement $reloaded */
        $reloaded = $this->em->getRepository(LeaveEntitlement::class)->find($id);
        self::assertNull($reloaded->getExpiresAt());
    }

    #[Test]
    public function managerIsForbidden(): void
    {
        $this->loginAs('manager@leaveflow.test');

        $this->client->request('GET', '/admin/entitlements');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function adminCreatesRegularEntitlement(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/entitlements/new');
        $form = $crawler->filter('form[data-testid="admin-entitlement-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[employee]' => (string) $this->employee->getId(),
            $formName.'[year]' => '2026',
            $formName.'[type]' => LeaveEntitlementType::Regular->value,
            $formName.'[hoursGranted]' => '240',
            $formName.'[expiresAt]' => '',
        ]);

        self::assertResponseRedirects('/admin/entitlements');

        /** @var LeaveEntitlement|null $created */
        $created = $this->em->getRepository(LeaveEntitlement::class)->findOneBy([
            'employee' => $this->employee,
            'year' => 2026,
            'type' => LeaveEntitlementType::Regular,
        ]);
        self::assertNotNull($created);
        self::assertSame(240.0, $created->getHoursGranted());
        self::assertSame(0.0, $created->getHoursUsed());
        self::assertNull($created->getExpiresAt());
    }

    #[Test]
    public function adminCreatesCarryoverEntitlementWithExpiry(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/entitlements/new');
        $form = $crawler->filter('form[data-testid="admin-entitlement-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[employee]' => (string) $this->employee->getId(),
            $formName.'[year]' => '2026',
            $formName.'[type]' => LeaveEntitlementType::Carryover->value,
            $formName.'[hoursGranted]' => '16',
            $formName.'[expiresAt]' => '31.03.2026',
        ]);

        self::assertResponseRedirects('/admin/entitlements');

        /** @var LeaveEntitlement|null $created */
        $created = $this->em->getRepository(LeaveEntitlement::class)->findOneBy([
            'employee' => $this->employee,
            'year' => 2026,
            'type' => LeaveEntitlementType::Carryover,
        ]);
        self::assertNotNull($created);
        self::assertSame('2026-03-31', $created->getExpiresAt()?->format('Y-m-d'));
    }

    #[Test]
    public function duplicateEntitlementShowsFormError(): void
    {
        $this->createEntitlement(2026, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/entitlements/new');
        $form = $crawler->filter('form[data-testid="admin-entitlement-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[employee]' => (string) $this->employee->getId(),
            $formName.'[year]' => '2026',
            $formName.'[type]' => LeaveEntitlementType::Regular->value,
            $formName.'[hoursGranted]' => '240',
            $formName.'[expiresAt]' => '',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('existiert', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function indexDefaultsToCurrentYear(): void
    {
        $currentYear = (int) (new \DateTimeImmutable())->format('Y');
        $previousYear = $currentYear - 1;

        $previous = $this->createEntitlement($previousYear, LeaveEntitlementType::Regular, 240.0, null);
        $current = $this->createEntitlement($currentYear, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/entitlements');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="entitlement-row-'.$current->getId().'"]');
        self::assertSelectorNotExists('[data-testid="entitlement-row-'.$previous->getId().'"]');
    }

    #[Test]
    public function indexExplicitYearShowsOnlyThatYear(): void
    {
        $entry2025 = $this->createEntitlement(2025, LeaveEntitlementType::Regular, 240.0, null);
        $entry2027 = $this->createEntitlement(2027, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/entitlements?year=2025');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="entitlement-row-'.$entry2025->getId().'"]');
        self::assertSelectorNotExists('[data-testid="entitlement-row-'.$entry2027->getId().'"]');
    }

    #[Test]
    public function indexAllYearsShowsEverything(): void
    {
        $entry2025 = $this->createEntitlement(2025, LeaveEntitlementType::Regular, 240.0, null);
        $entry2027 = $this->createEntitlement(2027, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/entitlements?year=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="entitlement-row-'.$entry2025->getId().'"]');
        self::assertSelectorExists('[data-testid="entitlement-row-'.$entry2027->getId().'"]');
    }

    #[Test]
    public function indexInvalidYearFallsBackToCurrentYear(): void
    {
        $currentYear = (int) (new \DateTimeImmutable())->format('Y');
        $current = $this->createEntitlement($currentYear, LeaveEntitlementType::Regular, 240.0, null);
        $previous = $this->createEntitlement($currentYear - 1, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        // Garbage value: not "all", not numeric — controller defaults to current year.
        $this->client->request('GET', '/admin/entitlements?year=foo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="entitlement-row-'.$current->getId().'"]');
        self::assertSelectorNotExists('[data-testid="entitlement-row-'.$previous->getId().'"]');
    }

    #[Test]
    public function yearDropdownContainsAvailableYearsAndCurrentYear(): void
    {
        $currentYear = (int) (new \DateTimeImmutable())->format('Y');
        // Seed two non-current years so the dropdown's option set is well-defined.
        $this->createEntitlement(2024, LeaveEntitlementType::Regular, 240.0, null);
        $this->createEntitlement(2025, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/entitlements');

        self::assertResponseIsSuccessful();
        $options = $crawler
            ->filter('[data-testid="admin-entitlements-year-select"] option')
            ->each(static fn ($n): string => trim($n->attr('value') ?? ''));

        self::assertContains((string) $currentYear, $options);
        self::assertContains('2024', $options);
        self::assertContains('2025', $options);
        self::assertContains('all', $options);
    }

    #[Test]
    public function newFormRejectsExpiresAtBelowBurlgFloor(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/entitlements/new');
        $form = $crawler->filter('form[data-testid="admin-entitlement-form"]')->form();
        $formName = $form->getName();

        // Bug case from issue #23: 2027 carryover with expiry in 2026.
        $this->client->submit($form, [
            $formName.'[employee]' => (string) $this->employee->getId(),
            $formName.'[year]' => '2027',
            $formName.'[type]' => LeaveEntitlementType::Carryover->value,
            $formName.'[hoursGranted]' => '40',
            $formName.'[expiresAt]' => '23.05.2026',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // No row created.
        $created = $this->em->getRepository(LeaveEntitlement::class)->findOneBy([
            'employee' => $this->employee,
            'year' => 2027,
            'type' => LeaveEntitlementType::Carryover,
        ]);
        self::assertNull($created);
    }

    #[Test]
    public function newFormRejectsExpiresAtOnRegularEntitlement(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/entitlements/new');
        $form = $crawler->filter('form[data-testid="admin-entitlement-form"]')->form();
        $formName = $form->getName();

        // Regular vacation has no per-record expiry — only Carryover does.
        $this->client->submit($form, [
            $formName.'[employee]' => (string) $this->employee->getId(),
            $formName.'[year]' => '2027',
            $formName.'[type]' => LeaveEntitlementType::Regular->value,
            $formName.'[hoursGranted]' => '240',
            $formName.'[expiresAt]' => '31.03.2027',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $created = $this->em->getRepository(LeaveEntitlement::class)->findOneBy([
            'employee' => $this->employee,
            'year' => 2027,
            'type' => LeaveEntitlementType::Regular,
        ]);
        self::assertNull($created);
    }

    #[Test]
    public function editExpiryFormRejectsDateBelowBurlgFloor(): void
    {
        $entitlement = $this->createEntitlement(
            2027,
            LeaveEntitlementType::Carryover,
            240.0,
            new \DateTimeImmutable('2027-03-31'),
        );
        $this->em->flush();
        $id = $entitlement->getId();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/entitlements/'.$id.'/expires');
        $form = $crawler->filter('form[data-testid="admin-entitlement-expiry-form"]')->form();
        $formName = $form->getName();

        // Bug case: 2027 carryover, admin tries to set expiry to 2026.
        $this->client->submit($form, [
            $formName.'[expiresAt]' => '23.05.2026',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // Original expiry preserved.
        $this->em->clear();
        /** @var LeaveEntitlement $reloaded */
        $reloaded = $this->em->getRepository(LeaveEntitlement::class)->find($id);
        self::assertSame('2027-03-31', $reloaded->getExpiresAt()?->format('Y-m-d'));
    }

    #[Test]
    public function editExpiryReturns404ForRegularEntitlement(): void
    {
        $regular = $this->createEntitlement(
            2027,
            LeaveEntitlementType::Regular,
            240.0,
            null,
        );
        $this->em->flush();
        $id = $regular->getId();

        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/entitlements/'.$id.'/expires');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function indexHidesEditExpiryButtonForRegularEntitlements(): void
    {
        $regular = $this->createEntitlement(2027, LeaveEntitlementType::Regular, 240.0, null);
        $carryover = $this->createEntitlement(
            2027,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2027-03-31'),
        );
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        // 2027 fixtures — explicit year filter so the test isn't tied to "now".
        $crawler = $this->client->request('GET', '/admin/entitlements?year=2027');

        self::assertResponseIsSuccessful();
        // Carryover row has the edit-expiry link, Regular row does not.
        self::assertSelectorExists(
            \sprintf('[data-testid="entitlement-row-%d"] a[href*="/expires"]', $carryover->getId())
        );
        self::assertSelectorNotExists(
            \sprintf('[data-testid="entitlement-row-%d"] a[href*="/expires"]', $regular->getId())
        );
    }

    #[Test]
    public function archivedEmployeeNotOfferedInDropdown(): void
    {
        $archived = new Employee(
            $this->company,
            'Archived Alice',
            'EMP-9999',
            $this->employee->getLocation(),
            $this->employee->getWorkSchedule(),
            new \DateTimeImmutable('2020-01-01'),
            null,
            new \DateTimeImmutable('2024-12-31'),
        );
        $this->em->persist($archived);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/entitlements/new');
        $options = $crawler->filter('select[name$="[employee]"] option')->each(
            static fn ($node) => $node->text(),
        );

        self::assertNotContains('Archived Alice (EMP-9999)', $options);
    }

    private function createEntitlement(
        int $year,
        LeaveEntitlementType $type,
        float $granted,
        ?\DateTimeImmutable $expiresAt,
    ): LeaveEntitlement {
        $entitlement = new LeaveEntitlement(
            $this->employee,
            $year,
            $type,
            $granted,
            $expiresAt,
        );
        $this->em->persist($entitlement);

        return $entitlement;
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        $location = new Location($this->company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->em->persist($location);

        $schedule = WorkSchedule::autoDistribute(40.0, [
            Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday,
        ]);
        $this->employee = new Employee(
            $this->company,
            'Max Mustermann',
            'EMP-0001',
            $location,
            $schedule,
            new \DateTimeImmutable('2024-01-01'),
        );
        $this->em->persist($this->employee);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
            ['manager@leaveflow.test', UserRole::Manager],
        ] as [$email, $role]) {
            $user = new User($this->company, $email, $role);
            $user->setHashedPassword($hasher->hashPassword($user, AppFixtures::DEFAULT_PASSWORD));
            $this->em->persist($user);
        }

        $this->em->flush();
    }

    #[Test]
    public function indexShowsSourceYearHintForCarryoverEntries(): void
    {
        $carryover = $this->createEntitlement(
            2027,
            LeaveEntitlementType::Carryover,
            16.0,
            new \DateTimeImmutable('2027-03-31'),
        );
        $regular = $this->createEntitlement(2027, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/entitlements?year=2027');

        self::assertResponseIsSuccessful();
        // Carryover row carries the "aus 2026" hint — clarifies that the
        // year field is the use-year, not the source year (#25 pragmatic UX).
        self::assertSelectorExists('[data-testid="entitlement-source-year-'.$carryover->getId().'"]');
        self::assertSelectorTextContains(
            '[data-testid="entitlement-source-year-'.$carryover->getId().'"]',
            'aus 2026',
        );
        // Regular row does not.
        self::assertSelectorNotExists('[data-testid="entitlement-source-year-'.$regular->getId().'"]');
    }

    #[Test]
    public function adminUpdatesGrantedHours(): void
    {
        $entitlement = $this->createEntitlement(2027, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();
        $id = $entitlement->getId();

        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/entitlements/'.$id.'/edit');
        $form = $crawler->filter('form[data-testid="admin-entitlement-edit-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[hoursGranted]' => '200.5',
        ]);

        self::assertResponseRedirects('/admin/entitlements?year=2027');

        $this->em->clear();
        /** @var LeaveEntitlement $reloaded */
        $reloaded = $this->em->find(LeaveEntitlement::class, $id);
        self::assertSame(200.5, $reloaded->getHoursGranted());
    }

    #[Test]
    public function editFormRejectsGrantBelowConsumed(): void
    {
        $entitlement = $this->createEntitlement(2027, LeaveEntitlementType::Regular, 240.0, null);
        $entitlement->consume(120.0);
        $this->em->flush();
        $id = $entitlement->getId();

        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/entitlements/'.$id.'/edit');
        $form = $crawler->filter('form[data-testid="admin-entitlement-edit-form"]')->form();
        $formName = $form->getName();

        $this->client->submit($form, [
            $formName.'[hoursGranted]' => '100',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->em->clear();
        /** @var LeaveEntitlement $reloaded */
        $reloaded = $this->em->find(LeaveEntitlement::class, $id);
        self::assertSame(240.0, $reloaded->getHoursGranted());
    }

    #[Test]
    public function editForeignCompanyEntitlementReturns404(): void
    {
        $foreign = new Company('Other GmbH', 36);
        $this->em->persist($foreign);
        $foreignLocation = new Location($foreign, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->em->persist($foreignLocation);
        $foreignEmployee = new Employee(
            $foreign,
            'Foreign Frank',
            'EMP-F001',
            $foreignLocation,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2023-01-01'),
        );
        $this->em->persist($foreignEmployee);
        $foreignEntitlement = new LeaveEntitlement($foreignEmployee, 2027, LeaveEntitlementType::Regular, 240.0);
        $this->em->persist($foreignEntitlement);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/entitlements/'.$foreignEntitlement->getId().'/edit');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function adminDeletesUnusedEntitlement(): void
    {
        $entitlement = $this->createEntitlement(2027, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();
        $id = $entitlement->getId();

        $this->loginAs('admin@leaveflow.test');

        // The delete button form lives in the index — fetch a fresh page so
        // the CSRF token in the page matches the session.
        $crawler = $this->client->request('GET', '/admin/entitlements?year=2027');
        $form = $crawler->filter('form[data-testid="entitlement-delete-form-'.$id.'"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/entitlements?year=2027');

        $this->em->clear();
        self::assertNull($this->em->find(LeaveEntitlement::class, $id));
    }

    #[Test]
    public function deleteIsBlockedWhenHoursAreConsumed(): void
    {
        $entitlement = $this->createEntitlement(2027, LeaveEntitlementType::Regular, 240.0, null);
        $entitlement->consume(40.0);
        $this->em->flush();
        $id = $entitlement->getId();

        $this->loginAs('admin@leaveflow.test');
        $crawler = $this->client->request('GET', '/admin/entitlements?year=2027');
        $form = $crawler->filter('form[data-testid="entitlement-delete-form-'.$id.'"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/entitlements?year=2027');

        $this->em->clear();
        // Entitlement still exists; flash error has been queued.
        self::assertNotNull($this->em->find(LeaveEntitlement::class, $id));
    }

    #[Test]
    public function deleteRejectsInvalidCsrf(): void
    {
        $entitlement = $this->createEntitlement(2027, LeaveEntitlementType::Regular, 240.0, null);
        $this->em->flush();
        $id = $entitlement->getId();

        $this->loginAs('admin@leaveflow.test');
        $this->client->request('POST', '/admin/entitlements/'.$id.'/delete', [
            '_token' => 'wrong',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->em->clear();
        self::assertNotNull($this->em->find(LeaveEntitlement::class, $id));
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
