<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification;

use App\Application\Notification\NotificationDispatcher;
use App\Domain\Entity\Company;
use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit tests for NotificationDispatcher — single entry point that persists
 * an in-app Notification AND optionally dispatches an email via Symfony
 * Mailer (which routes through Messenger to async).
 *
 * The caller is responsible for flushing the EntityManager — same UnitOfWork
 * convention as ApprovalAuditSubscriber. Email channel is opt-out per user
 * via NotificationPreference (lazy default = enabled).
 */
#[CoversClass(NotificationDispatcher::class)]
final class NotificationDispatcherTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MailerInterface&MockObject $mailer;
    private NotificationPreferenceRepository&MockObject $preferences;
    private TranslatorInterface&MockObject $translator;
    private MockClock $clock;
    private User $recipient;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->preferences = $this->createMock(NotificationPreferenceRepository::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->clock = new MockClock('2026-05-02 09:00:00', 'UTC');

        $acme = new Company('Acme GmbH');
        $this->recipient = new User($acme, 'jane@acme.test', UserRole::Employee);
    }

    private function createDispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher(
            entityManager: $this->em,
            mailer: $this->mailer,
            preferences: $this->preferences,
            translator: $this->translator,
            clock: $this->clock,
        );
    }

    #[Test]
    public function persistsNotificationWithCoreFields(): void
    {
        $this->preferences->method('isEmailEnabledFor')->willReturn(false);

        $captured = null;
        $this->em->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (Notification $n) use (&$captured): bool {
                $captured = $n;

                return true;
            }));

        $dispatcher = $this->createDispatcher();
        $result = $dispatcher->dispatch(
            type: NotificationType::ApprovalRequested,
            recipient: $this->recipient,
            payload: ['leaveRequestId' => 42],
        );

        self::assertNotNull($captured);
        self::assertSame($result, $captured);
        self::assertSame($this->recipient, $captured->getRecipient());
        self::assertSame(NotificationType::ApprovalRequested, $captured->getType());
        self::assertSame(['leaveRequestId' => 42], $captured->getPayload());
    }

    #[Test]
    public function setsCreatedAtFromClock(): void
    {
        $this->preferences->method('isEmailEnabledFor')->willReturn(false);

        $captured = null;
        $this->em->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (Notification $n) use (&$captured): bool {
                $captured = $n;

                return true;
            }));

        $this->createDispatcher()->dispatch(
            type: NotificationType::ApprovalRequested,
            recipient: $this->recipient,
            payload: [],
        );

        self::assertNotNull($captured);
        self::assertSame(
            '2026-05-02 09:00:00',
            $captured->getCreatedAt()->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function passesRelatedEntityRefThrough(): void
    {
        $this->preferences->method('isEmailEnabledFor')->willReturn(false);

        $captured = null;
        $this->em->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (Notification $n) use (&$captured): bool {
                $captured = $n;

                return true;
            }));

        $this->createDispatcher()->dispatch(
            type: NotificationType::ApprovalDecided,
            recipient: $this->recipient,
            payload: [],
            relatedEntityType: 'App\\Domain\\Entity\\LeaveRequest',
            relatedEntityId: 17,
        );

        self::assertNotNull($captured);
        self::assertSame('App\\Domain\\Entity\\LeaveRequest', $captured->getRelatedEntityType());
        self::assertSame(17, $captured->getRelatedEntityId());
    }

    #[Test]
    public function sendsEmailWhenPreferenceAllows(): void
    {
        $this->preferences->expects(self::once())
            ->method('isEmailEnabledFor')
            ->with($this->recipient, NotificationType::ApprovalRequested)
            ->willReturn(true);

        $this->translator->method('trans')
            ->with(
                'email.approval_requested.subject',
                self::anything(),
                'notifications',
            )
            ->willReturn('Neuer Urlaubsantrag wartet auf Genehmigung');

        $this->em->expects(self::once())->method('persist');

        $captured = null;
        $this->mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (TemplatedEmail $email) use (&$captured): bool {
                $captured = $email;

                return true;
            }));

        $this->createDispatcher()->dispatch(
            type: NotificationType::ApprovalRequested,
            recipient: $this->recipient,
            payload: ['employeeName' => 'Jane Doe'],
        );

        self::assertNotNull($captured);
        self::assertSame(
            'emails/notifications/approval_requested.html.twig',
            $captured->getHtmlTemplate(),
        );
        self::assertSame(
            'emails/notifications/approval_requested.txt.twig',
            $captured->getTextTemplate(),
        );
        self::assertSame('Neuer Urlaubsantrag wartet auf Genehmigung', $captured->getSubject());

        $to = $captured->getTo();
        self::assertCount(1, $to);
        self::assertSame('jane@acme.test', $to[0]->getAddress());

        $context = $captured->getContext();
        self::assertSame(['employeeName' => 'Jane Doe'], $context['payload']);
        self::assertSame($this->recipient, $context['recipient']);
    }

    #[Test]
    public function skipsEmailWhenPreferenceDisabled(): void
    {
        $this->preferences->expects(self::once())
            ->method('isEmailEnabledFor')
            ->with($this->recipient, NotificationType::ApprovalDecided)
            ->willReturn(false);

        // In-app channel must still persist — only email is opted out.
        $this->em->expects(self::once())->method('persist');

        $this->mailer->expects(self::never())->method('send');
        $this->translator->expects(self::never())->method('trans');

        $this->createDispatcher()->dispatch(
            type: NotificationType::ApprovalDecided,
            recipient: $this->recipient,
            payload: [],
        );
    }

    #[Test]
    public function passesPayloadAsTranslationParameters(): void
    {
        // The subject line often interpolates payload data ("Vacation request
        // by %employeeName% needs approval"). Translator gets the payload as
        // its parameters array — keys map 1:1 to %placeholder%.
        $this->preferences->method('isEmailEnabledFor')->willReturn(true);

        $this->translator->expects(self::once())
            ->method('trans')
            ->with(
                'email.approval_requested.subject',
                ['employeeName' => 'Jane Doe', 'startDate' => '06.07.2026'],
                'notifications',
            )
            ->willReturn('Antrag von Jane Doe ab 06.07.2026');

        $this->em->expects(self::once())->method('persist');
        $this->mailer->expects(self::once())->method('send');

        $this->createDispatcher()->dispatch(
            type: NotificationType::ApprovalRequested,
            recipient: $this->recipient,
            payload: ['employeeName' => 'Jane Doe', 'startDate' => '06.07.2026'],
        );
    }
}
