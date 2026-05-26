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
use App\Domain\Enum\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTwoFactorTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User(new Company('Acme'), 'alice@example.test', UserRole::Employee);
    }

    #[Test]
    public function freshUserHasTotpDisabled(): void
    {
        self::assertFalse($this->user->isTotpEnabled());
        self::assertFalse($this->user->isTotpAuthenticationEnabled());
        self::assertNull($this->user->getTotpSecret());
        self::assertSame([], $this->user->getBackupCodes());
    }

    #[Test]
    public function enableTotpPersistsSecretAndCodes(): void
    {
        $this->user->enableTotp('JBSWY3DPEHPK3PXP', ['hash1', 'hash2', 'hash3']);

        self::assertTrue($this->user->isTotpEnabled());
        self::assertTrue($this->user->isTotpAuthenticationEnabled());
        self::assertSame('JBSWY3DPEHPK3PXP', $this->user->getTotpSecret());
        self::assertCount(3, $this->user->getBackupCodes());
        self::assertSame('alice@example.test', $this->user->getTotpAuthenticationUsername());
    }

    #[Test]
    public function disableTotpClearsEverything(): void
    {
        $this->user->enableTotp('JBSWY3DPEHPK3PXP', ['hash1', 'hash2']);
        $this->user->disableTotp();

        self::assertFalse($this->user->isTotpEnabled());
        self::assertNull($this->user->getTotpSecret());
        self::assertSame([], $this->user->getBackupCodes());
    }

    #[Test]
    public function isBackupCodeMatchesHashOfPlaintext(): void
    {
        $plaintext = 'rescue42';
        $this->user->enableTotp('SECRET', [hash('sha256', $plaintext)]);

        self::assertTrue($this->user->isBackupCode($plaintext));
        self::assertFalse($this->user->isBackupCode('wrong'));
    }

    #[Test]
    public function isBackupCodeIgnoresCaseAndWhitespace(): void
    {
        $this->user->enableTotp('SECRET', [hash('sha256', 'rescue42')]);

        self::assertTrue($this->user->isBackupCode('RESCUE42'));
        self::assertTrue($this->user->isBackupCode(' rescue42 '));
    }

    #[Test]
    public function invalidateBackupCodeRemovesOnlyThatCode(): void
    {
        $this->user->enableTotp('SECRET', [
            hash('sha256', 'one'),
            hash('sha256', 'two'),
            hash('sha256', 'three'),
        ]);

        $this->user->invalidateBackupCode('two');

        self::assertCount(2, $this->user->getBackupCodes());
        self::assertFalse($this->user->isBackupCode('two'));
        self::assertTrue($this->user->isBackupCode('one'));
        self::assertTrue($this->user->isBackupCode('three'));
    }

    #[Test]
    public function totpAuthenticationDisabledWhenOnlyFlagWithoutSecret(): void
    {
        // Defensive: setTotpSecret(null) + flag=true shouldn't happen
        // but the interface guard is the safety net.
        $this->user->enableTotp('SECRET', []);
        $this->user->setTotpSecret(null);

        self::assertFalse($this->user->isTotpAuthenticationEnabled());
    }

    #[Test]
    public function getTotpAuthenticationConfigurationReturnsNullWithoutSecret(): void
    {
        self::assertNull($this->user->getTotpAuthenticationConfiguration());
    }

    #[Test]
    public function getTotpAuthenticationConfigurationReturnsRFC6238Defaults(): void
    {
        $this->user->enableTotp('JBSWY3DPEHPK3PXP', []);
        $config = $this->user->getTotpAuthenticationConfiguration();

        self::assertNotNull($config);
        self::assertSame('JBSWY3DPEHPK3PXP', $config->getSecret());
        self::assertSame(30, $config->getPeriod());
        self::assertSame(6, $config->getDigits());
    }
}
