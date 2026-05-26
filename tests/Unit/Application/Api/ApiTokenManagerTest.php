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

namespace App\Tests\Unit\Application\Api;

use App\Application\Api\ApiTokenManager;
use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Company;
use App\Domain\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ApiTokenManager::class)]
#[AllowMockObjectsWithoutExpectations]
final class ApiTokenManagerTest extends TestCase
{
    private Company $company;
    private MockClock $clock;
    private ApiTokenRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $em;
    private ApiTokenManager $manager;

    protected function setUp(): void
    {
        $this->company = new Company('Acme GmbH');
        $this->clock = new MockClock('2026-05-23 10:00:00');
        $this->repository = $this->createMock(ApiTokenRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->manager = new ApiTokenManager(
            repository: $this->repository,
            entityManager: $this->em,
            clock: $this->clock,
        );
    }

    #[Test]
    public function createPersistsTokenAndReturnsRawToken(): void
    {
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(ApiToken::class));
        $this->em->expects(self::once())->method('flush');

        $result = $this->manager->create($this->company, 'HR System');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['rawToken']);
        self::assertSame(hash('sha256', $result['rawToken']), $result['apiToken']->getTokenHash());
    }

    #[Test]
    public function createSetsCreatedAtFromClock(): void
    {
        $result = $this->manager->create($this->company, 'HR System');

        self::assertSame(
            '2026-05-23 10:00:00',
            $result['apiToken']->getCreatedAt()->format('Y-m-d H:i:s')
        );
    }

    #[Test]
    public function revokeCallsRevokeOnToken(): void
    {
        $token = new ApiToken(
            company: $this->company,
            name: 'HR',
            tokenHash: hash('sha256', 'raw'),
            createdAt: $this->clock->now(),
        );

        $this->em->expects(self::once())->method('flush');
        $this->manager->revoke($token);

        self::assertFalse($token->isActive($this->clock->now()));
    }

    #[Test]
    public function findActiveByRawTokenReturnsNullForUnknownToken(): void
    {
        $this->repository->method('findByHash')->willReturn(null);

        $result = $this->manager->findActiveByRawToken('unknowntoken');

        self::assertNull($result);
    }

    #[Test]
    public function findActiveByRawTokenReturnsNullForRevokedToken(): void
    {
        $token = new ApiToken(
            company: $this->company,
            name: 'HR',
            tokenHash: hash('sha256', 'therawtoken'),
            createdAt: new \DateTimeImmutable('2026-05-23 09:00:00'),
        );
        $token->revoke(new \DateTimeImmutable('2026-05-23 09:30:00'));

        $this->repository->method('findByHash')->willReturn($token);

        $result = $this->manager->findActiveByRawToken('therawtoken');

        self::assertNull($result);
    }

    #[Test]
    public function findActiveByRawTokenRecordsUsageForValidToken(): void
    {
        $token = new ApiToken(
            company: $this->company,
            name: 'HR',
            tokenHash: hash('sha256', 'therawtoken'),
            createdAt: new \DateTimeImmutable('2026-05-23 09:00:00'),
        );

        $this->repository->method('findByHash')->willReturn($token);
        $this->em->expects(self::once())->method('flush');

        $result = $this->manager->findActiveByRawToken('therawtoken');

        self::assertSame($token, $result);
        self::assertNotNull($token->getLastUsedAt());
    }

    #[Test]
    public function eachCreateCallGeneratesUniqueRawToken(): void
    {
        $first = $this->manager->create($this->company, 'Token A');
        $second = $this->manager->create($this->company, 'Token B');

        self::assertNotSame($first['rawToken'], $second['rawToken']);
    }
}
