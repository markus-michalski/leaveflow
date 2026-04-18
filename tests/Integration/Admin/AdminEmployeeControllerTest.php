<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
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

    private function seed(): void
    {
        $company = new Company('Acme GmbH', 36);
        $this->em->persist($company);

        $this->hq = new Location($company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->em->persist($this->hq);

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
