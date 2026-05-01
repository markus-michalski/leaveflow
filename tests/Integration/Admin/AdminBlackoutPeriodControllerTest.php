<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\BlackoutPeriod;
use App\Domain\Entity\Company;
use App\Domain\Entity\Department;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminBlackoutPeriodControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private Department $engineering;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function adminSeesEmptyBlackoutList(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/admin/blackout-periods');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sperrfristen');
        self::assertSelectorExists('[data-testid="blackout-periods-empty"]');
    }

    #[Test]
    public function adminCanCreateCompanyWideBlackout(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/blackout-periods/new');
        $form = $crawler->filter('form[data-testid="blackout-period-form"]')->form();
        $name = $form->getName();

        $this->client->submit($form, [
            $name.'[startDate]' => '23.12.2026',
            $name.'[endDate]' => '31.12.2026',
            $name.'[reason]' => 'Werksferien',
            $name.'[department]' => '',
        ]);

        self::assertResponseRedirects('/admin/blackout-periods');

        $this->em->clear();
        $repo = $this->em->getRepository(BlackoutPeriod::class);
        $created = $repo->findOneBy(['reason' => 'Werksferien']);
        self::assertNotNull($created);
        self::assertNull($created->getDepartment());
    }

    #[Test]
    public function adminCanCreateDepartmentScopedBlackout(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/blackout-periods/new');
        $form = $crawler->filter('form[data-testid="blackout-period-form"]')->form();
        $name = $form->getName();

        $this->client->submit($form, [
            $name.'[startDate]' => '01.07.2026',
            $name.'[endDate]' => '14.07.2026',
            $name.'[reason]' => 'Release-Freeze',
            $name.'[department]' => (string) $this->engineering->getId(),
        ]);

        self::assertResponseRedirects('/admin/blackout-periods');

        $this->em->clear();
        $created = $this->em->getRepository(BlackoutPeriod::class)
            ->findOneBy(['reason' => 'Release-Freeze']);
        self::assertNotNull($created);
        self::assertNotNull($created->getDepartment());
        self::assertSame('Engineering', $created->getDepartment()->getName());
    }

    #[Test]
    public function createRejectsEndBeforeStart(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/blackout-periods/new');
        $form = $crawler->filter('form[data-testid="blackout-period-form"]')->form();
        $name = $form->getName();

        $this->client->submit($form, [
            $name.'[startDate]' => '31.12.2026',
            $name.'[endDate]' => '23.12.2026',
            $name.'[reason]' => 'invalid',
            $name.'[department]' => '',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    public function adminCanEditBlackout(): void
    {
        $period = new BlackoutPeriod(
            company: $this->company,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'Initial reason',
        );
        $this->em->persist($period);
        $this->em->flush();
        $id = $period->getId();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/blackout-periods/'.$id.'/edit');
        $form = $crawler->filter('form[data-testid="blackout-period-form"]')->form();
        $name = $form->getName();

        $this->client->submit($form, [
            $name.'[startDate]' => '23.12.2026',
            $name.'[endDate]' => '02.01.2027',
            $name.'[reason]' => 'Updated reason',
            $name.'[department]' => '',
        ]);

        self::assertResponseRedirects('/admin/blackout-periods');

        $this->em->clear();
        $reloaded = $this->em->getRepository(BlackoutPeriod::class)->find($id);
        self::assertNotNull($reloaded);
        self::assertSame('Updated reason', $reloaded->getReason());
        self::assertSame('2027-01-02', $reloaded->getEndDate()->format('Y-m-d'));
    }

    #[Test]
    public function adminCanDeleteBlackout(): void
    {
        $period = new BlackoutPeriod(
            company: $this->company,
            startDate: new \DateTimeImmutable('2026-12-23'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            reason: 'gone',
        );
        $this->em->persist($period);
        $this->em->flush();
        $id = $period->getId();

        $this->loginAs('admin@leaveflow.test');

        $crawler = $this->client->request('GET', '/admin/blackout-periods');
        $form = $crawler->filter('form[action="/admin/blackout-periods/'.$id.'/delete"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/blackout-periods');
        $this->em->clear();
        self::assertNull($this->em->getRepository(BlackoutPeriod::class)->find($id));
    }

    #[Test]
    public function employeeIsForbidden(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/admin/blackout-periods');

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

        $lead = new Employee(
            company: $this->company,
            fullName: 'Max Lead',
            employeeNumber: 'EMP-LEAD',
            location: $location,
            workSchedule: WorkSchedule::standardFullTime(),
            joinedAt: new \DateTimeImmutable('2020-01-01'),
        );
        $this->em->persist($lead);

        $this->engineering = new Department($this->company, 'Engineering', lead: $lead);
        $this->em->persist($this->engineering);

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
