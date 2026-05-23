<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Api\CompanyAwareUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Synthetic security principal for machine-to-machine API access.
 *
 * Not persisted — created fresh on each request from a valid ApiToken.
 * Carries only the company context and the token ID for logging/audit.
 */
final readonly class ApiUser implements UserInterface, CompanyAwareUserInterface
{
    public function __construct(
        private int $apiTokenId,
        private int $companyId,
        private string $companyName,
    ) {
    }

    public function getApiTokenId(): int
    {
        return $this->apiTokenId;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function getUserIdentifier(): string
    {
        return 'api-token-'.$this->apiTokenId;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_API'];
    }

    public function eraseCredentials(): void
    {
        // No sensitive data to clear — raw token is never stored here.
    }
}
