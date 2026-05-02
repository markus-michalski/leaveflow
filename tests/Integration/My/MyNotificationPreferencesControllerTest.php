<?php

declare(strict_types=1);

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

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function showsAllNotificationTypesAsCheckedByDefault(): void
    {
        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Benachrichtigungs-Einstellungen');

        // All 6 types must render as checked (lazy default = enabled).
        // Validate via the form's resolved submission values: a checked
        // checkbox shows up in getPhpValues() with its value '1'.
        $form = $crawler->filter('form')->first()->form();
        $values = $form->getPhpValues();
        foreach (NotificationType::cases() as $type) {
            self::assertSame(
                '1',
                $values['email'][$type->value] ?? null,
                'Type '.$type->value.' must be checked by default',
            );
        }
    }

    #[Test]
    public function reflectsExistingDisabledPreferenceAsUnchecked(): void
    {
        $pref = new NotificationPreference($this->jane, NotificationType::EscalationTriggered);
        $pref->disableEmail();
        $this->em->persist($pref);
        $this->em->flush();

        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        $form = $crawler->filter('form')->first()->form();
        $values = $form->getPhpValues();
        // EscalationTriggered must be absent from the submission set —
        // unchecked boxes are not posted.
        self::assertArrayNotHasKey('escalation_triggered', $values['email'] ?? []);
        // Other types remain checked.
        self::assertSame('1', $values['email']['approval_requested'] ?? null);
    }

    #[Test]
    public function unsubmittingTypeCreatesDisabledPreferenceRow(): void
    {
        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        // Manually build the POST: submit only 5 of the 6 types so the form
        // emulates "user unchecked Approval Decided".
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/my/notifications/preferences', [
            '_token' => $token,
            'email' => [
                'approval_requested' => '1',
                // approval_decided intentionally omitted
                'cancel_requested' => '1',
                'cancel_decided' => '1',
                'escalation_triggered' => '1',
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
        // The other types must remain at default (no row created, returns true).
        self::assertTrue($repo->isEmailEnabledFor($reloadedJane, NotificationType::ApprovalRequested));
    }

    #[Test]
    public function flippingDisabledBackToEnabledUpdatesExistingRow(): void
    {
        $pref = new NotificationPreference($this->jane, NotificationType::CancelRequested);
        $pref->disableEmail();
        $this->em->persist($pref);
        $this->em->flush();

        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications/preferences');

        // Submit with all 6 types checked (re-enable cancel_requested).
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/my/notifications/preferences', [
            '_token' => $token,
            'email' => [
                'approval_requested' => '1',
                'approval_decided' => '1',
                'cancel_requested' => '1',
                'cancel_decided' => '1',
                'escalation_triggered' => '1',
                'entitlement_expiring_soon' => '1',
            ],
        ]);
        self::assertResponseRedirects('/my/notifications/preferences');

        $this->em->clear();
        $repo = self::getContainer()->get(\App\Domain\Repository\NotificationPreferenceRepository::class);
        $reloadedJane = self::getContainer()->get(\App\Domain\Repository\UserRepository::class)
            ->findOneByEmail('jane@acme.test');
        self::assertInstanceOf(User::class, $reloadedJane);

        self::assertTrue($repo->isEmailEnabledFor($reloadedJane, NotificationType::CancelRequested));
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
