<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\UserRole;
use App\Domain\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
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
}
