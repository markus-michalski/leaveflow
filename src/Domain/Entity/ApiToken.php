<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Repository\ApiTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens')]
#[ORM\Index(name: 'idx_api_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_api_token_company', columns: ['company_id'])]
class ApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
        private Company $company,
        #[ORM\Column(length: 100)]
        private string $name,
        /** SHA-256 hex hash of the raw token — raw token is never stored. */
        #[ORM\Column(name: 'token_hash', length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $expiresAt = null,
    ) {
        $trimmed = trim($name);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('ApiToken name must not be empty.');
        }
        $this->name = $trimmed;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        if (null !== $this->revokedAt) {
            return false;
        }

        if (null !== $this->expiresAt && $this->expiresAt <= $now) {
            return false;
        }

        return true;
    }

    public function recordUsage(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    /** Soft-revokes this token. Idempotent — preserves the first revocation timestamp. */
    public function revoke(\DateTimeImmutable $now): void
    {
        if (null !== $this->revokedAt) {
            return;
        }
        $this->revokedAt = $now;
    }

    public function rename(string $name): void
    {
        $trimmed = trim($name);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('ApiToken name must not be empty.');
        }
        $this->name = $trimmed;
    }
}
