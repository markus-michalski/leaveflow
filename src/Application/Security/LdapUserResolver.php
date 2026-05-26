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

namespace App\Application\Security;

use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\AuthSource;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Resolves (or JIT-provisions) a local User from data extracted from an LDAP entry.
 *
 * Lookup order:
 *   1. Match by (auth_source=ldap, external_id=DN) — survives email changes
 *   2. Reject if email already belongs to a different auth source
 *   3. Determine role from group membership (Admin > Manager > Employee)
 *   4. JIT-provision a new User and flush
 */
final class LdapUserResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly UserProvisioningServiceInterface $provisioning,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(LdapUserData $ldapUser): User
    {
        $company = $this->companyRepository->findOneBy([]);

        if (null === $company || !$company->isLdapEnabled()) {
            throw new CustomUserMessageAuthenticationException('ldap_login_disabled');
        }

        $user = $this->userRepository->findByIdp(AuthSource::Ldap, $ldapUser->distinguishedName);
        if (null !== $user) {
            return $user;
        }

        $existingByEmail = $this->userRepository->findOneByEmail($ldapUser->email);
        if (null !== $existingByEmail) {
            throw new CustomUserMessageAuthenticationException('ldap_email_taken');
        }

        $role = $this->resolveRole($company, $ldapUser->memberOf);

        $user = $this->provisioning->provisionFromIdpClaims(
            $company,
            $ldapUser->email,
            AuthSource::Ldap,
            $ldapUser->distinguishedName,
            $role,
        );
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param list<string> $memberOf
     */
    private function resolveRole(Company $company, array $memberOf): UserRole
    {
        $adminDn = $company->getLdapGroupAdminDn();
        if (null !== $adminDn && \in_array($adminDn, $memberOf, true)) {
            return UserRole::Admin;
        }

        $managerDn = $company->getLdapGroupManagerDn();
        if (null !== $managerDn && \in_array($managerDn, $memberOf, true)) {
            return UserRole::Manager;
        }

        return UserRole::Employee;
    }
}
