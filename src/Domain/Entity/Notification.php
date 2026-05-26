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

namespace App\Domain\Entity;

use App\Domain\Enum\NotificationType;
use App\Domain\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One in-app notification row, addressed to a single user.
 *
 * Notifications are unified across types — one table, type discriminated via
 * the NotificationType enum and per-type payload stored as JSON. The Twig
 * inbox renders type-specific fragments by switching on the type.
 *
 * The relatedEntityType/relatedEntityId pair forms a polymorphic deeplink so
 * the inbox can route "see request" buttons without per-type FK columns.
 * They're stored as a paired pair: both set or both null. Half-set state is
 * rejected at construction.
 *
 * createdAt is taken as a constructor argument rather than initialized
 * internally — the application-layer NotificationDispatcher injects
 * ClockInterface and resolves the time. Domain stays Clock-free.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notif_recipient_unread', columns: ['recipient_id', 'read_at'])]
#[ORM\Index(name: 'idx_notif_recipient_created', columns: ['recipient_id', 'created_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'recipient_id', nullable: false, onDelete: 'CASCADE')]
        private User $recipient,
        #[ORM\Column(type: 'string', length: 40, enumType: NotificationType::class)]
        private NotificationType $type,
        /**
         * @var array<string, mixed>
         */
        #[ORM\Column(type: Types::JSON)]
        private array $payload,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'related_entity_type', length: 80, nullable: true)]
        private ?string $relatedEntityType = null,
        #[ORM\Column(name: 'related_entity_id', nullable: true)]
        private ?int $relatedEntityId = null,
    ) {
        if ((null === $relatedEntityType) !== (null === $relatedEntityId)) {
            throw new \InvalidArgumentException('Notification.relatedEntityType and relatedEntityId must be set together (both or neither).');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getRelatedEntityType(): ?string
    {
        return $this->relatedEntityType;
    }

    public function getRelatedEntityId(): ?int
    {
        return $this->relatedEntityId;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    /**
     * Marks this notification as read. Idempotent — repeated calls preserve
     * the original read timestamp. The inbox surfaces "first-read" semantics.
     */
    public function markAsRead(\DateTimeImmutable $now): void
    {
        if (null !== $this->readAt) {
            return;
        }
        $this->readAt = $now;
    }
}
