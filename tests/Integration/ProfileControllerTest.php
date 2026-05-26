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

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
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

final class ProfileControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function anonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/profile');

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function userWithLinkedEmployeeSeesHrDetails(): void
    {
        $this->loginAs('employee@leaveflow.test');

        $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="profile-email"]', 'employee@leaveflow.test');
        self::assertSelectorTextContains('[data-testid="profile-full-name"]', 'Erik Employee');
    }

    #[Test]
    public function userWithoutEmployeeSeesGracefulPlaceholder(): void
    {
        $this->loginAs('admin@leaveflow.test');

        $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="profile-no-employee"]');
    }

    private function seed(): void
    {
        $company = new Company('Acme GmbH', 36);
        $this->em->persist($company);

        $hq = new Location($company, 'HQ', 'DE', 'DE-BY', 'München');
        $this->em->persist($hq);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $admin = new User($company, 'admin@leaveflow.test', UserRole::Admin);
        $admin->setHashedPassword($hasher->hashPassword($admin, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($admin);

        $employeeUser = new User($company, 'employee@leaveflow.test', UserRole::Employee);
        $employeeUser->setHashedPassword($hasher->hashPassword($employeeUser, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($employeeUser);

        $this->em->persist(new Employee(
            $company,
            'Erik Employee',
            'EMP-0002',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2025-03-01'),
            $employeeUser,
        ));

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
        // Follow every redirect — admins now hop /login → / → /admin/statistics
        // so a single followRedirect would leave us on a 302 response.
        while ($this->client->getResponse()->isRedirection()) {
            $this->client->followRedirect();
        }

        if (Response::HTTP_OK !== $this->client->getResponse()->getStatusCode()) {
            throw new \RuntimeException('Login failed for '.$email);
        }
    }
}
