<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Company;

interface ApiTokenManagerInterface
{
    /**
     * @return array{rawToken: string, apiToken: ApiToken}
     */
    public function create(Company $company, string $name, ?\DateTimeImmutable $expiresAt = null): array;

    public function revoke(ApiToken $token): void;

    public function findActiveByRawToken(string $rawToken): ?ApiToken;
}
