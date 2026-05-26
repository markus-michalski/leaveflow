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

namespace App\Tests\Integration\My;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\NotificationPreference;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MyNotificationPreferencesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private User $jane;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function showsOnlyEmployeeRelevantTypesForEmployee(): void
    {
        // Employees can only ever receive ApprovalDecided, CancelDecided
        // and EntitlementExpiringSoon — no point cluttering their UI with
        // toggles for manager/admin-only types.
        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Benachrichtigungs-Einstellungen');

        $form = $crawler->filter('form[data-testid="my-notification-preferences-form"]')->form();
        $values = $form->getPhpValues();

        $expected = [
            NotificationType::ApprovalDecided->value,
            NotificationType::CancelDecided->value,
            NotificationType::EntitlementExpiringSoon->value,
        ];
        $forbidden = [
            NotificationType::ApprovalRequested->value,
            NotificationType::RequestWithdrawn->value,
            NotificationType::CancelRequested->value,
            NotificationType::EscalationTriggered->value,
        ];

        foreach ($expected as $type) {
            self::assertSame('1', $values['email'][$type] ?? null, 'Employee must see + default-check type '.$type);
        }
        foreach ($forbidden as $type) {
            self::assertArrayNotHasKey($type, $values['email'] ?? [], 'Employee must NOT see type '.$type);
        }
    }

    #[Test]
    public function showsAllNotificationTypesForAdmin(): void
    {
        // Admins inherit every role via role_hierarchy → see every type.
        $this->loginAs('admin@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        $form = $crawler->filter('form[data-testid="my-notification-preferences-form"]')->form();
        $values = $form->getPhpValues();

        foreach (NotificationType::cases() as $type) {
            self::assertSame('1', $values['email'][$type->value] ?? null, 'Admin must see type '.$type->value);
        }
    }

    #[Test]
    public function reflectsExistingDisabledPreferenceAsUnchecked(): void
    {
        $pref = new NotificationPreference($this->jane, NotificationType::ApprovalDecided);
        $pref->disableEmail();
        $this->em->persist($pref);
        $this->em->flush();

        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        $form = $crawler->filter('form[data-testid="my-notification-preferences-form"]')->form();
        $values = $form->getPhpValues();
        // ApprovalDecided must be absent from the submission set —
        // unchecked toggles are not posted.
        self::assertArrayNotHasKey('approval_decided', $values['email'] ?? []);
        // Other employee-visible types remain checked.
        self::assertSame('1', $values['email']['cancel_decided'] ?? null);
    }

    #[Test]
    public function unsubmittingTypeCreatesDisabledPreferenceRow(): void
    {
        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        // POST with cancel_decided + entitlement_expiring_soon only —
        // approval_decided intentionally omitted to emulate "user toggled
        // it off".
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/my/notifications/preferences', [
            '_token' => $token,
            'email' => [
                // approval_decided intentionally omitted
                'cancel_decided' => '1',
                'entitlement_expiring_soon' => '1',
            ],
        ]);
        self::assertResponseRedirects('/my/notifications/preferences');

        $this->em->clear();
        $repo = self::getContainer()->get(\App\Domain\Repository\NotificationPreferenceRepository::class);
        $reloadedJane = self::getContainer()->get(\App\Domain\Repository\UserRepository::class)
            ->findOneByEmail('jane@acme.test');
        self::assertInstanceOf(User::class, $reloadedJane);

        self::assertFalse($repo->isEmailEnabledFor($reloadedJane, NotificationType::ApprovalDecided));
        // Other employee-visible types remain at default (no row created, returns true).
        self::assertTrue($repo->isEmailEnabledFor($reloadedJane, NotificationType::CancelDecided));
    }

    #[Test]
    public function flippingDisabledBackToEnabledUpdatesExistingRow(): void
    {
        $pref = new NotificationPreference($this->jane, NotificationType::CancelDecided);
        $pref->disableEmail();
        $this->em->persist($pref);
        $this->em->flush();

        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/my/notifications/preferences', [
            '_token' => $token,
            'email' => [
                'approval_decided' => '1',
                'cancel_decided' => '1',
                'entitlement_expiring_soon' => '1',
            ],
        ]);
        self::assertResponseRedirects('/my/notifications/preferences');

        $this->em->clear();
        $repo = self::getContainer()->get(\App\Domain\Repository\NotificationPreferenceRepository::class);
        $reloadedJane = self::getContainer()->get(\App\Domain\Repository\UserRepository::class)
            ->findOneByEmail('jane@acme.test');
        self::assertInstanceOf(User::class, $reloadedJane);

        self::assertTrue($repo->isEmailEnabledFor($reloadedJane, NotificationType::CancelDecided));
    }

    #[Test]
    public function silentlyIgnoresPostedTypesOutsideUserRoleScope(): void
    {
        // An employee can't manage EscalationTriggered (admin-only) — even
        // if they craft a POST that includes it, the controller skips it.
        // No persisted row, no error.
        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/my/notifications/preferences', [
            '_token' => $token,
            'email' => [
                'approval_decided' => '1',
                'cancel_decided' => '1',
                'entitlement_expiring_soon' => '1',
                // forbidden — must be silently ignored
                'escalation_triggered' => '1',
            ],
        ]);
        self::assertResponseRedirects('/my/notifications/preferences');

        $this->em->clear();
        $repo = self::getContainer()->get(\App\Domain\Repository\NotificationPreferenceRepository::class);
        $reloadedJane = self::getContainer()->get(\App\Domain\Repository\UserRepository::class)
            ->findOneByEmail('jane@acme.test');
        self::assertInstanceOf(User::class, $reloadedJane);
        // No row created for the smuggled type — default still applies, but
        // crucially nothing was persisted under the employee's name.
        self::assertNull($repo->findOneByUserAndType($reloadedJane, NotificationType::EscalationTriggered));
    }

    #[Test]
    public function rejectsPostWithInvalidCsrf(): void
    {
        $this->loginAs('jane@acme.test');
        $this->client->request('POST', '/my/notifications/preferences', [
            '_token' => 'invalid',
            'email' => ['approval_decided' => '1'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function requiresAuthentication(): void
    {
        $this->client->request('GET', '/my/notifications/preferences');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function inboxLinksToPreferencesPage(): void
    {
        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications');

        $link = $crawler->filter('[data-testid="notifications-preferences-link"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('/my/notifications/preferences', (string) $link->attr('href'));
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->jane = new User($this->company, 'jane@acme.test', UserRole::Employee);
        $this->jane->setHashedPassword($hasher->hashPassword($this->jane, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($this->jane);

        $this->admin = new User($this->company, 'admin@acme.test', UserRole::Admin);
        $this->admin->setHashedPassword($hasher->hashPassword($this->admin, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($this->admin);

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
