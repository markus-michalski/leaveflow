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

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbsenceType::class)]
final class AbsenceTypeTest extends TestCase
{
    private Company $acme;

    protected function setUp(): void
    {
        $this->acme = new Company('Acme GmbH');
    }

    #[Test]
    public function storesCoreFieldsAndNormalizesWhitespace(): void
    {
        $type = new AbsenceType(
            $this->acme,
            '  Urlaub  ',
            deductsFromLeave: true,
            requiresApproval: true,
            color: '#3B82F6',
        );

        self::assertSame($this->acme, $type->getCompany());
        self::assertSame('Urlaub', $type->getName());
        self::assertTrue($type->deductsFromLeave());
        self::assertTrue($type->requiresApproval());
        self::assertSame('#3B82F6', $type->getColor());
        self::assertTrue($type->isActive());
    }

    #[Test]
    public function rejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        new AbsenceType($this->acme, '   ', true, true, '#000000');
    }

    #[Test]
    #[DataProvider('invalidColorProvider')]
    public function rejectsInvalidHexColor(string $color): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('color');

        new AbsenceType($this->acme, 'Urlaub', true, true, $color);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidColorProvider(): iterable
    {
        yield 'missing hash' => ['3B82F6'];
        yield 'too short' => ['#3B'];
        yield 'four digits' => ['#3B82'];
        yield 'too long' => ['#3B82F6F6'];
        yield 'invalid char' => ['#3B82FG'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('validColorProvider')]
    public function acceptsValidHexColor(string $color, string $expected): void
    {
        $type = new AbsenceType($this->acme, 'Urlaub', true, true, $color);

        self::assertSame($expected, $type->getColor());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function validColorProvider(): iterable
    {
        yield 'uppercase 6-digit' => ['#3B82F6', '#3B82F6'];
        yield 'lowercase normalized' => ['#3b82f6', '#3B82F6'];
        yield '3-digit short form' => ['#FFF', '#FFF'];
    }

    #[Test]
    public function defaultsToActive(): void
    {
        $type = new AbsenceType($this->acme, 'Urlaub', true, true, '#3B82F6');

        self::assertTrue($type->isActive());
    }

    #[Test]
    public function deactivateTogglesActiveFlag(): void
    {
        $type = new AbsenceType($this->acme, 'Urlaub', true, true, '#3B82F6');

        $type->deactivate();

        self::assertFalse($type->isActive());
    }

    #[Test]
    public function activateTogglesActiveFlag(): void
    {
        $type = new AbsenceType($this->acme, 'Urlaub', true, true, '#3B82F6');
        $type->deactivate();

        $type->activate();

        self::assertTrue($type->isActive());
    }

    #[Test]
    public function updateChangesAllMutableFields(): void
    {
        $type = new AbsenceType($this->acme, 'Urlaub', true, true, '#3B82F6');

        $type->update(
            name: '  Jahresurlaub  ',
            deductsFromLeave: false,
            requiresApproval: false,
            color: '#ef4444',
        );

        self::assertSame('Jahresurlaub', $type->getName());
        self::assertFalse($type->deductsFromLeave());
        self::assertFalse($type->requiresApproval());
        self::assertSame('#EF4444', $type->getColor());
    }

    #[Test]
    public function updateRejectsBlankName(): void
    {
        $type = new AbsenceType($this->acme, 'Urlaub', true, true, '#3B82F6');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        $type->update('  ', true, true, '#000000');
    }

    #[Test]
    public function updateRejectsInvalidColor(): void
    {
        $type = new AbsenceType($this->acme, 'Urlaub', true, true, '#3B82F6');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('color');

        $type->update('Urlaub', true, true, 'not-hex');
    }
}
