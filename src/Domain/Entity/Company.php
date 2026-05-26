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

namespace App\Domain\Entity;

use App\Domain\Enum\ExitLeaveHandling;
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

    /**
     * How unused leave balance is handled when an employee exits mid-year.
     * PayOut is the statutory default (§7 Abs. 4 BUrlG).
     */
    #[ORM\Column(name: 'exit_leave_handling', length: 30, enumType: ExitLeaveHandling::class, options: ['default' => 'pay_out'])]
    private ExitLeaveHandling $exitLeaveHandling = ExitLeaveHandling::PayOut;

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

    public function getExitLeaveHandling(): ExitLeaveHandling
    {
        return $this->exitLeaveHandling;
    }

    public function setExitLeaveHandling(ExitLeaveHandling $handling): void
    {
        $this->exitLeaveHandling = $handling;
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

    // ── Microsoft Entra ID OAuth ────────────────────────────────────────────

    #[ORM\Column(name: 'entra_oauth_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $entraOAuthEnabled = false;

    /**
     * Optional tenant ID restriction. When set, only tokens whose `tid`
     * claim matches this value are accepted. Leave null to allow any tenant
     * (useful for multi-tenant Entra app registrations).
     */
    #[ORM\Column(name: 'entra_oauth_tenant_id', length: 36, nullable: true)]
    private ?string $entraOAuthTenantId = null;

    public function isEntraOAuthEnabled(): bool
    {
        return $this->entraOAuthEnabled;
    }

    public function enableEntraOAuth(): void
    {
        $this->entraOAuthEnabled = true;
    }

    public function disableEntraOAuth(): void
    {
        $this->entraOAuthEnabled = false;
    }

    public function getEntraOAuthTenantId(): ?string
    {
        return $this->entraOAuthTenantId;
    }

    public function setEntraOAuthTenantId(?string $tenantId): void
    {
        $tenantId = null === $tenantId ? null : trim($tenantId);
        $this->entraOAuthTenantId = ('' === $tenantId) ? null : $tenantId;
    }

    // ── LDAP / Active Directory ─────────────────────────────────────────────

    #[ORM\Column(name: 'ldap_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $ldapEnabled = false;

    /** Hostname or IP of the LDAP/AD server. */
    #[ORM\Column(name: 'ldap_host', length: 253, nullable: true)]
    private ?string $ldapHost = null;

    /** Port number. 389 for plain/STARTTLS, 636 for LDAPS. */
    #[ORM\Column(name: 'ldap_port', nullable: true)]
    private ?int $ldapPort = null;

    /** Transport security: 'none', 'tls' (STARTTLS), or 'ssl' (LDAPS). */
    #[ORM\Column(name: 'ldap_encryption', length: 4, nullable: true)]
    private ?string $ldapEncryption = null;

    /** DN of the service account used for searching the directory. Null = anonymous bind. */
    #[ORM\Column(name: 'ldap_bind_dn', length: 512, nullable: true)]
    private ?string $ldapBindDn = null;

    #[ORM\Column(name: 'ldap_bind_password', type: Types::TEXT, nullable: true)]
    private ?string $ldapBindPassword = null;

    /** Base DN for user searches, e.g. "ou=users,dc=example,dc=com". */
    #[ORM\Column(name: 'ldap_base_dn', length: 512, nullable: true)]
    private ?string $ldapBaseDn = null;

    /**
     * LDAP filter used to locate a user by username.
     * Use {username} as placeholder — it is replaced at runtime.
     * Default: "(uid={username})". For Active Directory: "(sAMAccountName={username})".
     */
    #[ORM\Column(name: 'ldap_user_filter', length: 255, nullable: true)]
    private ?string $ldapUserFilter = null;

    /** DN of the LDAP group whose members receive the Manager role. */
    #[ORM\Column(name: 'ldap_group_manager_dn', length: 512, nullable: true)]
    private ?string $ldapGroupManagerDn = null;

    /** DN of the LDAP group whose members receive the Admin role. */
    #[ORM\Column(name: 'ldap_group_admin_dn', length: 512, nullable: true)]
    private ?string $ldapGroupAdminDn = null;

    #[ORM\Column(name: 'teams_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $teamsEnabled = false;

    /** Incoming webhook URL provided by Teams — not a secret, no encryption needed. */
    #[ORM\Column(name: 'teams_webhook_url', type: Types::TEXT, nullable: true)]
    private ?string $teamsWebhookUrl = null;

    // ── Slack Bot ───────────────────────────────────────────────────────────

    #[ORM\Column(name: 'slack_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $slackEnabled = false;

    /** Bot token (xoxb-…). Stored encrypted via EncryptionService. */
    #[ORM\Column(name: 'slack_bot_token', length: 512, nullable: true)]
    private ?string $slackBotToken = null;

    /** Signing secret for HMAC-SHA256 request verification. Stored encrypted. */
    #[ORM\Column(name: 'slack_signing_secret', length: 512, nullable: true)]
    private ?string $slackSigningSecret = null;

    /** Default channel to post leave notifications (e.g. C0123456789). */
    #[ORM\Column(name: 'slack_channel_id', length: 32, nullable: true)]
    private ?string $slackChannelId = null;

    public function isLdapEnabled(): bool
    {
        return $this->ldapEnabled;
    }

    public function enableLdap(): void
    {
        $this->ldapEnabled = true;
    }

    public function disableLdap(): void
    {
        $this->ldapEnabled = false;
    }

    public function getLdapHost(): ?string
    {
        return $this->ldapHost;
    }

    public function setLdapHost(?string $host): void
    {
        $host = null === $host ? null : trim($host);
        $this->ldapHost = ('' === $host) ? null : $host;
    }

    public function getLdapPort(): ?int
    {
        return $this->ldapPort;
    }

    public function setLdapPort(?int $port): void
    {
        $this->ldapPort = $port;
    }

    public function getLdapEncryption(): ?string
    {
        return $this->ldapEncryption;
    }

    public function setLdapEncryption(?string $encryption): void
    {
        $this->ldapEncryption = $encryption;
    }

    public function getLdapBindDn(): ?string
    {
        return $this->ldapBindDn;
    }

    public function setLdapBindDn(?string $dn): void
    {
        $dn = null === $dn ? null : trim($dn);
        $this->ldapBindDn = ('' === $dn) ? null : $dn;
    }

    public function getLdapBindPassword(): ?string
    {
        return $this->ldapBindPassword;
    }

    public function setLdapBindPassword(?string $password): void
    {
        $password = null === $password ? null : $password;
        $this->ldapBindPassword = ('' === $password) ? null : $password;
    }

    public function getLdapBaseDn(): ?string
    {
        return $this->ldapBaseDn;
    }

    public function setLdapBaseDn(?string $baseDn): void
    {
        $baseDn = null === $baseDn ? null : trim($baseDn);
        $this->ldapBaseDn = ('' === $baseDn) ? null : $baseDn;
    }

    public function getLdapUserFilter(): ?string
    {
        return $this->ldapUserFilter;
    }

    public function setLdapUserFilter(?string $filter): void
    {
        $filter = null === $filter ? null : trim($filter);
        $this->ldapUserFilter = ('' === $filter) ? null : $filter;
    }

    public function getLdapGroupManagerDn(): ?string
    {
        return $this->ldapGroupManagerDn;
    }

    public function setLdapGroupManagerDn(?string $dn): void
    {
        $dn = null === $dn ? null : trim($dn);
        $this->ldapGroupManagerDn = ('' === $dn) ? null : $dn;
    }

    public function getLdapGroupAdminDn(): ?string
    {
        return $this->ldapGroupAdminDn;
    }

    public function setLdapGroupAdminDn(?string $dn): void
    {
        $dn = null === $dn ? null : trim($dn);
        $this->ldapGroupAdminDn = ('' === $dn) ? null : $dn;
    }

    public function isTeamsEnabled(): bool
    {
        return $this->teamsEnabled;
    }

    public function enableTeams(): void
    {
        $this->teamsEnabled = true;
    }

    public function disableTeams(): void
    {
        $this->teamsEnabled = false;
    }

    public function getTeamsWebhookUrl(): ?string
    {
        return $this->teamsWebhookUrl;
    }

    public function setTeamsWebhookUrl(?string $url): void
    {
        if (null === $url || '' === trim($url)) {
            $this->teamsWebhookUrl = null;

            return;
        }

        $url = trim($url);

        // Require https:// — Teams incoming webhooks and Power Automate workflow URLs are always HTTPS.
        // This also prevents SSRF via plaintext http:// to internal services.
        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('Teams webhook URL must use https://.');
        }

        $this->teamsWebhookUrl = $url;
    }

    public function isSlackEnabled(): bool
    {
        return $this->slackEnabled;
    }

    public function enableSlack(): void
    {
        $this->slackEnabled = true;
    }

    public function disableSlack(): void
    {
        $this->slackEnabled = false;
    }

    public function getSlackBotToken(): ?string
    {
        return $this->slackBotToken;
    }

    public function setSlackBotToken(?string $encryptedToken): void
    {
        $encryptedToken = null === $encryptedToken ? null : trim($encryptedToken);
        $this->slackBotToken = ('' === $encryptedToken) ? null : $encryptedToken;
    }

    public function getSlackSigningSecret(): ?string
    {
        return $this->slackSigningSecret;
    }

    public function setSlackSigningSecret(?string $encryptedSecret): void
    {
        $encryptedSecret = null === $encryptedSecret ? null : trim($encryptedSecret);
        $this->slackSigningSecret = ('' === $encryptedSecret) ? null : $encryptedSecret;
    }

    public function getSlackChannelId(): ?string
    {
        return $this->slackChannelId;
    }

    public function setSlackChannelId(?string $channelId): void
    {
        $channelId = null === $channelId ? null : trim($channelId);
        $this->slackChannelId = ('' === $channelId) ? null : $channelId;
    }
}
