<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Security;

use App\Application\Security\LdapUserData;
use App\Application\Security\LdapUserResolver;
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

#[CoversClass(LdapUserResolver::class)]
final class LdapUserResolverTest extends TestCase
{
    private CompanyRepository&Stub $companyRepository;
    private UserRepository&Stub $userRepository;
    private Company $company;

    protected function setUp(): void
    {
        $this->companyRepository = $this->createStub(CompanyRepository::class);
        $this->userRepository = $this->createStub(UserRepository::class);

        $this->company = new Company('Acme GmbH');
        $this->company->enableLdap();
    }

    private function withEnabledCompany(): void
    {
        $this->companyRepository->method('findOneBy')->willReturn($this->company);
    }

    // ── Happy paths ───────────────────────────────────────────────────────────

    #[Test]
    public function returnsExistingUserFoundByDistinguishedName(): void
    {
        $this->withEnabledCompany();
        $existing = $this->makeUser(AuthSource::Ldap, 'uid=alice,ou=users,dc=acme,dc=test');
        $this->userRepository->method('findByIdp')->willReturn($existing);

        $result = $this->makeResolver()->resolve($this->makeLdapUser('uid=alice,ou=users,dc=acme,dc=test', 'alice@acme.com'));

        self::assertSame($existing, $result);
    }

    #[Test]
    public function jitProvisionNewUserWithEmployeeRoleByDefault(): void
    {
        $this->withEnabledCompany();
        $provisioned = $this->makeUser(AuthSource::Ldap, 'uid=new,ou=users,dc=acme,dc=test');

        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn(null);

        $provisioning = $this->createMock(UserProvisioningServiceInterface::class);
        $provisioning
            ->expects(self::once())
            ->method('provisionFromIdpClaims')
            ->with($this->company, 'new@acme.com', AuthSource::Ldap, 'uid=new,ou=users,dc=acme,dc=test', UserRole::Employee)
            ->willReturn($provisioned);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $result = $this->makeResolver($provisioning, $entityManager)->resolve(
            $this->makeLdapUser('uid=new,ou=users,dc=acme,dc=test', 'new@acme.com')
        );

        self::assertSame($provisioned, $result);
    }

    #[Test]
    public function assignsManagerRoleWhenUserIsInManagerGroup(): void
    {
        $this->company->setLdapGroupManagerDn('cn=managers,ou=groups,dc=acme,dc=test');
        $this->withEnabledCompany();

        $provisioned = $this->makeUser(AuthSource::Ldap, 'uid=bob,ou=users,dc=acme,dc=test');
        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn(null);

        $provisioning = $this->createMock(UserProvisioningServiceInterface::class);
        $provisioning
            ->expects(self::once())
            ->method('provisionFromIdpClaims')
            ->with($this->company, 'bob@acme.com', AuthSource::Ldap, 'uid=bob,ou=users,dc=acme,dc=test', UserRole::Manager)
            ->willReturn($provisioned);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $this->makeResolver($provisioning, $entityManager)->resolve(
            $this->makeLdapUser(
                'uid=bob,ou=users,dc=acme,dc=test',
                'bob@acme.com',
                memberOf: ['cn=managers,ou=groups,dc=acme,dc=test', 'cn=staff,ou=groups,dc=acme,dc=test'],
            )
        );
    }

    #[Test]
    public function assignsAdminRoleWhenUserIsInAdminGroup(): void
    {
        $this->company->setLdapGroupAdminDn('cn=admins,ou=groups,dc=acme,dc=test');
        $this->withEnabledCompany();

        $provisioned = $this->makeUser(AuthSource::Ldap, 'uid=carol,ou=users,dc=acme,dc=test');
        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn(null);

        $provisioning = $this->createMock(UserProvisioningServiceInterface::class);
        $provisioning
            ->expects(self::once())
            ->method('provisionFromIdpClaims')
            ->with($this->company, 'carol@acme.com', AuthSource::Ldap, 'uid=carol,ou=users,dc=acme,dc=test', UserRole::Admin)
            ->willReturn($provisioned);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $this->makeResolver($provisioning, $entityManager)->resolve(
            $this->makeLdapUser(
                'uid=carol,ou=users,dc=acme,dc=test',
                'carol@acme.com',
                memberOf: ['cn=admins,ou=groups,dc=acme,dc=test'],
            )
        );
    }

