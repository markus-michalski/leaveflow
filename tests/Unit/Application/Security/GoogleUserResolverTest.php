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

namespace App\Tests\Unit\Application\Security;

use App\Application\Security\GoogleUserResolver;
use App\Application\Security\UserProvisioningServiceInterface;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\AuthSource;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\GoogleUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

#[CoversClass(GoogleUserResolver::class)]
final class GoogleUserResolverTest extends TestCase
{
    /** Stub: tests only set return values, never verify calls on this dep. */
    private CompanyRepository&Stub $companyRepository;
    private UserRepository&Stub $userRepository;
    private Company $company;

    protected function setUp(): void
    {
        $this->companyRepository = $this->createStub(CompanyRepository::class);
        $this->userRepository = $this->createStub(UserRepository::class);

        $this->company = new Company('Acme GmbH');
        $this->company->enableGoogleOAuth();
    }

    private function withEnabledCompany(): void
    {
        $this->companyRepository->method('findOneBy')->willReturn($this->company);
    }

    // ── Happy paths ───────────────────────────────────────────���─────────────

    #[Test]
    public function returnsExistingGoogleUserFoundByExternalId(): void
    {
        $this->withEnabledCompany();
        $existing = $this->makeUser(AuthSource::Google, 'sub-123');
        $this->userRepository->method('findByIdp')->willReturn($existing);

        $result = $this->makeResolver()->resolve($this->makeGoogleUser('sub-123', 'jane@acme.com'));

        self::assertSame($existing, $result);
    }

    #[Test]
    public function jitProvisionNewGoogleUserWhenNoMatchFound(): void
    {
        $this->withEnabledCompany();
        $provisioned = $this->makeUser(AuthSource::Google, 'sub-new');

        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn(null);

        $provisioning = $this->createMock(UserProvisioningServiceInterface::class);
        $provisioning
            ->expects(self::once())
            ->method('provisionFromIdpClaims')
            ->with($this->company, 'new@acme.com', AuthSource::Google, 'sub-new', UserRole::Employee)
            ->willReturn($provisioned);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $result = $this->makeResolver($provisioning, $entityManager)->resolve(
            $this->makeGoogleUser('sub-new', 'new@acme.com')
        );

        self::assertSame($provisioned, $result);
    }

    #[Test]
    public function hostedDomainValidationPassesWhenClaimMatches(): void
    {
        $this->withEnabledCompany();
        $this->company->setGoogleOAuthHostedDomain('acme.com');
        $existing = $this->makeUser(AuthSource::Google, 'sub-hd');
        $this->userRepository->method('findByIdp')->willReturn($existing);

        $result = $this->makeResolver()->resolve(
            $this->makeGoogleUser('sub-hd', 'jane@acme.com', hostedDomain: 'acme.com')
        );

        self::assertSame($existing, $result);
    }

    // ── Error paths ──────────────────────────────────────────────────────────

    #[Test]
    public function throwsWhenEmailNotVerified(): void
    {
        $this->withEnabledCompany();

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve($this->makeGoogleUser('sub-unverified', 'unverified@acme.com', emailVerified: false));
    }

    #[Test]
    public function throwsWhenGoogleOAuthDisabledForCompany(): void
    {
        $disabledCompany = new Company('No-Google GmbH'); // googleOAuthEnabled defaults false
        $this->companyRepository->method('findOneBy')->willReturn($disabledCompany);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve($this->makeGoogleUser('sub-x', 'user@example.com'));
    }

    #[Test]
    public function throwsWhenNoCompanyExists(): void
    {
        $this->companyRepository->method('findOneBy')->willReturn(null);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve($this->makeGoogleUser('sub-x', 'user@example.com'));
    }

    #[Test]
    public function throwsWhenHostedDomainClaimMismatch(): void
    {
        $this->withEnabledCompany();
        $this->company->setGoogleOAuthHostedDomain('acme.com');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve(
            $this->makeGoogleUser('sub-x', 'jane@other.com', hostedDomain: 'other.com')
        );
    }

    #[Test]
    public function throwsWhenHostedDomainRequiredButClaimAbsent(): void
    {
        $this->withEnabledCompany();
        $this->company->setGoogleOAuthHostedDomain('acme.com');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve(
            $this->makeGoogleUser('sub-x', 'personal@gmail.com', hostedDomain: null)
        );
    }

    #[Test]
    public function throwsWhenEmailAlreadyRegisteredWithDifferentAuthSource(): void
    {
        $this->withEnabledCompany();
        $localUser = $this->makeUser(AuthSource::Local, null);
        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn($localUser);

        $provisioning = $this->createMock(UserProvisioningServiceInterface::class);
        $provisioning->expects(self::never())->method('provisionFromIdpClaims');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver($provisioning)->resolve(
            $this->makeGoogleUser('sub-clash', 'existing@acme.com')
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeResolver(
        ?UserProvisioningServiceInterface $provisioning = null,
        ?EntityManagerInterface $entityManager = null,
    ): GoogleUserResolver {
        return new GoogleUserResolver(
            $this->userRepository,
            $this->companyRepository,
            $provisioning ?? $this->createStub(UserProvisioningServiceInterface::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
        );
    }

    private function makeGoogleUser(string $id, string $email, ?string $hostedDomain = null, bool $emailVerified = true): GoogleUser
    {
        $data = ['sub' => $id, 'email' => $email, 'email_verified' => $emailVerified];
        if (null !== $hostedDomain) {
            $data['hd'] = $hostedDomain;
        }

        return new GoogleUser($data);
    }

    private function makeUser(AuthSource $source, ?string $externalId): User
    {
        $user = new User(new Company('Test GmbH'), 'test@example.com', UserRole::Employee);
        if (AuthSource::Local !== $source && null !== $externalId) {
            $user->bindToIdp($source, $externalId);
        }

        return $user;
    }
}
