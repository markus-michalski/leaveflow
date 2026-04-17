<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\UserRole;
use App\Infrastructure\Security\UserChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(UserChecker::class)]
final class UserCheckerTest extends TestCase
{
    private function makeUser(bool $active): User
    {
        $company = new Company('Acme GmbH');
        $user = new User($company, 'jane@example.com', UserRole::Employee);

        if (!$active) {
            $user->deactivate();
        }

        return $user;
    }

    #[Test]
    public function allowsActiveUserOnPreAuth(): void
    {
        $checker = new UserChecker();
        $user = $this->makeUser(active: true);

        $checker->checkPreAuth($user);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function blocksInactiveUserOnPreAuth(): void
    {
        $this->expectException(DisabledException::class);

        $checker = new UserChecker();
        $user = $this->makeUser(active: false);

        $checker->checkPreAuth($user);
    }

    #[Test]
    public function ignoresUnrelatedUserImplementations(): void
    {
        $checker = new UserChecker();
        $checker->checkPreAuth(new InMemoryUser('someone', null));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function postAuthIsNoop(): void
    {
        $checker = new UserChecker();
        $checker->checkPostAuth($this->makeUser(active: true));

        $this->expectNotToPerformAssertions();
    }
}
