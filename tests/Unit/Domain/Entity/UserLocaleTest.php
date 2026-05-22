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
final class UserLocaleTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company('Acme GmbH');
    }

    #[Test]
    public function localeIsNullByDefault(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);

        self::assertNull($user->getLocale());
    }

    #[Test]
    public function localeCanBeSetToGerman(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->setLocale('de');

        self::assertSame('de', $user->getLocale());
    }

    #[Test]
    public function localeCanBeSetToEnglish(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->setLocale('en');

        self::assertSame('en', $user->getLocale());
    }

    #[Test]
    public function localeCanBeUnset(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->setLocale('de');
        $user->setLocale(null);

        self::assertNull($user->getLocale());
    }

    #[Test]
    public function anonymizeClearsLocale(): void
    {
        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->setLocale('de');

        $user->anonymize('anonymized-1@leaveflow.local');

        self::assertNull($user->getLocale());
    }

    #[Test]
    public function unsupportedLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = new User($this->company, 'jane@example.com', UserRole::Employee);
        $user->setLocale('fr');
    }

    #[Test]
    public function allowedLocalesConstantContainsDeAndEn(): void
    {
        self::assertContains('de', User::ALLOWED_LOCALES);
        self::assertContains('en', User::ALLOWED_LOCALES);
    }
}
