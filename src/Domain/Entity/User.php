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

    public function changeRole(UserRole $role): void
    {
        $this->role = $role;
    }

    public function eraseCredentials(): void
    {
        // No-op — plaintext credentials are never stored on the entity.
    }
}
