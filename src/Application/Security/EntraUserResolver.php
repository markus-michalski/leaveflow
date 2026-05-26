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

use App\Domain\Entity\User;
use App\Domain\Enum\AuthSource;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;

/**
 * Resolves (or JIT-provisions) a local User from a Microsoft Entra ID token payload.
 *
 * Lookup order:
 *   1. Match by (auth_source=entra, external_id=oid) — survives email renames
 *   2. Reject if email already belongs to a different auth source
 *   3. JIT-provision a new Employee-role User and flush
 */
final class EntraUserResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly UserProvisioningServiceInterface $provisioning,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(AzureResourceOwner $azureUser): User
    {
        $company = $this->companyRepository->findOneBy([]);

        if (null === $company || !$company->isEntraOAuthEnabled()) {
            throw new CustomUserMessageAuthenticationException('entra_login_disabled');
        }

        $requiredTenant = $company->getEntraOAuthTenantId();
        if (null !== $requiredTenant) {
            $tokenTenant = $azureUser->getTenantId();
            if (null === $tokenTenant || $tokenTenant !== $requiredTenant) {
                throw new CustomUserMessageAuthenticationException('entra_wrong_tenant');
            }
        }

        $rawEmail = $azureUser->getEmail() ?? $azureUser->getUpn();
        if (null === $rawEmail || '' === trim($rawEmail)) {
            throw new CustomUserMessageAuthenticationException('entra_no_email');
        }
        $email = strtolower(trim($rawEmail));

        $externalId = (string) $azureUser->getId();

        $user = $this->userRepository->findByIdp(AuthSource::Entra, $externalId);
        if (null !== $user) {
            return $user;
        }

        $existingByEmail = $this->userRepository->findOneByEmail($email);
        if (null !== $existingByEmail) {
            throw new CustomUserMessageAuthenticationException('entra_email_taken');
        }

        $user = $this->provisioning->provisionFromIdpClaims(
            $company,
            $email,
            AuthSource::Entra,
            $externalId,
            UserRole::Employee,
        );
        $this->entityManager->flush();

        return $user;
    }
}
