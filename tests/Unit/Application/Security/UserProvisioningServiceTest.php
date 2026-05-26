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

use App\Application\Security\UserProvisioningService;
use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\AuthSource;
use App\Domain\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(UserProvisioningService::class)]
final class UserProvisioningServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    /** Stub for tests that don't verify password-hashing behaviour. */
    private UserPasswordHasherInterface&Stub $passwordHasherStub;
    private UserProvisioningService $service;
    private Company $company;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasherStub = $this->createStub(UserPasswordHasherInterface::class);
        $this->service = new UserProvisioningService($this->entityManager, $this->passwordHasherStub);
        $this->company = new Company('Acme GmbH');
    }

    #[Test]
    public function provisionLocalCreatesUserWithLocalAuthSource(): void
    {
        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));

        $user = $this->service->provisionLocal($this->company, 'jane@example.com', UserRole::Employee);

        self::assertSame('jane@example.com', $user->getEmail());
        self::assertSame(AuthSource::Local, $user->getAuthSource());
        self::assertNull($user->getExternalId());
    }

    #[Test]
    public function provisionLocalHashesPasswordWhenProvided(): void
    {
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->willReturnCallback(static fn (User $u, string $plain): string => 'hashed:'.$plain);

        $service = new UserProvisioningService($this->entityManager, $passwordHasher);

        $this->entityManager->expects(self::once())->method('persist');

        $user = $service->provisionLocal($this->company, 'jane@example.com', UserRole::Admin, 'plain-secret');

        self::assertSame('hashed:plain-secret', $user->getPassword());
    }

    #[Test]
    public function provisionLocalLeavesPasswordNullWhenNoneGiven(): void
    {
        $this->entityManager->expects(self::once())->method('persist');

        $user = $this->service->provisionLocal($this->company, 'jane@example.com', UserRole::Employee);

        self::assertNull($user->getPassword());
    }

    #[Test]
    public function provisionFromIdpClaimsCreatesUserBoundToIdp(): void
    {
        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));

        $user = $this->service->provisionFromIdpClaims(
            $this->company,
            'jane@example.com',
            AuthSource::Google,
            'google-sub-12345',
            UserRole::Employee,
        );

        self::assertSame('jane@example.com', $user->getEmail());
        self::assertSame(AuthSource::Google, $user->getAuthSource());
        self::assertSame('google-sub-12345', $user->getExternalId());
        self::assertNull($user->getPassword());
    }

    #[Test]
    public function provisionFromIdpClaimsRejectsLocalAuthSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->entityManager->expects(self::never())->method('persist');

        $this->service->provisionFromIdpClaims(
            $this->company,
            'jane@example.com',
            AuthSource::Local,
            'some-id',
            UserRole::Employee,
        );
    }
}