    #[Test]
    public function adminRoleTakesPrecedenceWhenUserIsInBothAdminAndManagerGroups(): void
    {
        $this->company->setLdapGroupManagerDn('cn=managers,ou=groups,dc=acme,dc=test');
        $this->company->setLdapGroupAdminDn('cn=admins,ou=groups,dc=acme,dc=test');
        $this->withEnabledCompany();

        $provisioned = $this->makeUser(AuthSource::Ldap, 'uid=dave,ou=users,dc=acme,dc=test');
        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn(null);

        $provisioning = $this->createMock(UserProvisioningServiceInterface::class);
        $provisioning
            ->expects(self::once())
            ->method('provisionFromIdpClaims')
            ->with($this->company, 'dave@acme.com', AuthSource::Ldap, 'uid=dave,ou=users,dc=acme,dc=test', UserRole::Admin)
            ->willReturn($provisioned);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $this->makeResolver($provisioning, $entityManager)->resolve(
            $this->makeLdapUser(
                'uid=dave,ou=users,dc=acme,dc=test',
                'dave@acme.com',
                memberOf: [
                    'cn=managers,ou=groups,dc=acme,dc=test',
                    'cn=admins,ou=groups,dc=acme,dc=test',
                ],
            )
        );
    }

    // ── Error paths ───────────────────────────────────────────────────────────

    #[Test]
    public function throwsWhenLdapIsDisabled(): void
    {
        $company = new Company('Acme GmbH'); // ldap disabled by default
        $this->companyRepository->method('findOneBy')->willReturn($company);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('ldap_login_disabled');

        $this->makeResolver()->resolve($this->makeLdapUser('uid=alice,ou=users,dc=acme,dc=test', 'alice@acme.com'));
    }

    #[Test]
    public function throwsWhenNoCompanyExists(): void
    {
        $this->companyRepository->method('findOneBy')->willReturn(null);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('ldap_login_disabled');

        $this->makeResolver()->resolve($this->makeLdapUser('uid=alice,ou=users,dc=acme,dc=test', 'alice@acme.com'));
    }

    #[Test]
    public function throwsWhenEmailAlreadyBelongsToLocalUser(): void
    {
        $this->withEnabledCompany();
        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn($this->makeUser(AuthSource::Local, null));

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('ldap_email_taken');

        $this->makeResolver()->resolve($this->makeLdapUser('uid=alice,ou=users,dc=acme,dc=test', 'alice@acme.com'));
    }

    #[Test]
    public function employeeRoleIsAssignedWhenNoGroupDnsAreConfigured(): void
    {
        // Company has no group DNs set — everyone gets Employee
        $this->withEnabledCompany();

        $provisioned = $this->makeUser(AuthSource::Ldap, 'uid=frank,ou=users,dc=acme,dc=test');
        $this->userRepository->method('findByIdp')->willReturn(null);
        $this->userRepository->method('findOneByEmail')->willReturn(null);

        $provisioning = $this->createMock(UserProvisioningServiceInterface::class);
        $provisioning
            ->expects(self::once())
            ->method('provisionFromIdpClaims')
            ->with($this->company, 'frank@acme.com', AuthSource::Ldap, 'uid=frank,ou=users,dc=acme,dc=test', UserRole::Employee)
            ->willReturn($provisioned);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $this->makeResolver($provisioning, $entityManager)->resolve(
            $this->makeLdapUser(
                'uid=frank,ou=users,dc=acme,dc=test',
                'frank@acme.com',
                memberOf: ['cn=admins,ou=groups,dc=acme,dc=test'], // ignored — no group DN configured
            )
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeResolver(
        ?UserProvisioningServiceInterface $provisioning = null,
        ?EntityManagerInterface $entityManager = null,
    ): LdapUserResolver {
        return new LdapUserResolver(
            $this->userRepository,
            $this->companyRepository,
            $provisioning ?? $this->createStub(UserProvisioningServiceInterface::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
        );
    }

    /**
     * @param list<string> $memberOf
     */
    private function makeLdapUser(
        string $dn,
        string $email,
        ?string $displayName = null,
        array $memberOf = [],
    ): LdapUserData {
        return new LdapUserData($dn, $email, $displayName, $memberOf);
    }

    private function makeUser(AuthSource $authSource, ?string $externalId): User
    {
        $user = $this->createStub(User::class);
        $user->method('getAuthSource')->willReturn($authSource);
        $user->method('getExternalId')->willReturn($externalId);

        return $user;
    }
}
