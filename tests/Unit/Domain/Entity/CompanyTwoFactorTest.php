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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Company::class)]
final class CompanyTwoFactorTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company('Acme GmbH');
    }

    #[Test]
    public function freshCompanyDoesNotRequireTwoFactor(): void
    {
        self::assertFalse($this->company->requiresTwoFactor());
        self::assertNull($this->company->getTwoFactorEnforcedFrom());
        self::assertFalse($this->company->isTwoFactorEnforced(new \DateTimeImmutable('2026-05-12')));
    }

    #[Test]
    public function enableSetsFlagAndDeadline(): void
    {
        $this->company->enableTwoFactorRequirement(
            new \DateTimeImmutable('2026-06-11'),
            new \DateTimeImmutable('2026-05-12'),
        );

        self::assertTrue($this->company->requiresTwoFactor());
        self::assertSame('2026-06-11', $this->company->getTwoFactorEnforcedFrom()?->format('Y-m-d'));
    }

    #[Test]
    public function rejectsPastDeadline(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->company->enableTwoFactorRequirement(
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-05-12'),
        );
    }

    #[Test]
    public function deadlineEqualToTodayIsAccepted(): void
    {
        $today = new \DateTimeImmutable('2026-05-12');
        $this->company->enableTwoFactorRequirement($today, $today);

        self::assertTrue($this->company->requiresTwoFactor());
        // Equal day means already enforced.
        self::assertTrue($this->company->isTwoFactorEnforced($today));
    }

    #[Test]
    public function isEnforcedFlipsTrueOnceDeadlineReached(): void
    {
        $this->company->enableTwoFactorRequirement(
            new \DateTimeImmutable('2026-06-11'),
            new \DateTimeImmutable('2026-05-12'),
        );

        self::assertFalse($this->company->isTwoFactorEnforced(new \DateTimeImmutable('2026-06-10')));
        self::assertTrue($this->company->isTwoFactorEnforced(new \DateTimeImmutable('2026-06-11')));
        self::assertTrue($this->company->isTwoFactorEnforced(new \DateTimeImmutable('2026-07-01')));
    }

    #[Test]
    public function disableClearsBothFields(): void
    {
        $this->company->enableTwoFactorRequirement(
            new \DateTimeImmutable('2026-06-11'),
            new \DateTimeImmutable('2026-05-12'),
        );
        $this->company->disableTwoFactorRequirement();

        self::assertFalse($this->company->requiresTwoFactor());
        self::assertNull($this->company->getTwoFactorEnforcedFrom());
        self::assertFalse($this->company->isTwoFactorEnforced(new \DateTimeImmutable('2026-07-01')));
    }
}
