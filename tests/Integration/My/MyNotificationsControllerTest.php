<?php

declare(strict_types=1);

namespace App\Tests\Integration\My;

use App\DataFixtures\AppFixtures;
use App\Domain\Entity\Company;
use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MyNotificationsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Company $company;
    private User $jane;
    private User $john;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seed();
    }

    #[Test]
    public function bellShowsBadgeWhenUnreadExists(): void
    {
        $this->createUnreadFor($this->jane, NotificationType::ApprovalRequested);
        $this->em->flush();

        $this->loginAs('jane@acme.test');
        $this->client->request('GET', '/my/notifications/bell');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="notification-bell"]');
        self::assertSelectorExists('[data-testid="notification-bell-badge"]');
        self::assertSelectorTextContains('[data-testid="notification-bell-badge"]', '1');
    }

    #[Test]
    public function bellHidesBadgeWhenAllRead(): void
    {
        $this->loginAs('jane@acme.test');
        $this->client->request('GET', '/my/notifications/bell');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="notification-bell"]');
        self::assertSelectorNotExists('[data-testid="notification-bell-badge"]');
    }

    #[Test]
    public function inboxShowsEmptyState(): void
    {
        $this->loginAs('jane@acme.test');
        $this->client->request('GET', '/my/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Benachrichtigungen');
        self::assertSelectorExists('[data-testid="notifications-empty"]');
    }

    #[Test]
    public function inboxListsRecentNotifications(): void
    {
        $approvalRequested = $this->createUnreadFor($this->jane, NotificationType::ApprovalRequested, [
            'employeeName' => 'Max Schmidt',
            'absenceTypeName' => 'Urlaub',
            'startDate' => '06.07.2026',
            'endDate' => '10.07.2026',
        ]);
        $this->em->flush();

        $this->loginAs('jane@acme.test');
        $this->client->request('GET', '/my/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="notifications-list"]');
        self::assertSelectorTextContains('[data-testid="notification-item-'.$approvalRequested->getId().'"]', 'Max Schmidt');
        self::assertSelectorExists('[data-testid="notification-unread-badge"]');
    }

    #[Test]
    public function markReadFlipsStateAndRedirectsToInboxWhenNoDeeplink(): void
    {
        $notification = $this->createUnreadFor($this->jane, NotificationType::ApprovalRequested);
        $this->em->flush();
        $id = $notification->getId();

        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications');
        $button = $crawler->filter('[data-testid="notification-item-'.$id.'"]')->first();
        $this->client->submit($button->form());

        self::assertResponseRedirects('/my/notifications');

        $this->em->clear();
        $reloaded = $this->em->find(Notification::class, $id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRead());
    }

    #[Test]
    public function markReadOnForeignNotificationReturns404(): void
    {
        // 404 fires before the CSRF check (mirrors LeaveRequest controller
        // pattern), so any token value works here.
        $foreign = $this->createUnreadFor($this->john, NotificationType::ApprovalRequested);
        $this->em->flush();
        $id = $foreign->getId();

        $this->loginAs('jane@acme.test');
        $this->client->request('POST', '/my/notifications/'.$id.'/read', [
            '_token' => 'ignored',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function markAllAsReadFlipsAllUnread(): void
    {
        $this->createUnreadFor($this->jane, NotificationType::ApprovalRequested);
        $this->createUnreadFor($this->jane, NotificationType::ApprovalDecided, ['decision' => 'approved']);
        $this->em->flush();

        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications');
        $button = $crawler->filter('button[data-testid="notifications-mark-all-read"]')->first();
        $this->client->submit($button->form());

        self::assertResponseRedirects('/my/notifications');

        $this->em->clear();
        $this->client->request('GET', '/my/notifications/bell');
        self::assertSelectorNotExists('[data-testid="notification-bell-badge"]');
    }

    #[Test]
    public function inboxRequiresAuthentication(): void
    {
        $this->client->request('GET', '/my/notifications');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function deleteRemovesOwnedNotification(): void
    {
        $notification = $this->createUnreadFor($this->jane, NotificationType::ApprovalRequested);
        $this->em->flush();
        $id = $notification->getId();

        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications');
        $form = $crawler->filter('form[data-testid="notification-delete-form-'.$id.'"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/my/notifications');

        $this->em->clear();
        self::assertNull($this->em->find(Notification::class, $id));
    }

    #[Test]
    public function deleteOnForeignNotificationReturns404(): void
    {
        // Ownership check fires before CSRF — mirrors the read action.
        $foreign = $this->createUnreadFor($this->john, NotificationType::ApprovalRequested);
        $this->em->flush();
        $id = $foreign->getId();

        $this->loginAs('jane@acme.test');
        $this->client->request('POST', '/my/notifications/'.$id.'/delete', [
            '_token' => 'ignored',
        ]);

        self::assertResponseStatusCodeSame(404);

        // Foreign notification untouched.
        $this->em->clear();
        self::assertNotNull($this->em->find(Notification::class, $id));
    }

    #[Test]
    public function deleteRejectsInvalidCsrf(): void
    {
        $notification = $this->createUnreadFor($this->jane, NotificationType::ApprovalRequested);
        $this->em->flush();
        $id = $notification->getId();

        $this->loginAs('jane@acme.test');
        $this->client->request('POST', '/my/notifications/'.$id.'/delete', [
            '_token' => 'wrong',
        ]);

        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNotNull($this->em->find(Notification::class, $id));
    }

    #[Test]
    public function deleteReadRemovesOnlyReadNotificationsOfCurrentUser(): void
    {
        $readJane = $this->createReadFor($this->jane, NotificationType::ApprovalRequested);
        $unreadJane = $this->createUnreadFor($this->jane, NotificationType::ApprovalDecided, ['decision' => 'approved']);
        $readJohn = $this->createReadFor($this->john, NotificationType::ApprovalRequested);
        $this->em->flush();

        $readJaneId = $readJane->getId();
        $unreadJaneId = $unreadJane->getId();
        $readJohnId = $readJohn->getId();

        $this->loginAs('jane@acme.test');
        $crawler = $this->client->request('GET', '/my/notifications');
        $form = $crawler->filter('form[data-testid="notifications-delete-read-form"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/my/notifications');

        $this->em->clear();
        // Jane's read one is gone, her unread stays, John's read stays.
        self::assertNull($this->em->find(Notification::class, $readJaneId));
        self::assertNotNull($this->em->find(Notification::class, $unreadJaneId));
        self::assertNotNull($this->em->find(Notification::class, $readJohnId));
    }

    #[Test]
    public function deleteReadRejectsInvalidCsrf(): void
    {
        $read = $this->createReadFor($this->jane, NotificationType::ApprovalRequested);
        $this->em->flush();
        $id = $read->getId();

        $this->loginAs('jane@acme.test');
        $this->client->request('POST', '/my/notifications/delete-read', [
            '_token' => 'wrong',
        ]);

        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNotNull($this->em->find(Notification::class, $id));
    }

    #[Test]
    public function inboxHidesDeleteReadButtonWhenNoReadNotificationsExist(): void
    {
        $this->createUnreadFor($this->jane, NotificationType::ApprovalRequested);
        $this->em->flush();

        $this->loginAs('jane@acme.test');
        $this->client->request('GET', '/my/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-testid="notifications-delete-read-form"]');
    }

    #[Test]
    public function inboxShowsDeleteReadButtonWhenAtLeastOneReadNotificationExists(): void
    {
        $this->createReadFor($this->jane, NotificationType::ApprovalRequested);
        $this->em->flush();

        $this->loginAs('jane@acme.test');
        $this->client->request('GET', '/my/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-testid="notifications-delete-read-form"]');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createReadFor(User $recipient, NotificationType $type, array $overrides = []): Notification
    {
        $notification = $this->createUnreadFor($recipient, $type, $overrides);
        $notification->markAsRead(new \DateTimeImmutable('2026-05-02 09:30:00'));

        return $notification;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createUnreadFor(User $recipient, NotificationType $type, array $overrides = []): Notification
    {
        $payload = $this->basePayloadFor($type);
        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }

        $notification = new Notification(
            recipient: $recipient,
            type: $type,
            payload: $payload,
            createdAt: new \DateTimeImmutable('2026-05-02 09:00:00'),
        );
        $this->em->persist($notification);

        return $notification;
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayloadFor(NotificationType $type): array
    {
        return match ($type) {
            NotificationType::ApprovalRequested,
            NotificationType::CancelRequested,
            NotificationType::RequestWithdrawn => [
                'employeeName' => 'Sample Employee',
                'absenceTypeName' => 'Urlaub',
                'startDate' => '01.06.2026',
                'endDate' => '05.06.2026',
            ],
            NotificationType::ApprovalDecided, NotificationType::CancelDecided => [
                'decision' => 'approved',
                'approverName' => 'Sample Approver',
                'absenceTypeName' => 'Urlaub',
                'startDate' => '01.06.2026',
                'endDate' => '05.06.2026',
            ],
            NotificationType::EscalationTriggered, NotificationType::EntitlementExpiringSoon => [],
            NotificationType::AdminTypeChange => [
                'oldTypeName' => 'Urlaub',
                'newTypeName' => 'Sonderurlaub',
                'startDate' => '01.06.2026',
                'endDate' => '05.06.2026',
                'adminName' => 'Sample Admin',
                'reason' => 'Sample reason',
            ],
            NotificationType::IllnessSixWeekAlert => [
                'employeeName' => 'Sample Employee',
                'employeeNumber' => 'EMP-1',
                'periodStartedOn' => '01.04.2026',
                'periodEndsOn' => '12.05.2026',
                'daysCount' => 42,
                'thresholdDays' => 42,
            ],
        };
    }

    private function seed(): void
    {
        $this->company = new Company('Acme GmbH', 36);
        $this->em->persist($this->company);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->jane = new User($this->company, 'jane@acme.test', UserRole::Employee);
        $this->jane->setHashedPassword($hasher->hashPassword($this->jane, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($this->jane);

        $this->john = new User($this->company, 'john@acme.test', UserRole::Employee);
        $this->john->setHashedPassword($hasher->hashPassword($this->john, AppFixtures::DEFAULT_PASSWORD));
        $this->em->persist($this->john);

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
