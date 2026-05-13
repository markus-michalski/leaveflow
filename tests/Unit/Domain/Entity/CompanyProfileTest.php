<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\Company;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Company::class)]
final class CompanyProfileTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company('Acme GmbH');
    }

    #[Test]
    public function addressIsNullableAndTrimmed(): void
    {
        $this->company->setAddress('  Hauptstr. 1, 80331 München  ');
        self::assertSame('Hauptstr. 1, 80331 München', $this->company->getAddress());

        $this->company->setAddress('   ');
        self::assertNull($this->company->getAddress());

        $this->company->setAddress(null);
        self::assertNull($this->company->getAddress());
    }

    #[Test]
    public function primaryColorNormalizesToUppercaseHex(): void
    {
        $this->company->setPrimaryColor('#3b82f6');
        self::assertSame('#3B82F6', $this->company->getPrimaryColor());

        $this->company->setPrimaryColor('#abc');
        self::assertSame('#ABC', $this->company->getPrimaryColor());
    }

    #[Test]
    public function primaryColorRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->company->setPrimaryColor('blue');
    }

    #[Test]
    public function primaryColorAcceptsNullAndBlank(): void
    {
        $this->company->setPrimaryColor('#FFFFFF');
        $this->company->setPrimaryColor(null);
        self::assertNull($this->company->getPrimaryColor());

        $this->company->setPrimaryColor('#FFFFFF');
        $this->company->setPrimaryColor('  ');
        self::assertNull($this->company->getPrimaryColor());
    }

    #[Test]
    public function retentionPeriodMonthsValidatesPositive(): void
    {
        $this->company->setRetentionPeriodMonths(48);
        self::assertSame(48, $this->company->getRetentionPeriodMonths());

        $this->expectException(\InvalidArgumentException::class);
        $this->company->setRetentionPeriodMonths(0);
    }

    #[Test]
    public function taxIdAndCommercialRegisterAreTrimmed(): void
    {
        $this->company->setTaxId('  DE 123 456 789 ');
        $this->company->setCommercialRegister(' HRB 12345 ');

        self::assertSame('DE 123 456 789', $this->company->getTaxId());
        self::assertSame('HRB 12345', $this->company->getCommercialRegister());
    }
}
