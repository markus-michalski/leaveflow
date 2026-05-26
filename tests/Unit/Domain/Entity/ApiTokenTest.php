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

use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Company;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiToken::class)]
final class ApiTokenTest extends TestCase
{
    private Company $acme;
    private \DateTimeImmutable $createdAt;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
        $this->createdAt = new \DateTimeImmutable('2026-05-23 10:00:00');
    }

    private function makeToken(?string $name = 'HR System', ?\DateTimeImmutable $expiresAt = null): ApiToken
    {
        return new ApiToken(
            company: $this->acme,
            name: $name ?? 'HR System',
            tokenHash: hash('sha256', 'rawtoken'),
            createdAt: $this->createdAt,
            expiresAt: $expiresAt,
        );
    }

    #[Test]
    public function defaultsToActiveWithNoExpiry(): void
    {
        $token = $this->makeToken();

        self::assertTrue($token->isActive(new \DateTimeImmutable('2026-05-23 11:00:00')));
        self::assertNull($token->getRevokedAt());
        self::assertNull($token->getLastUsedAt());
        self::assertNull($token->getExpiresAt());
    }

    #[Test]
    public function storesCoreFields(): void
    {
        $token = $this->makeToken('HR System');

        self::assertSame('HR System', $token->getName());
        self::assertSame(hash('sha256', 'rawtoken'), $token->getTokenHash());
        self::assertSame($this->createdAt, $token->getCreatedAt());
        self::assertSame($this->acme, $token->getCompany());
    }

    #[Test]
    public function rejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        $this->makeToken('   ');
    }

    #[Test]
    public function isNotActiveWhenRevoked(): void
    {
        $token = $this->makeToken();
        $token->revoke(new \DateTimeImmutable('2026-05-23 12:00:00'));

        self::assertFalse($token->isActive(new \DateTimeImmutable('2026-05-23 12:01:00')));
        self::assertNotNull($token->getRevokedAt());
    }

    #[Test]
    public function revokeIsIdempotent(): void
    {
        $token = $this->makeToken();
        $first = new \DateTimeImmutable('2026-05-23 12:00:00');
        $second = new \DateTimeImmutable('2026-05-23 13:00:00');

        $token->revoke($first);
        $token->revoke($second);

        self::assertSame($first, $token->getRevokedAt());
    }

    #[Test]
    public function isNotActiveWhenExpired(): void
    {
        $past = new \DateTimeImmutable('2026-01-01 00:00:00');
        $token = $this->makeToken(expiresAt: $past);

        self::assertFalse($token->isActive(new \DateTimeImmutable('2026-05-23 10:00:00')));
    }

    #[Test]
    public function isActiveWhenNotYetExpired(): void
    {
        $future = new \DateTimeImmutable('2027-01-01 00:00:00');
        $token = $this->makeToken(expiresAt: $future);

        self::assertTrue($token->isActive(new \DateTimeImmutable('2026-05-23 10:00:00')));
    }

    #[Test]
    public function recordUsageSetsLastUsedAt(): void
    {
        $token = $this->makeToken();
        $now = new \DateTimeImmutable('2026-05-23 15:00:00');

        $token->recordUsage($now);

        self::assertSame($now, $token->getLastUsedAt());
    }

    #[Test]
    public function recordUsageUpdatesLastUsedAt(): void
    {
        $token = $this->makeToken();
        $first = new \DateTimeImmutable('2026-05-23 15:00:00');
        $second = new \DateTimeImmutable('2026-05-23 16:00:00');

        $token->recordUsage($first);
        $token->recordUsage($second);

        self::assertSame($second, $token->getLastUsedAt());
    }

    #[Test]
    public function trimsName(): void
    {
        $token = $this->makeToken('  HR System  ');

        self::assertSame('HR System', $token->getName());
    }

    #[Test]
    public function updateNameTrimsAndRejectsEmpty(): void
    {
        $token = $this->makeToken();
        $token->rename('  Updated Name  ');

        self::assertSame('Updated Name', $token->getName());
    }

    #[Test]
    public function updateNameRejectsEmpty(): void
    {
        $token = $this->makeToken();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        $token->rename('');
    }
}
