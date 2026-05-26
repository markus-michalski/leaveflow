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
