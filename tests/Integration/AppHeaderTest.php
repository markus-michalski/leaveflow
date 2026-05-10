<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase-9 issue #14: the global app header (logo, nav, admin menu, bell,
 * user menu) lives in base.html.twig and renders on every authenticated
 * page. Login, password-reset, and error templates suppress it via empty
 * {% block app_header %}{% endblock %} overrides.
 *
 * These tests pin both behaviors so future template churn doesn't
 * silently regress them.
 */
final class AppHeaderTest extends WebTestCase
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
    public function headerRendersOnDashboard(): void
    {
        $this->loginAs('admin@leaveflow.test');
        // Admin's "/" redirects to /admin/statistics — the action briefing is
        // their canonical landing page, the personal dashboard would be empty
        // for a user-only admin without an employee record.
        $this->client->request('GET', '/');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="app-header-home"]');
        self::assertSelectorExists('[data-testid="app-header-nav"]');
    }

    #[Test]
    public function headerRendersOnAdminPage(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="app-header-home"]');
    }

    #[Test]
    public function headerRendersOnTeamCalendar(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/team/calendar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="app-header-home"]');
    }

    #[Test]
    public function headerRendersOnNotificationsInbox(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/my/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="app-header-home"]');
    }

    #[Test]
    public function adminMenuVisibleOnlyForAdmin(): void
    {
        $this->loginAs('admin@leaveflow.test');
        $this->client->request('GET', '/');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-dropdown-toggle="admin-menu"]');
    }

    #[Test]
    public function adminMenuHiddenForEmployee(): void
    {
        $this->loginAs('employee@leaveflow.test');
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-dropdown-toggle="admin-menu"]');
    }

    #[Test]
    public function loginPageHasNoHeader(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="app-header-home"]');
    }

    #[Test]
    public function passwordResetRequestPageHasNoHeader(): void
    {
        $this->client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="app-header-home"]');
    }

    private function seed(): void
    {
        $company = new Company('Acme GmbH', 36);
        $this->em->persist($company);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        foreach ([
            ['admin@leaveflow.test', UserRole::Admin],
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
