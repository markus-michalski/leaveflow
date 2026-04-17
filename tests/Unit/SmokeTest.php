<?php

declare(strict_types=1);

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
