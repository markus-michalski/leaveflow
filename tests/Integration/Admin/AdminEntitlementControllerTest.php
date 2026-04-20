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

        $this->client->request('GET', '/admin/entitlements');

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
