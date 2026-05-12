<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\TwoFactor;

use App\Application\TwoFactor\BackupCodeGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackupCodeGenerator::class)]
final class BackupCodeGeneratorTest extends TestCase
{
    private BackupCodeGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BackupCodeGenerator();
    }

    #[Test]
    public function generatesTheRequestedNumberOfCodes(): void
    {
        $bundle = $this->generator->generate(10);

        self::assertCount(10, $bundle->plaintextCodes);
        self::assertCount(10, $bundle->hashedCodes);
    }

    #[Test]
    public function plaintextCodesAreUnique(): void
    {
        $bundle = $this->generator->generate(20);

        self::assertCount(20, array_unique($bundle->plaintextCodes));
    }

    #[Test]
    public function plaintextCodesAreLowercaseAlphanumeric(): void
    {
        $bundle = $this->generator->generate(5);

        foreach ($bundle->plaintextCodes as $code) {
            self::assertMatchesRegularExpression('/^[a-z0-9]{8,}$/', $code);
        }
    }

    #[Test]
    public function hashedCodesMatchPlaintextOrderAndUseSha256(): void
    {
        $bundle = $this->generator->generate(3);

        foreach ($bundle->plaintextCodes as $index => $plain) {
            self::assertSame(hash('sha256', $plain), $bundle->hashedCodes[$index]);
        }
    }

    #[Test]
    public function rejectsNonPositiveCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->generate(0);
    }
}
