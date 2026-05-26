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

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\Company;
use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Notification — a single in-app notification row.
 *
 * Constructor takes createdAt explicitly (no internal Clock dependency in the
 * domain layer). Application-layer NotificationDispatcher resolves the time
 * via injected ClockInterface and passes it in.
 */
#[CoversClass(Notification::class)]
final class NotificationTest extends TestCase
{
    private User $recipient;

    protected function setUp(): void
    {
        $acme = new Company('Acme GmbH');
        $this->recipient = new User($acme, 'jane@acme.test', UserRole::Employee);
    }

    #[Test]
    public function storesCoreFields(): void
    {
        $createdAt = new \DateTimeImmutable('2026-05-02 09:00:00');

        $notification = new Notification(
            recipient: $this->recipient,
            type: NotificationType::ApprovalRequested,
            payload: ['leaveRequestId' => 42, 'employeeName' => 'Jane Doe'],
            createdAt: $createdAt,
        );

        self::assertSame($this->recipient, $notification->getRecipient());
        self::assertSame(NotificationType::ApprovalRequested, $notification->getType());
        self::assertSame(['leaveRequestId' => 42, 'employeeName' => 'Jane Doe'], $notification->getPayload());
        self::assertSame($createdAt, $notification->getCreatedAt());
        self::assertNull($notification->getReadAt());
        self::assertFalse($notification->isRead());
    }

    #[Test]
    public function allowsEmptyPayload(): void
    {
        // Some notification types carry no extra context (e.g. a system-wide
        // ping). Empty payload must work without ceremony.
        $notification = new Notification(
            recipient: $this->recipient,
            type: NotificationType::EntitlementExpiringSoon,
            payload: [],
            createdAt: new \DateTimeImmutable('2026-05-02 09:00:00'),
        );

        self::assertSame([], $notification->getPayload());
    }

    #[Test]
    public function storesOptionalRelatedEntityReference(): void
    {
        // The dispatcher passes `relatedEntity` so the inbox can deeplink to
        // the source object (e.g. the LeaveRequest a notification is about).
        $notification = new Notification(
            recipient: $this->recipient,
            type: NotificationType::ApprovalDecided,
            payload: [],
            createdAt: new \DateTimeImmutable('2026-05-02 09:00:00'),
            relatedEntityType: 'App\\Domain\\Entity\\LeaveRequest',
            relatedEntityId: 17,
        );

        self::assertSame('App\\Domain\\Entity\\LeaveRequest', $notification->getRelatedEntityType());
        self::assertSame(17, $notification->getRelatedEntityId());
    }

    #[Test]
    public function relatedEntityFieldsAreOptional(): void
    {
        $notification = new Notification(
            recipient: $this->recipient,
            type: NotificationType::EntitlementExpiringSoon,
            payload: [],
            createdAt: new \DateTimeImmutable('2026-05-02 09:00:00'),
        );

        self::assertNull($notification->getRelatedEntityType());
        self::assertNull($notification->getRelatedEntityId());
    }

    #[Test]
    public function markAsReadSetsTimestamp(): void
    {
        $notification = new Notification(
            recipient: $this->recipient,
            type: NotificationType::ApprovalRequested,
            payload: [],
            createdAt: new \DateTimeImmutable('2026-05-02 09:00:00'),
        );

        $readAt = new \DateTimeImmutable('2026-05-02 10:30:00');
        $notification->markAsRead($readAt);

        self::assertTrue($notification->isRead());
        self::assertSame($readAt, $notification->getReadAt());
    }

    #[Test]
    public function markAsReadIsIdempotent(): void
    {
        // Calling markAsRead twice must keep the original readAt — the inbox
        // surfaces "first-read" semantics, not last-touched. Prevents
        // accidental clock churn from reset operations.
        $notification = new Notification(
            recipient: $this->recipient,
            type: NotificationType::ApprovalRequested,
            payload: [],
            createdAt: new \DateTimeImmutable('2026-05-02 09:00:00'),
        );

        $firstRead = new \DateTimeImmutable('2026-05-02 10:30:00');
        $secondRead = new \DateTimeImmutable('2026-05-02 11:45:00');

        $notification->markAsRead($firstRead);
        $notification->markAsRead($secondRead);

        self::assertSame($firstRead, $notification->getReadAt());
    }

    #[Test]
    public function rejectsRelatedEntityTypeWithoutId(): void
    {
        // (type, id) form a logical pair: either both set or both null.
        // Half-set state is a bug at the call site — surface it loudly.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('relatedEntityType and relatedEntityId must be set together');

        new Notification(
            recipient: $this->recipient,
            type: NotificationType::ApprovalDecided,
            payload: [],
            createdAt: new \DateTimeImmutable('2026-05-02 09:00:00'),
            relatedEntityType: 'App\\Domain\\Entity\\LeaveRequest',
            relatedEntityId: null,
        );
    }

    #[Test]
    public function rejectsRelatedEntityIdWithoutType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('relatedEntityType and relatedEntityId must be set together');

        new Notification(
            recipient: $this->recipient,
            type: NotificationType::ApprovalDecided,
            payload: [],
            createdAt: new \DateTimeImmutable('2026-05-02 09:00:00'),
            relatedEntityType: null,
            relatedEntityId: 17,
        );
    }
}
