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

namespace App\Tests\Unit\Domain\Enum;

use App\Domain\Enum\Weekday;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Weekday::class)]
final class WeekdayTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Weekday}>
     */
    public static function provideDateToWeekday(): iterable
    {
        yield 'Monday' => ['2026-04-13', Weekday::Monday];
        yield 'Tuesday' => ['2026-04-14', Weekday::Tuesday];
        yield 'Wednesday' => ['2026-04-15', Weekday::Wednesday];
        yield 'Thursday' => ['2026-04-16', Weekday::Thursday];
        yield 'Friday' => ['2026-04-17', Weekday::Friday];
        yield 'Saturday' => ['2026-04-18', Weekday::Saturday];
        yield 'Sunday' => ['2026-04-19', Weekday::Sunday];
    }

    #[Test]
    #[DataProvider('provideDateToWeekday')]
    public function mapsEveryWeekdayCorrectly(string $isoDate, Weekday $expected): void
    {
        $date = new \DateTimeImmutable($isoDate);

        self::assertSame($expected, Weekday::fromDateTime($date));
    }
}
