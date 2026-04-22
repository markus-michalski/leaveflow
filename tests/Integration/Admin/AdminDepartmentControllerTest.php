<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminDepartmentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Employee $lead;
    private Employee $deputy;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function adminSeesEmptyDepartmentList(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/departments');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Teams');
        self::assertSelectorExists('[data-testid="departments-empty"]');
    }

    #[Test]
    public function adminCanCreateDepartment(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/departments/new');
        $form = $crawler->filter('form[data-testid="admin-department-form"]')->form();
        $name = $form->getName();

        $this->client->submit($form, [
            $name.'[name]' => 'Engineering',
            $name.'[lead]' => (string) $this->lead->getId(),
            $name.'[deputy]' => (string) $this->deputy->getId(),
            $name.'[active]' => '1',
        ]);

        self::assertResponseRedirects('/admin/departments');

        $this->em->clear();
        $department = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Engineering']);
        self::assertNotNull($department);
        self::assertSame('Engineering', $department->getName());
        self::assertNotNull($department->getLead());
        self::assertNotNull($department->getDeputy());
        self::assertTrue($department->isActive());
    }

    #[Test]
    public function adminCreateRejectsLeadEqualToDeputy(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/departments/new');
        $form = $crawler->filter('form[data-testid="admin-department-form"]')->form();
        $name = $form->getName();

        $this->client->submit($form, [
            $name.'[name]' => 'Invalid',
            $name.'[lead]' => (string) $this->lead->getId(),
            $name.'[deputy]' => (string) $this->lead->getId(),
            $name.'[active]' => '1',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->em->getRepository(Department::class)->findOneBy(['name' => 'Invalid']));
    }

    #[Test]
    public function adminCanEditDepartment(): void
    {
        $department = new Department($this->company, 'Sales', lead: $this->lead);
        $this->em->persist($department);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/departments/'.$department->getId().'/edit');
        $form = $crawler->filter('form[data-testid="admin-department-form"]')->form();
        $name = $form->getName();

        $this->client->submit($form, [
            $name.'[name]' => 'Sales Europe',
            $name.'[lead]' => (string) $this->lead->getId(),
            $name.'[deputy]' => (string) $this->deputy->getId(),
            $name.'[active]' => '1',
        ]);

        self::assertResponseRedirects('/admin/departments');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Department::class)->find($department->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Sales Europe', $reloaded->getName());
        self::assertNotNull($reloaded->getDeputy());
    }

    #[Test]
    public function adminCanToggleDepartmentActive(): void
    {
        $department = new Department($this->company, 'Ops', lead: $this->lead);
        $this->em->persist($department);
        $this->em->flush();

        $this->loginAs('admin@leaveflow.test');

        // Load the index so the toggle form with its CSRF token is in the
        // rendered page — mirrors how a real user triggers it.
        $crawler = $this->client->request('GET', '/admin/departments');
        $form = $crawler->filter('form[action="/admin/departments/'.$department->getId().'/toggle"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/departments');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Department::class)->find($department->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());
    }

    #[Test]
    public function adminCanDeleteDepartment(): void
    {
        $department = new Department($this->company, 'Legacy');
        $this->em->persist($department);
        $this->em->flush();
        $id = $department->getId();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/departments');
        $form = $crawler->filter('form[action="/admin/departments/'.$id.'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/departments');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Department::class)->find($id));
    }

    #[Test]
    public function employeeIsForbidden(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/admin/departments');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        $location = new Location($this->company, 'HQ', 'DE', 'DE-BE', 'Berlin');
        $this->em->persist($location);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
            ['employee@leaveflow.test', UserRole::Employee],
        ] as [$email, $role]) {
            $user = new User($this->company, $email, $role);
            $user->setHashedPassword($hasher->hashPassword($user, AppFixtures::DEFAULT_PASSWORD));
            $this->em->persist($user);
        }

        $schedule = WorkSchedule::autoDistribute(40.0, [
            Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday,
        ]);

        $this->lead = new Employee(
            company: $this->company,
            fullName: 'Max Lead',
            employeeNumber: 'EMP-LEAD',
            location: $location,
            workSchedule: $schedule,
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->em->persist($this->lead);

        $this->deputy = new Employee(
            company: $this->company,
            fullName: 'Maria Deputy',
            employeeNumber: 'EMP-DEP',
            location: $location,
            workSchedule: $schedule,
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->em->persist($this->deputy);

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
