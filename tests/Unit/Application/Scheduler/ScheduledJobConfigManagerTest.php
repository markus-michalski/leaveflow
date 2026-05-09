<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scheduler;

use App\Application\Scheduler\ScheduledJobConfigManager;
use App\Domain\Entity\ScheduledJobConfig;
use App\Domain\Enum\ScheduledJobRunStatus;
use App\Domain\Repository\ScheduledJobConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ScheduledJobConfigManager::class)]
#[AllowMockObjectsWithoutExpectations]
final class ScheduledJobConfigManagerTest extends TestCase
{
    private ScheduledJobConfigRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private MockClock $clock;
    private ScheduledJobConfigManager $manager;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ScheduledJobConfigRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->clock = new MockClock('2027-01-01 01:00:00');
        $this->manager = new ScheduledJobConfigManager(
            $this->repository,
            $this->entityManager,
            $this->clock,
        );
    }

    #[Test]
    public function isEnabledDefaultsToTrueForUnknownJob(): void
    {
        // Unknown job names default to enabled — preserves existing behavior
        // for handlers shipped before their config row is seeded.
        $this->repository->method('findOneByName')->willReturn(null);

        self::assertTrue($this->manager->isEnabled('year-transition'));
    }

    #[Test]
    public function isEnabledReturnsConfiguredValue(): void
    {
        $config = new ScheduledJobConfig('year-transition', enabled: false);
        $this->repository->method('findOneByName')->willReturn($config);

        self::assertFalse($this->manager->isEnabled('year-transition'));
    }

    #[Test]
    public function markRunCreatesRowOnFirstContact(): void
    {
        $this->repository->method('findOneByName')->willReturn(null);

        $persisted = [];
        $this->entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->markRun('year-transition', ScheduledJobRunStatus::Success);

        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduledJobConfig::class, $persisted[0]);
        self::assertSame('year-transition', $persisted[0]->getName());
        self::assertSame(ScheduledJobRunStatus::Success, $persisted[0]->getLastRunStatus());
    }

    #[Test]
    public function markRunUpdatesExistingRowAndFlushes(): void
    {
        $config = new ScheduledJobConfig('year-transition');
        $this->repository->method('findOneByName')->willReturn($config);
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->markRun('year-transition', ScheduledJobRunStatus::Failure, 'oops');

        self::assertSame(ScheduledJobRunStatus::Failure, $config->getLastRunStatus());
        self::assertSame('oops', $config->getLastError());
        // Compare via formatted string to dodge tz mismatch between
        // MockClock's default UTC and the test machine's tz.
        self::assertSame('2027-01-01 01:00:00', $config->getLastRunAt()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function setEnabledTogglesAndFlushes(): void
    {
        $config = new ScheduledJobConfig('year-transition');
        $this->repository->method('findOneByName')->willReturn($config);

        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->setEnabled('year-transition', false);

        self::assertFalse($config->isEnabled());
    }

    #[Test]
    public function setEnabledOnUnknownJobAutoCreatesRow(): void
    {
        $this->repository->method('findOneByName')->willReturn(null);

        $persisted = [];
        $this->entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->setEnabled('year-transition', false);

        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduledJobConfig::class, $persisted[0]);
        self::assertFalse($persisted[0]->isEnabled());
    }
}
