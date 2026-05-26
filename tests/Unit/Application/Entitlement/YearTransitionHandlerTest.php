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

namespace App\Tests\Unit\Application\Entitlement;

use App\Application\Entitlement\YearTransitionEntry;
use App\Application\Entitlement\YearTransitionHandler;
use App\Application\Entitlement\YearTransitionMessage;
use App\Application\Entitlement\YearTransitionServiceInterface;
use App\Application\Entitlement\YearTransitionStatus;
use App\Application\Scheduler\ScheduledJobConfigManagerInterface;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\Location;
use App\Domain\Enum\ScheduledJobRunStatus;
use App\Domain\ValueObject\WorkSchedule;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Unit tests for {@see YearTransitionHandler}.
 *
 * The handler is fired by the Symfony Scheduler (annually on Jan 1st) via
 * the {@see YearTransitionMessage} marker. It resolves "last year" from the
 * clock and delegates the actual booking work to YearTransitionService —
 * which is exercised in its own test suite. These tests pin the handler's
 * thin contract: clock-to-source-year mapping and per-status logging.
 */
#[CoversClass(YearTransitionHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class YearTransitionHandlerTest extends TestCase
{
    private YearTransitionServiceInterface&MockObject $service;
    private ScheduledJobConfigManagerInterface&MockObject $jobConfig;
    private LoggerInterface&MockObject $logger;
    private Employee $jane;

    protected function setUp(): void
    {
        $this->service = $this->createMock(YearTransitionServiceInterface::class);
        $this->jobConfig = $this->createMock(ScheduledJobConfigManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        // Default: toggle enabled — most tests exercise the happy path.
        $this->jobConfig->method('isEnabled')->willReturn(true);

        $acme = new Company('Acme GmbH');
        $hq = new Location($acme, 'HQ', 'DE', 'DE-BY', 'München');
        $this->jane = new Employee(
            $acme,
            'Jane Doe',
            'EMP-0001',
            $hq,
            WorkSchedule::standardFullTime(),
            new \DateTimeImmutable('2020-01-01'),
        );
    }

    #[Test]
    public function dispatchesTransitionForLastYear(): void
    {
        $clock = new MockClock('2027-01-01 01:00:00');

        $this->service->expects(self::once())
            ->method('transition')
            ->with(2026)  // current year - 1
            ->willReturn([]);

        $this->handler($clock)->__invoke(new YearTransitionMessage());
    }

    #[Test]
    public function logsPerStatusCounts(): void
    {
        $clock = new MockClock('2027-01-01 01:00:00');
        $this->service->method('transition')->willReturn([
            new YearTransitionEntry($this->jane, 40.0, YearTransitionStatus::Created),
            new YearTransitionEntry($this->jane, 0.0, YearTransitionStatus::SkippedAlreadyExists),
            new YearTransitionEntry($this->jane, 0.0, YearTransitionStatus::SkippedAlreadyExists),
            new YearTransitionEntry($this->jane, 0.0, YearTransitionStatus::SkippedEmptyBalance),
        ]);

        $loggedContexts = [];
        $this->logger->method('info')
            ->willReturnCallback(static function (string $message, array $context) use (&$loggedContexts): void {
                $loggedContexts[] = ['message' => $message, 'context' => $context];
            });

        $this->handler($clock)->__invoke(new YearTransitionMessage());

        // Two info entries: starting + finished.
        self::assertCount(2, $loggedContexts);
        self::assertSame(2026, $loggedContexts[1]['context']['sourceYear']);
        self::assertSame(1, $loggedContexts[1]['context']['created']);
        self::assertSame(2, $loggedContexts[1]['context']['skippedAlreadyExists']);
        self::assertSame(1, $loggedContexts[1]['context']['skippedEmptyBalance']);
    }

    #[Test]
    public function handlesEmptyReportWithoutCrashing(): void
    {
        // Fresh tenant: no Regular entitlements yet for the source year.
        $clock = new MockClock('2027-01-01 01:00:00');
        $this->service->method('transition')->willReturn([]);

        $loggedContexts = [];
        $this->logger->method('info')
            ->willReturnCallback(static function (string $message, array $context) use (&$loggedContexts): void {
                $loggedContexts[] = $context;
            });

        $this->handler($clock)->__invoke(new YearTransitionMessage());

        self::assertSame(0, $loggedContexts[1]['created']);
        self::assertSame(0, $loggedContexts[1]['skippedAlreadyExists']);
        self::assertSame(0, $loggedContexts[1]['skippedEmptyBalance']);
    }

    #[Test]
    public function skipsWorkWhenToggleIsDisabled(): void
    {
        $clock = new MockClock('2027-01-01 01:00:00');

        // Override the default-enabled stub.
        $this->jobConfig = $this->createMock(ScheduledJobConfigManagerInterface::class);
        $this->jobConfig->method('isEnabled')->willReturn(false);

        $this->service->expects(self::never())->method('transition');
        $this->jobConfig->expects(self::once())
            ->method('markRun')
            ->with(YearTransitionHandler::JOB_NAME, ScheduledJobRunStatus::Skipped);

        $this->handler($clock)->__invoke(new YearTransitionMessage());
    }

    #[Test]
    public function recordsSuccessAfterCompletedRun(): void
    {
        $clock = new MockClock('2027-01-01 01:00:00');
        $this->service->method('transition')->willReturn([]);

        $this->jobConfig->expects(self::once())
            ->method('markRun')
            ->with(YearTransitionHandler::JOB_NAME, ScheduledJobRunStatus::Success);

        $this->handler($clock)->__invoke(new YearTransitionMessage());
    }

    #[Test]
    public function recordsFailureAndRethrowsWhenServiceThrows(): void
    {
        $clock = new MockClock('2027-01-01 01:00:00');
        $this->service->method('transition')->willThrowException(new \RuntimeException('repo dead'));

        $this->jobConfig->expects(self::once())
            ->method('markRun')
            ->with(YearTransitionHandler::JOB_NAME, ScheduledJobRunStatus::Failure, 'repo dead');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('repo dead');

        $this->handler($clock)->__invoke(new YearTransitionMessage());
    }

    private function handler(MockClock $clock): YearTransitionHandler
    {
        return new YearTransitionHandler(
            $this->service,
            $this->jobConfig,
            $clock,
            $this->logger,
        );
    }
}
