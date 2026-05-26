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
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Resolves (or JIT-provisions) a local User from a Google OIDC token payload.
 *
 * Lookup order:
 *   1. Match by (auth_source=google, external_id=sub) — survives email renames
 *   2. Reject if email already belongs to a different auth source
 *   3. JIT-provision a new Employee-role User and flush
 */
final class GoogleUserResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly UserProvisioningServiceInterface $provisioning,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(GoogleUser $googleUser): User
    {
        $company = $this->companyRepository->findOneBy([]);

        if (null === $company || !$company->isGoogleOAuthEnabled()) {
            throw new CustomUserMessageAuthenticationException('google_login_disabled');
        }

        $requiredHd = $company->getGoogleOAuthHostedDomain();
        if (null !== $requiredHd && $googleUser->getHostedDomain() !== $requiredHd) {
            throw new CustomUserMessageAuthenticationException('google_wrong_hosted_domain');
        }

        $claims = $googleUser->toArray();
        if (true !== ($claims['email_verified'] ?? null)) {
            throw new CustomUserMessageAuthenticationException('google_email_not_verified');
        }

        $externalId = $googleUser->getId();
        $email = strtolower(trim((string) $googleUser->getEmail()));

        $user = $this->userRepository->findByIdp(AuthSource::Google, $externalId);
        if (null !== $user) {
            return $user;
        }

        $existingByEmail = $this->userRepository->findOneByEmail($email);
        if (null !== $existingByEmail) {
            throw new CustomUserMessageAuthenticationException('google_email_taken');
        }

        $user = $this->provisioning->provisionFromIdpClaims(
            $company,
            $email,
            AuthSource::Google,
            $externalId,
            UserRole::Employee,
        );
        $this->entityManager->flush();

        return $user;
    }
}
