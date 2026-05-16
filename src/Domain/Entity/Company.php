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

    /**
     * Multi-line postal address — Strasse/PLZ/Ort/Land in a single
     * free-text field. Structured fields would buy us nothing for a
     * field that ends up on PDF letterheads anyway.
     */
    #[ORM\Column(name: 'address', type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    /**
     * Logo path relative to public/uploads/. Rendered in the PDF
     * export header and (eventually) the app header. Null until the
     * admin uploads one.
     */
    #[ORM\Column(name: 'logo_path', length: 255, nullable: true)]
    private ?string $logoPath = null;

    /**
     * Primary brand color (hex, e.g. #3B82F6). Used for the PDF
     * export accent bar and headings.
     */
    #[ORM\Column(name: 'primary_color', length: 7, nullable: true)]
    private ?string $primaryColor = null;

    /**
     * USt-IdNr / Tax ID. Optional; shown on PDF exports for
     * professional invoicing context.
     */
    #[ORM\Column(name: 'tax_id', length: 50, nullable: true)]
    private ?string $taxId = null;

    /**
     * Handelsregister-Nummer. Optional; same use as taxId.
     */
    #[ORM\Column(name: 'commercial_register', length: 100, nullable: true)]
    private ?string $commercialRegister = null;

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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): void
    {
        $address = null === $address ? null : trim($address);
        $this->address = ('' === $address) ? null : $address;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): void
    {
        $this->logoPath = $logoPath;
    }

    public function getPrimaryColor(): ?string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(?string $primaryColor): void
    {
        if (null === $primaryColor || '' === trim($primaryColor)) {
            $this->primaryColor = null;

            return;
        }

        $normalized = strtoupper(trim($primaryColor));
        if (1 !== preg_match('/^#(?:[0-9A-F]{3}|[0-9A-F]{6})$/', $normalized)) {
            throw new \InvalidArgumentException(\sprintf('Company.primaryColor must be a hex color like "#RRGGBB" or "#RGB", got "%s".', $primaryColor));
        }
        $this->primaryColor = $normalized;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): void
    {
        $taxId = null === $taxId ? null : trim($taxId);
        $this->taxId = ('' === $taxId) ? null : $taxId;
    }

    public function getCommercialRegister(): ?string
    {
        return $this->commercialRegister;
    }

    public function setCommercialRegister(?string $register): void
    {
        $register = null === $register ? null : trim($register);
        $this->commercialRegister = ('' === $register) ? null : $register;
    }

    public function setRetentionPeriodMonths(int $months): void
    {
        if ($months < 1) {
            throw new \InvalidArgumentException('Company.retentionPeriodMonths must be at least 1.');
        }
        $this->retentionPeriodMonths = $months;
    }

    // ── Google Workspace OAuth ──────────────────────────────────────────────

    #[ORM\Column(name: 'google_oauth_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $googleOAuthEnabled = false;

    /**
     * Optional hosted-domain restriction (the `hd` claim in Google's OIDC
     * token). When set, only users from that Google Workspace domain may sign
     * in via Google. Leave null to allow any Google account.
     */
    #[ORM\Column(name: 'google_oauth_hosted_domain', length: 253, nullable: true)]
    private ?string $googleOAuthHostedDomain = null;

    public function isGoogleOAuthEnabled(): bool
    {
        return $this->googleOAuthEnabled;
    }

    public function enableGoogleOAuth(): void
    {
        $this->googleOAuthEnabled = true;
    }

    public function disableGoogleOAuth(): void
    {
        $this->googleOAuthEnabled = false;
    }

    public function getGoogleOAuthHostedDomain(): ?string
    {
        return $this->googleOAuthHostedDomain;
    }

    public function setGoogleOAuthHostedDomain(?string $domain): void
    {
        $domain = null === $domain ? null : strtolower(trim($domain));
        $this->googleOAuthHostedDomain = ('' === $domain) ? null : $domain;
    }
}
