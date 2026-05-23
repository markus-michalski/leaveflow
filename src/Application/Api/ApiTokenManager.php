<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Company;
use App\Domain\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Generates and manages API tokens for machine-to-machine access.
 *
 * Raw tokens are shown exactly once (returned from create()). Only the
 * SHA-256 hash is persisted — there is no way to recover a lost token.
 */
final class ApiTokenManager implements ApiTokenManagerInterface
{
    public function __construct(
        private readonly ApiTokenRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Generates a new token for the given company.
     *
     * Returns the raw token string (show it once to the admin, then discard)
     * and the persisted ApiToken entity.
     *
     * @return array{rawToken: string, apiToken: ApiToken}
     */
    public function create(Company $company, string $name, ?\DateTimeImmutable $expiresAt = null): array
    {
        $rawToken = $this->generateRawToken();
        $hash = hash('sha256', $rawToken);

        $apiToken = new ApiToken(
            company: $company,
            name: $name,
            tokenHash: $hash,
            createdAt: $this->clock->now(),
            expiresAt: $expiresAt,
        );

        $this->entityManager->persist($apiToken);
        $this->entityManager->flush();

        return ['rawToken' => $rawToken, 'apiToken' => $apiToken];
    }

    public function revoke(ApiToken $token): void
    {
        $token->revoke($this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * Validates a raw bearer token and returns the matching active ApiToken,
     * or null if not found / revoked / expired. Also updates last_used_at.
     */
    public function findActiveByRawToken(string $rawToken): ?ApiToken
    {
        $hash = hash('sha256', $rawToken);
        $token = $this->repository->findByHash($hash);

        if (null === $token) {
            return null;
        }

        $now = $this->clock->now();

        if (!$token->isActive($now)) {
            return null;
        }

        $token->recordUsage($now);
        $this->entityManager->flush();

        return $token;
    }

    private function generateRawToken(): string
    {
        // 32 random bytes → 64 hex chars. URL-safe, no padding issues.
        return bin2hex(random_bytes(32));
    }
}
