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

namespace App\Tests\Integration\Security;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);

        $this->seedDefaultAccounts();
    }

    #[Test]
    public function loginPageIsPubliclyAccessible(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-testid="login-form"]');
    }

    #[Test]
    public function rootRedirectsAnonymousUsersToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function validCredentialsRedirectToDashboard(): void
    {
        $this->submitLoginForm('employee@leaveflow.test', AppFixtures::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Dashboard');
    }

    #[Test]
    public function invalidCredentialsStayOnLoginWithError(): void
    {
        $this->submitLoginForm('employee@leaveflow.test', 'wrong-password');

        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSelectorExists('[role="alert"]');
    }

    #[Test]
    public function deactivatedUserCannotLogIn(): void
    {
        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => 'employee@leaveflow.test']);

        self::assertInstanceOf(User::class, $user);
        $user->deactivate();
        $this->entityManager->flush();

        $this->submitLoginForm('employee@leaveflow.test', AppFixtures::DEFAULT_PASSWORD);

        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
    }

    #[Test]
    public function adminCanAccessUserManagement(): void
    {
        $this->submitLoginForm('admin@leaveflow.test', AppFixtures::DEFAULT_PASSWORD);
        $this->client->followRedirect();

        $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Benutzer');
    }

    #[Test]
    public function employeeIsForbiddenFromUserManagement(): void
    {
        $this->submitLoginForm('employee@leaveflow.test', AppFixtures::DEFAULT_PASSWORD);
        $this->client->followRedirect();

        $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function managerIsForbiddenFromUserManagement(): void
    {
        $this->submitLoginForm('manager@leaveflow.test', AppFixtures::DEFAULT_PASSWORD);
        $this->client->followRedirect();

        $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function submitLoginForm(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form[data-testid="login-form"]')->form([
            '_username' => $email,
            '_password' => $password,
        ]);
        $this->client->submit($form);
    }

    private function seedDefaultAccounts(): void
    {
        $company = new Company('Acme GmbH', 36);
        $this->entityManager->persist($company);

        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
            ['manager@leaveflow.test', UserRole::Manager],
            ['employee@leaveflow.test', UserRole::Employee],
        ] as [$email, $role]) {
            $user = new User($company, $email, $role);
            $user->setHashedPassword(
                $this->hasher->hashPassword($user, AppFixtures::DEFAULT_PASSWORD)
            );
            $this->entityManager->persist($user);
        }

        $this->entityManager->flush();
    }
}
