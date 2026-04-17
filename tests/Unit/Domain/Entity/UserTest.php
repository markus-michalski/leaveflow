<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    private function makeCompany(): Company
    {
        return new Company('Acme GmbH');
    }

    #[Test]
    public function emailIsNormalizedToLowercase(): void
    {
        $user = new User($this->makeCompany(), 'Jane.Doe@Example.COM', UserRole::Employee);

        self::assertSame('jane.doe@example.com', $user->getEmail());
    }

    #[Test]
    public function userIdentifierIsEmail(): void
    {
        $user = new User($this->makeCompany(), 'jane@example.com', UserRole::Manager);

        self::assertSame('jane@example.com', $user->getUserIdentifier());
    }

    #[Test]
    public function getRolesIncludesEnumRoleAndRoleUser(): void
    {
        $user = new User($this->makeCompany(), 'jane@example.com', UserRole::Manager);

        self::assertSame(['ROLE_MANAGER', 'ROLE_USER'], $user->getRoles());
    }

    #[Test]
    public function getRolesDoesNotDuplicateRoleUser(): void
    {
        $user = new User($this->makeCompany(), 'jane@example.com', UserRole::Employee);

        $roles = $user->getRoles();

        self::assertSame(array_unique($roles), $roles);
    }

    #[Test]
    public function isActiveByDefault(): void
    {
        $user = new User($this->makeCompany(), 'jane@example.com', UserRole::Employee);

        self::assertTrue($user->isActive());
    }

    #[Test]
    public function canBeDeactivated(): void
    {
        $user = new User($this->makeCompany(), 'jane@example.com', UserRole::Employee);
        $user->deactivate();

        self::assertFalse($user->isActive());
    }

    #[Test]
    public function canBeReactivated(): void
    {
        $user = new User($this->makeCompany(), 'jane@example.com', UserRole::Employee);
        $user->deactivate();
        $user->activate();

        self::assertTrue($user->isActive());
    }

    #[Test]
    public function passwordStartsNullUntilHashed(): void
    {
        $user = new User($this->makeCompany(), 'jane@example.com', UserRole::Employee);

        self::assertNull($user->getPassword());
    }

    #[Test]
    public function storesHashedPassword(): void
    {
        $user = new User($this->makeCompany(), 'jane@example.com', UserRole::Employee);
        $user->setHashedPassword('$2y$13$hashvalue');

        self::assertSame('$2y$13$hashvalue', $user->getPassword());
    }

    #[Test]
    public function roleCanBeChanged(): void
    {
        $user = new User($this->makeCompany(), 'jane@example.com', UserRole::Employee);
        $user->changeRole(UserRole::Manager);

        self::assertSame(UserRole::Manager, $user->getRole());
        self::assertContains('ROLE_MANAGER', $user->getRoles());
    }

    #[Test]
    public function belongsToCompany(): void
    {
        $company = $this->makeCompany();
        $user = new User($company, 'jane@example.com', UserRole::Employee);

        self::assertSame($company, $user->getCompany());
    }

    #[Test]
    public function rejectsEmptyEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User($this->makeCompany(), '   ', UserRole::Employee);
    }
}
