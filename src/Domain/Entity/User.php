<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\UserRole;
use App\Domain\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TotpTwoFactorInterface, BackupCodeInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Employee::class)]
    private ?Employee $employee = null;

    /**
     * Personal calendar subscription token. Lazy-generated on first
     * profile-page visit (or via {@see resetIcalToken}). Used by the
     * public ICS feed endpoints (`/ical/personal/{token}.ics`,
     * `/ical/team/{token}.ics`) which can't authenticate via session
     * cookies because calendar clients don't send them.
     */
    #[ORM\Column(name: 'ical_token', length: 64, unique: true, nullable: true)]
    private ?string $icalToken = null;

    /**
     * TOTP shared secret, base32-encoded. Stored as-is; the dataset is
     * already protected by the database access boundary and re-encrypting
     * would require a key-management layer that's out of scope for v1.
     * Null when 2FA is not yet activated.
     */
    #[ORM\Column(name: 'totp_secret', length: 128, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(name: 'totp_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $totpEnabled = false;

    /**
     * Hashed one-time backup codes (sha256). Plaintext is shown to the
     * user exactly once at setup time and never recoverable from the DB.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'backup_codes', type: Types::JSON, options: ['default' => '[]'])]
    private array $backupCodes = [];

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'company_id', nullable: false)]
        private Company $company,
        string $email,
        #[ORM\Column(type: 'string', length: 20, enumType: UserRole::class)]
        private UserRole $role,
    ) {
        $normalized = strtolower(trim($email));
        if ('' === $normalized) {
            throw new \InvalidArgumentException('User email must not be empty.');
        }
        $this->email = $normalized;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([
            $this->role->asSymfonyRole(),
            'ROLE_USER',
        ]));
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setHashedPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function setRole(UserRole $role): void
    {
        $this->role = $role;
    }

    public function getEmployee(): ?Employee
    {
        return $this->employee;
    }

    public function hasEmployee(): bool
    {
        return null !== $this->employee;
    }

    public function eraseCredentials(): void
    {
        // No-op — plaintext credentials are never stored on the entity.
    }

    public function getIcalToken(): ?string
    {
        return $this->icalToken;
    }

    /**
     * Generates a fresh URL-safe token if none exists. Idempotent —
     * call from the profile-page controller without worrying about
     * duplicate rotation.
     */
    public function ensureIcalToken(): string
    {
        if (null === $this->icalToken) {
            $this->icalToken = bin2hex(random_bytes(32));
        }

        return $this->icalToken;
    }

    /**
     * Rotates the token, invalidating every previously-subscribed
     * calendar. Used when a token leaks (laptop lost, ex-employee
     * still subscribed).
     */
    public function resetIcalToken(): string
    {
        $this->icalToken = bin2hex(random_bytes(32));

        return $this->icalToken;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $secret): void
    {
        $this->totpSecret = $secret;
    }

    public function isTotpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    /**
     * @param list<string> $hashedBackupCodes
     */
    public function enableTotp(string $secret, array $hashedBackupCodes): void
    {
        $this->totpSecret = $secret;
        $this->totpEnabled = true;
        $this->backupCodes = $hashedBackupCodes;
    }

    /**
     * Disables 2FA and drops every credential — used both by the user
     * via /profile/2fa/disable and by admins via the lockout-recovery
     * action. There's no separate "admin disabled it" flag because the
     * audit trail lives in {@see LeaveEntitlementAuditEntry}-
     * style entries elsewhere; this entity stays a state holder.
     */
    public function disableTotp(): void
    {
        $this->totpSecret = null;
        $this->totpEnabled = false;
        $this->backupCodes = [];
    }

    /**
     * @param list<string> $hashedBackupCodes
     */
    public function replaceBackupCodes(array $hashedBackupCodes): void
    {
        $this->backupCodes = $hashedBackupCodes;
    }

    /**
     * @return list<string>
     */
    public function getBackupCodes(): array
    {
        return $this->backupCodes;
    }

    // ---- scheb/2fa-bundle TwoFactorInterface (TOTP) ----

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpEnabled && null !== $this->totpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->totpSecret) {
            return null;
        }

        // RFC 6238 defaults: SHA1, 6 digits, 30 second period — matches
        // every common authenticator app (Google Authenticator, Authy,
        // 1Password, Microsoft Authenticator).
        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    // ---- scheb/2fa-bundle BackupCodeInterface ----

    public function isBackupCode(string $code): bool
    {
        return \in_array($this->hashBackupCode($code), $this->backupCodes, true);
    }

    public function invalidateBackupCode(string $code): void
    {
        $hashed = $this->hashBackupCode($code);
        $this->backupCodes = array_values(array_filter(
            $this->backupCodes,
            static fn (string $stored): bool => $stored !== $hashed,
        ));
    }

    private function hashBackupCode(string $code): string
    {
        // SHA-256 is acceptable here: codes are 8+ random hex chars, the
        // attacker would need DB read access to even see the hashes, and
        // the codes are single-use. A KDF would buy us nothing.
        return hash('sha256', strtolower(trim($code)));
    }
}
