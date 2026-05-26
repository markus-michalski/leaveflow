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

use App\Application\Security\EntraUserResolver;
use App\Application\Security\UserProvisioningServiceInterface;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\AuthSource;
use App\Domain\Enum\UserRole;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;

#[CoversClass(EntraUserResolver::class)]
final class EntraUserResolverTest extends TestCase
{
    private CompanyRepository&Stub $companyRepository;
    private UserRepository&Stub $userRepository;
    private Company $company;

    protected function setUp(): void
    {
        $this->companyRepository = $this->createStub(CompanyRepository::class);
        $this->userRepository = $this->createStub(UserRepository::class);

        $this->company = new Company('Acme GmbH');
        $this->company->enableEntraOAuth();
    }

    private function withEnabledCompany(): void
    {
        $this->companyRepository->method('findOneBy')->willReturn($this->company);
    }

    // ── Happy paths ───────────────────────────────────────────────────────────

    #[Test]
    public function returnsExistingUserFoundByOid(): void
    {
        $this->withEnabledCompany();
        $existing = $this->makeUser(AuthSource::Entra, 'oid-123');
        $this->userRepository->method('findByIdp')->willReturn($existing);

        $result = $this->makeResolver()->resolve($this->makeAzureUser('oid-123', 'jane@acme.com'));

        self::assertSame($existing, $result);
    }

    #[Test]
    public function jitProvisionNewEntraUserWhenNoMatchFound(): void
    {
        $this->withEnabledCompany();
        $provisioned = $this->makeUser(AuthSource::Entra, 'oid-new');

        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn(null);

        $provisioning = $this->createMock(UserProvisioningServiceInterface::class);
        $provisioning
            ->expects(self::once())
            ->method('provisionFromIdpClaims')
            ->with($this->company, 'new@acme.com', AuthSource::Entra, 'oid-new', UserRole::Employee)
            ->willReturn($provisioned);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $result = $this->makeResolver($provisioning, $entityManager)->resolve(
            $this->makeAzureUser('oid-new', 'new@acme.com')
        );

        self::assertSame($provisioned, $result);
    }

    #[Test]
    public function tenantValidationPassesWhenTidMatches(): void
    {
        $this->withEnabledCompany();
        $this->company->setEntraOAuthTenantId('tenant-uuid-acme');
        $existing = $this->makeUser(AuthSource::Entra, 'oid-td');
        $this->userRepository->method('findByIdp')->willReturn($existing);

        $result = $this->makeResolver()->resolve(
            $this->makeAzureUser('oid-td', 'jane@acme.com', tenantId: 'tenant-uuid-acme')
        );

        self::assertSame($existing, $result);
    }

    #[Test]
    public function emailFallsBackToUpnWhenEmailClaimAbsent(): void
    {
        $this->withEnabledCompany();
        $provisioned = $this->makeUser(AuthSource::Entra, 'oid-upn');

        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn(null);

        $provisioning = $this->createMock(UserProvisioningServiceInterface::class);
        $provisioning
            ->expects(self::once())
            ->method('provisionFromIdpClaims')
            ->with($this->company, 'upn@acme.com', AuthSource::Entra, 'oid-upn', UserRole::Employee)
            ->willReturn($provisioned);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $azureUser = $this->makeAzureUser('oid-upn', null, upn: 'UPN@acme.com');

        $result = $this->makeResolver($provisioning, $entityManager)->resolve($azureUser);

        self::assertSame($provisioned, $result);
    }

    // ── Error paths ──────────────────────────────────────────────────────────

    #[Test]
    public function throwsWhenEntraOAuthDisabledForCompany(): void
    {
        $disabledCompany = new Company('No-Entra GmbH');
        $this->companyRepository->method('findOneBy')->willReturn($disabledCompany);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve($this->makeAzureUser('oid-x', 'user@example.com'));
    }

    #[Test]
    public function throwsWhenNoCompanyExists(): void
    {
        $this->companyRepository->method('findOneBy')->willReturn(null);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve($this->makeAzureUser('oid-x', 'user@example.com'));
    }

    #[Test]
    public function throwsWhenTenantIdMismatch(): void
    {
        $this->withEnabledCompany();
        $this->company->setEntraOAuthTenantId('tenant-uuid-acme');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve(
            $this->makeAzureUser('oid-x', 'jane@other.com', tenantId: 'tenant-uuid-other')
        );
    }

    #[Test]
    public function throwsWhenTenantRequiredButClaimAbsent(): void
    {
        $this->withEnabledCompany();
        $this->company->setEntraOAuthTenantId('tenant-uuid-acme');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve(
            $this->makeAzureUser('oid-x', 'jane@acme.com', tenantId: null)
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
            $this->makeAzureUser('oid-clash', 'existing@acme.com')
        );
    }

    #[Test]
    public function throwsWhenNoEmailResolvable(): void
    {
        $this->withEnabledCompany();

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->makeResolver()->resolve($this->makeAzureUser('oid-x', null, upn: null));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeResolver(
        ?UserProvisioningServiceInterface $provisioning = null,
        ?EntityManagerInterface $entityManager = null,
    ): EntraUserResolver {
        return new EntraUserResolver(
            $this->userRepository,
            $this->companyRepository,
            $provisioning ?? $this->createStub(UserProvisioningServiceInterface::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
        );
    }

    private function makeAzureUser(string $oid, ?string $email, ?string $upn = null, ?string $tenantId = 'tenant-uuid-default'): AzureResourceOwner
    {
        $stub = $this->createStub(AzureResourceOwner::class);
        $stub->method('getId')->willReturn($oid);
        $stub->method('getEmail')->willReturn($email);
        $stub->method('getUpn')->willReturn($upn);
        $stub->method('getTenantId')->willReturn($tenantId);

        return $stub;
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
