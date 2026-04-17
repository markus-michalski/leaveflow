<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\Company;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Company::class)]
final class CompanyTest extends TestCase
{
    #[Test]
    public function storesNameAndRetentionPeriod(): void
    {
        $company = new Company('Acme GmbH', 24);

        self::assertSame('Acme GmbH', $company->getName());
        self::assertSame(24, $company->getRetentionPeriodMonths());
    }

    #[Test]
    public function retentionPeriodDefaultsTo36Months(): void
    {
        $company = new Company('Acme GmbH');

        self::assertSame(36, $company->getRetentionPeriodMonths());
    }

    #[Test]
    public function nameCanBeRenamed(): void
    {
        $company = new Company('Old Name');
        $company->rename('New Name');

        self::assertSame('New Name', $company->getName());
    }

    #[Test]
    public function idIsNullUntilPersisted(): void
    {
        $company = new Company('Acme GmbH');

        self::assertNull($company->getId());
    }
}
