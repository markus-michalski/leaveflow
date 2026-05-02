<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;
use App\Domain\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Single entry point for emitting notifications.
 *
 * Persists a Notification row (in-app inbox channel — always on) and
 * optionally dispatches an email (opt-out via NotificationPreference, lazy
 * default = enabled). The caller is responsible for flushing the
 * EntityManager — this matches the UnitOfWork convention used by
 * ApprovalAuditSubscriber, so multiple notifications written during one
 * request share one transaction.
 *
 * Email subject + body are convention-based on the NotificationType:
 * - subject:  translation key 'email.{type.value}.subject' in 'notifications' domain
 * - body:     templates 'emails/notifications/{type.value}.html.twig' (+ .txt.twig)
 *
 * Each new NotificationType ships with both a translation entry and a
 * template pair. Missing template = Twig hard error at send time, which is
 * intentional — surfaces wiring gaps loudly rather than silently dropping.
 */
final readonly class NotificationDispatcher implements NotificationDispatcherInterface
{
    private const string EMAIL_FROM_ADDRESS = 'no-reply@leaveflow.test';
    private const string EMAIL_FROM_NAME = 'LeaveFlow';
    private const string TRANSLATION_DOMAIN = 'notifications';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private NotificationPreferenceRepository $preferences,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        NotificationType $type,
        User $recipient,
        array $payload = [],
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null,
    ): Notification {
        $notification = new Notification(
            recipient: $recipient,
            type: $type,
            payload: $payload,
            createdAt: $this->clock->now(),
            relatedEntityType: $relatedEntityType,
            relatedEntityId: $relatedEntityId,
        );

        $this->entityManager->persist($notification);

        if ($this->preferences->isEmailEnabledFor($recipient, $type)) {
            $this->sendEmail($type, $recipient, $payload);
        }

        return $notification;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendEmail(NotificationType $type, User $recipient, array $payload): void
    {
        $subject = $this->translator->trans(
            \sprintf('email.%s.subject', $type->value),
            $payload,
            self::TRANSLATION_DOMAIN,
        );

        $email = (new TemplatedEmail())
            ->from(new Address(self::EMAIL_FROM_ADDRESS, self::EMAIL_FROM_NAME))
            ->to($recipient->getEmail())
            ->subject($subject)
            ->htmlTemplate(\sprintf('emails/notifications/%s.html.twig', $type->value))
            ->textTemplate(\sprintf('emails/notifications/%s.txt.twig', $type->value))
            ->context([
                'recipient' => $recipient,
                'payload' => $payload,
            ]);

        $this->mailer->send($email);
    }
}
