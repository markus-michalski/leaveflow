<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;

/**
 * Single entry point contract for emitting notifications.
 *
 * Persists an in-app Notification (always) and optionally dispatches an
 * email (opt-out per user via NotificationPreference).
 *
 * The caller is responsible for flushing the EntityManager — the dispatcher
 * only persists, matching the UnitOfWork convention used by audit/booking
 * subscribers in this codebase.
 *
 * Existence of this interface is purely for testability — listeners take
 * the interface so they can be unit-tested with a mocked dispatcher without
 * having to wire up the full dispatch pipeline.
 */
interface NotificationDispatcherInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        NotificationType $type,
        User $recipient,
        array $payload = [],
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null,
    ): Notification;
}
