<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\Company;
use App\Domain\Entity\NotificationPreference;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;
use App\Domain\Enum\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationPreference — per-user opt-out for email channel
 * of one NotificationType.
 *
 * Storage strategy is "lazy": no row = default behavior (email enabled). Rows
 * appear only when the user explicitly opts out. The repo layer exposes
 * isEmailEnabledFor() which encodes that default.
 */
#[CoversClass(NotificationPreference::class)]
final class NotificationPreferenceTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $acme = new Company('Acme GmbH');
        $this->user = new User($acme, 'jane@acme.test', UserRole::Employee);
    }

    #[Test]
    public function defaultsToEmailEnabled(): void
    {
        $preference = new NotificationPreference(
            user: $this->user,
            type: NotificationType::ApprovalRequested,
        );

        self::assertSame($this->user, $preference->getUser());
        self::assertSame(NotificationType::ApprovalRequested, $preference->getType());
        self::assertTrue($preference->isEmailEnabled());
    }

    #[Test]
    public function disableEmailFlipsFlag(): void
    {
        $preference = new NotificationPreference(
            user: $this->user,
            type: NotificationType::ApprovalRequested,
        );

        $preference->disableEmail();

        self::assertFalse($preference->isEmailEnabled());
    }

    #[Test]
    public function enableEmailFlipsFlag(): void
    {
        $preference = new NotificationPreference(
            user: $this->user,
            type: NotificationType::ApprovalRequested,
        );
        $preference->disableEmail();

        $preference->enableEmail();

        self::assertTrue($preference->isEmailEnabled());
    }

    #[Test]
    public function disableEmailIsIdempotent(): void
    {
        $preference = new NotificationPreference(
            user: $this->user,
            type: NotificationType::ApprovalRequested,
        );

        $preference->disableEmail();
        $preference->disableEmail();

        self::assertFalse($preference->isEmailEnabled());
    }

    #[Test]
    public function enableEmailIsIdempotent(): void
    {
        $preference = new NotificationPreference(
            user: $this->user,
            type: NotificationType::ApprovalRequested,
        );

        $preference->enableEmail();
        $preference->enableEmail();

        self::assertTrue($preference->isEmailEnabled());
    }
}
