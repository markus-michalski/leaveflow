<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Repository\CompanyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\Table(name: 'companies')]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * When true, every active user of this company must activate
     * two-factor authentication. Until {@see $twoFactorEnforcedFrom}
     * (the grace deadline) the UI shows a reminder; after it, every
     * route except logout and the 2FA-setup pages becomes inaccessible
     * for users without TOTP enabled.
     */
    #[ORM\Column(name: 'requires_two_factor', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $requiresTwoFactor = false;

    #[ORM\Column(name: 'two_factor_enforced_from', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $twoFactorEnforcedFrom = null;

    public function __construct(
        #[ORM\Column(length: 200)]
        private string $name,
        #[ORM\Column(name: 'retention_period_months', options: ['default' => 36])]
        private int $retentionPeriodMonths = 36,
        /**
         * Threshold in calendar days after which a still-Pending leave
         * request triggers an EscalationTriggered notification to admins.
         * Default 3 days mirrors typical SMB SLA expectations.
         */
        #[ORM\Column(name: 'approval_escalation_days', options: ['default' => 3])]
        private int $approvalEscalationDays = 3,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRetentionPeriodMonths(): int
    {
        return $this->retentionPeriodMonths;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getApprovalEscalationDays(): int
    {
        return $this->approvalEscalationDays;
    }

    public function setApprovalEscalationDays(int $days): void
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Company.approvalEscalationDays must be at least 1.');
        }
        $this->approvalEscalationDays = $days;
    }

    public function requiresTwoFactor(): bool
    {
        return $this->requiresTwoFactor;
    }

    public function getTwoFactorEnforcedFrom(): ?\DateTimeImmutable
    {
        return $this->twoFactorEnforcedFrom;
    }

    /**
     * Enables the 2FA requirement. The grace deadline must be in the
     * future relative to `$asOf` so existing users get a chance to set
     * up TOTP before they're locked out.
     */
    public function enableTwoFactorRequirement(\DateTimeInterface $enforcedFrom, \DateTimeInterface $asOf): void
    {
        $enforcedFrom = \DateTimeImmutable::createFromInterface($enforcedFrom)->setTime(0, 0);
        $asOf = \DateTimeImmutable::createFromInterface($asOf)->setTime(0, 0);
        if ($enforcedFrom < $asOf) {
            throw new \InvalidArgumentException('Company.twoFactorEnforcedFrom must not be in the past.');
        }
        $this->requiresTwoFactor = true;
        $this->twoFactorEnforcedFrom = $enforcedFrom;
    }

    public function disableTwoFactorRequirement(): void
    {
        $this->requiresTwoFactor = false;
        $this->twoFactorEnforcedFrom = null;
    }

    /**
     * True when the grace period has passed and 2FA enforcement is
     * active. False before the deadline (banner-only phase) or when
     * the requirement is disabled.
     *
     * Accepts any DateTimeInterface — Twig's `date()` returns the
     * mutable variant, so the immutable-only signature used to throw
     * straight from the profile template.
     */
    public function isTwoFactorEnforced(\DateTimeInterface $asOf): bool
    {
        if (!$this->requiresTwoFactor || null === $this->twoFactorEnforcedFrom) {
            return false;
        }

        $normalized = \DateTimeImmutable::createFromInterface($asOf)->setTime(0, 0);

        return $normalized >= $this->twoFactorEnforcedFrom;
    }
}
