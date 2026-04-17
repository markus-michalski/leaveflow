<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Enum;

use App\Domain\Enum\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserRole::class)]
final class UserRoleTest extends TestCase
{
    /**
     * @return iterable<string, array{UserRole, string}>
     */
    public static function symfonyRoleProvider(): iterable
    {
        yield 'admin' => [UserRole::Admin, 'ROLE_ADMIN'];
        yield 'manager' => [UserRole::Manager, 'ROLE_MANAGER'];
        yield 'employee' => [UserRole::Employee, 'ROLE_EMPLOYEE'];
    }

    #[Test]
    #[DataProvider('symfonyRoleProvider')]
    public function asSymfonyRoleReturnsExpectedString(UserRole $role, string $expected): void
    {
        self::assertSame($expected, $role->asSymfonyRole());
    }

    /**
     * @return iterable<string, array{UserRole, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'admin' => [UserRole::Admin, 'user.role.admin'];
        yield 'manager' => [UserRole::Manager, 'user.role.manager'];
        yield 'employee' => [UserRole::Employee, 'user.role.employee'];
    }

    #[Test]
    #[DataProvider('labelProvider')]
    public function labelReturnsTranslationKey(UserRole $role, string $expected): void
    {
        self::assertSame($expected, $role->label());
    }
}
