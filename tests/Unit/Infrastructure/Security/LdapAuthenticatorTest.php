<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Application\Security\EncryptionServiceInterface;
use App\Application\Security\LdapUserResolver;
use App\Application\Security\UserProvisioningServiceInterface;
use App\Domain\Entity\Company;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\UserRepository;
use App\Infrastructure\Security\LdapAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

#[CoversClass(LdapAuthenticator::class)]
class LdapAuthenticatorTest extends TestCase
{
    private CompanyRepository&MockObject $companyRepo;
    private EncryptionServiceInterface&MockObject $encryption;
    private LoggerInterface&MockObject $logger;
    private LdapAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->companyRepo = $this->createMock(CompanyRepository::class);
        $this->encryption = $this->createMock(EncryptionServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $resolver = new LdapUserResolver(
            $this->createStub(UserRepository::class),
            $this->createStub(CompanyRepository::class),
            $this->createStub(UserProvisioningServiceInterface::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $this->authenticator = new LdapAuthenticator(
            $this->companyRepo,
            $this->createStub(UserRepository::class),
            $resolver,
            $this->createStub(RouterInterface::class),
            $this->encryption,
            $this->logger,
        );
    }

    #[Test]
    public function throwsAuthenticationExceptionWhenDecryptReturnsNullForStoredPassword(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getLdapHost')->willReturn('ldap.example.com');
        $company->method('getLdapPort')->willReturn(389);
        $company->method('getLdapEncryption')->willReturn('none');
        $company->method('getLdapBindDn')->willReturn('cn=service,dc=example,dc=com');
        $company->method('getLdapBindPassword')->willReturn('some-encrypted-value');

        $this->companyRepo->method('findOneBy')->willReturn($company);
        $this->encryption->method('tryDecrypt')->with('some-encrypted-value')->willReturn(null);

        $this->logger->expects($this->once())->method('critical');

        $request = Request::create('/login', 'POST', [
            '_username' => 'user@example.com',
            '_password' => 'pw',
            '_csrf_token' => 'csrf-token',
        ]);
        $request->attributes->set('_route', 'app_login');

        $this->expectException(AuthenticationException::class);

        $this->authenticator->authenticate($request);
    }

    #[Test]
    public function doesNotLogCriticalWhenNoPasswordStored(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getLdapHost')->willReturn('ldap.example.com');
        $company->method('getLdapPort')->willReturn(389);
        $company->method('getLdapEncryption')->willReturn('none');
        $company->method('getLdapBindDn')->willReturn(null);
        $company->method('getLdapBindPassword')->willReturn(null);

        $this->companyRepo->method('findOneBy')->willReturn($company);
        $this->encryption->expects($this->never())->method('tryDecrypt');
        $this->logger->expects($this->never())->method('critical');

        $request = Request::create('/login', 'POST', [
            '_username' => 'user@example.com',
            '_password' => 'pw',
            '_csrf_token' => 'csrf-token',
        ]);

        // ext_ldap is not available in unit tests — any exception here is expected
        try {
            $this->authenticator->authenticate($request);
        } catch (\Throwable) {
        }
    }
}
