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
use App\Domain\Entity\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Location::class)]
final class LocationTest extends TestCase
{
    private function acme(): Company
    {
        return new Company('Acme GmbH');
    }

    #[Test]
    public function normalizesCountryAndStateToUppercase(): void
    {
        $location = new Location($this->acme(), 'HQ', 'de', 'de-by', 'München');

        self::assertSame('DE', $location->getCountry());
        self::assertSame('DE-BY', $location->getFederalState());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBlankFields(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    #[Test]
    #[DataProvider('provideBlankFields')]
    public function rejectsBlankName(string $blank): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Location.name');

        new Location($this->acme(), $blank, 'DE', 'DE-BY', 'München');
    }

    #[Test]
    public function renameReplacesName(): void
    {
        $location = new Location($this->acme(), 'HQ', 'DE', 'DE-BY', 'München');

        $location->rename('Hauptsitz');

        self::assertSame('Hauptsitz', $location->getName());
    }

    #[Test]
    public function moveToUpdatesAddressComponents(): void
    {
        $location = new Location($this->acme(), 'HQ', 'DE', 'DE-BY', 'München');

        $location->moveTo('at', 'at-9', 'Wien');

        self::assertSame('AT', $location->getCountry());
        self::assertSame('AT-9', $location->getFederalState());
        self::assertSame('Wien', $location->getCity());
    }
}
