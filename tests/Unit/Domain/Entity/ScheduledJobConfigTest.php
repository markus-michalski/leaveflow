<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Entity;

use App\Domain\Entity\ScheduledJobConfig;
use App\Domain\Enum\ScheduledJobRunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScheduledJobConfig::class)]
final class ScheduledJobConfigTest extends TestCase
{
    #[Test]
    public function defaultsToEnabledWithNoLastRun(): void
    {
        $config = new ScheduledJobConfig('year-transition');

        self::assertSame('year-transition', $config->getName());
        self::assertTrue($config->isEnabled());
        self::assertNull($config->getLastRunAt());
        self::assertNull($config->getLastRunStatus());
        self::assertNull($config->getLastError());
    }

    #[Test]
    public function rejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        new ScheduledJobConfig('   ');
    }

    #[Test]
    public function enableAndDisableFlipTheToggle(): void
    {
        $config = new ScheduledJobConfig('year-transition');

        $config->disable();
        self::assertFalse($config->isEnabled());

        $config->enable();
        self::assertTrue($config->isEnabled());
    }

    #[Test]
    public function recordRunStoresTimestampAndStatus(): void
    {
        $config = new ScheduledJobConfig('year-transition');
        $now = new \DateTimeImmutable('2027-01-01 01:00:00');

        $config->recordRun($now, ScheduledJobRunStatus::Success);

        self::assertSame($now, $config->getLastRunAt());
        self::assertSame(ScheduledJobRunStatus::Success, $config->getLastRunStatus());
        self::assertNull($config->getLastError());
    }

    #[Test]
    public function recordRunStoresErrorOnlyForFailureStatus(): void
    {
        $config = new ScheduledJobConfig('year-transition');
        $now = new \DateTimeImmutable('2027-01-01 01:00:00');

        $config->recordRun($now, ScheduledJobRunStatus::Failure, 'database down');

        self::assertSame('database down', $config->getLastError());
    }

    #[Test]
    public function recordRunClearsStaleErrorOnRecovery(): void
    {
        // First run failed — error message stored.
        $config = new ScheduledJobConfig('year-transition');
        $config->recordRun(new \DateTimeImmutable('2027-01-01 01:00:00'), ScheduledJobRunStatus::Failure, 'database down');
        self::assertSame('database down', $config->getLastError());

        // Second run succeeded — error message cleared so admins don't see
        // a phantom "still broken" indicator after the system recovered.
        $config->recordRun(new \DateTimeImmutable('2027-01-02 01:00:00'), ScheduledJobRunStatus::Success);
        self::assertNull($config->getLastError());
    }

    #[Test]
    public function recordRunClearsStaleErrorEvenOnSkipped(): void
    {
        // Edge case: failure followed by an intentional disable (skipped).
        // Same logic as success — Skipped means "no current problem," only
        // Failure should leave a message.
        $config = new ScheduledJobConfig('year-transition');
        $config->recordRun(new \DateTimeImmutable('2027-01-01 01:00:00'), ScheduledJobRunStatus::Failure, 'database down');

        $config->recordRun(new \DateTimeImmutable('2027-01-02 01:00:00'), ScheduledJobRunStatus::Skipped);

        self::assertNull($config->getLastError());
    }
}
