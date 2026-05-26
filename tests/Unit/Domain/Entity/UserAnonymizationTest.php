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

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\AuthSource;
use App\Domain\Enum\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserAnonymizationTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company('Acme GmbH');
    }

    #[Test]
    public function anonymizeSetsPlaceholderEmail(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);

        $user->anonymize('anonymized-99@leaveflow.local');

        self::assertSame('anonymized-99@leaveflow.local', $user->getEmail());
        self::assertSame('anonymized-99@leaveflow.local', $user->getUserIdentifier());
    }

    #[Test]
    public function anonymizeClearsPassword(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->setHashedPassword('$2y$13$hashvalue');

        $user->anonymize('anonymized-99@leaveflow.local');

        self::assertNull($user->getPassword());
    }

    #[Test]
    public function anonymizeClearsTotpCredentials(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->enableTotp('BASE32SECRET', [hash('sha256', 'backup1')]);
        self::assertTrue($user->isTotpEnabled());

        $user->anonymize('anonymized-99@leaveflow.local');

        self::assertNull($user->getTotpSecret());
        self::assertFalse($user->isTotpEnabled());
        self::assertSame([], $user->getBackupCodes());
    }

    #[Test]
    public function anonymizeClearsIcalToken(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->ensureIcalToken();
        self::assertNotNull($user->getIcalToken());

        $user->anonymize('anonymized-99@leaveflow.local');

        self::assertNull($user->getIcalToken());
    }

    #[Test]
    public function anonymizeClearsExternalIdAndResetsAuthSource(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->bindToIdp(AuthSource::Google, 'google-sub-999');

        $user->anonymize('anonymized-99@leaveflow.local');

        self::assertNull($user->getExternalId());
        self::assertSame(AuthSource::Local, $user->getAuthSource());
    }

    #[Test]
    public function anonymizeClearsSlackUserId(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->setSlackUserId('U0123456789');

        $user->anonymize('anonymized-99@leaveflow.local');

        self::assertNull($user->getSlackUserId());
    }

    #[Test]
    public function anonymizeDeactivatesUser(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        self::assertTrue($user->isActive());

        $user->anonymize('anonymized-99@leaveflow.local');

        self::assertFalse($user->isActive());
    }
}
