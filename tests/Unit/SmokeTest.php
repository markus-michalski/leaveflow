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

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversNothing]
final class SmokeTest extends TestCase
{
    #[Test]
    public function mockClockProducesDeterministicTime(): void
    {
        $clock = new MockClock('2026-04-17 10:00:00', 'Europe/Berlin');

        self::assertSame('2026-04-17 10:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function timezoneIsBerlin(): void
    {
        self::assertSame('Europe/Berlin', date_default_timezone_get());
    }
}
