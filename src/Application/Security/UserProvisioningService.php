<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\AuthSource;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Single entry point for creating User records regardless of auth source.
 * Centralises the new-User logic previously scattered across AdminUserController
 * and SetupController. Phase 11.1+ IdP authenticators use provisionFromIdpClaims()
 * for just-in-time user creation on first SSO login.
 */
final class UserProvisioningService implements UserProvisioningServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Creates and persists a local-auth user. Hashes the password when provided;
     * leaves it null for invitation-flow users (password is set via reset-password link).
     *
     * Note: persist() is called, but flush() is the caller's responsibility so that
     * multiple provisioning calls can be batched in a single transaction.
     */
    public function provisionLocal(
        Company $company,
        string $email,
        UserRole $role,
        ?string $plainPassword = null,
    ): User {
        $user = new User($company, $email, $role);

        if (null !== $plainPassword) {
            $user->setHashedPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        }

        $this->entityManager->persist($user);

        return $user;
    }

    /**
     * Creates and persists an IdP-bound user for OAuth or LDAP flows.
     * The user has no local password — authentication happens exclusively via the IdP.
     *
     * Note: persist() is called, but flush() is the caller's responsibility.
     */
    public function provisionFromIdpClaims(
        Company $company,
        string $email,
        AuthSource $authSource,
        string $externalId,
        UserRole $role,
    ): User {
        $user = new User($company, $email, $role);
        $user->bindToIdp($authSource, $externalId);

        $this->entityManager->persist($user);

        return $user;
    }
}
