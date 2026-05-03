<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\NotificationType;
use App\Domain\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user opt-out for the email channel of one NotificationType.
 *
 * Storage is lazy: no row = default behavior (email enabled). Rows appear
 * only when the user explicitly toggles off in the preferences UI. The
 * repository's isEmailEnabledFor() encodes this default — lookups don't
 * need to seed.
 *
 * In-app delivery is always on (the inbox is not opt-out-able), so this
 * entity carries the email flag only.
 *
 * Unique constraint on (user_id, type) ensures at most one row per pairing.
 */
#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_notif_pref_user_type', columns: ['user_id', 'type'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'email_enabled', options: ['default' => true])]
    private bool $emailEnabled = true;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: 'string', length: 40, enumType: NotificationType::class)]
        private NotificationType $type,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function isEmailEnabled(): bool
    {
        return $this->emailEnabled;
    }

    public function enableEmail(): void
    {
        $this->emailEnabled = true;
    }

    public function disableEmail(): void
    {
        $this->emailEnabled = false;
    }
}
