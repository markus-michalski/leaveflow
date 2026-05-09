<?php

declare(strict_types=1);

namespace App\Application\Scheduler;

use App\Domain\Entity\ScheduledJobConfig;
use App\Domain\Enum\ScheduledJobRunStatus;
use App\Domain\Repository\ScheduledJobConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Handler-facing API for the scheduled-job toggle layer (#35 phase 2).
 *
 * Each scheduled handler asks the manager whether its toggle is enabled
 * before doing work, and reports the outcome back so admins (Phase 3 UI,
 * console command for now) see "last ran 4h ago, success" without
 * grepping logs.
 *
 * Auto-provisions missing rows on first contact: the default for an
 * unknown job name is "enabled" so a new handler shipped without a
 * pre-seeded row keeps running. Handlers don't have to know whether
 * their row exists yet.
 *
 * Persists immediately in markRun — separate UnitOfWork so a handler's
 * own flush behavior doesn't bleed into our bookkeeping.
 */
final readonly class ScheduledJobConfigManager implements ScheduledJobConfigManagerInterface
{
    public function __construct(
        private ScheduledJobConfigRepository $repository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function isEnabled(string $jobName): bool
    {
        $config = $this->repository->findOneByName($jobName);

        return $config?->isEnabled() ?? true;
    }

    public function markRun(string $jobName, ScheduledJobRunStatus $status, ?string $error = null): void
    {
        $config = $this->resolveOrCreate($jobName);
        $config->recordRun($this->clock->now(), $status, $error);
        $this->entityManager->flush();
    }

    public function setEnabled(string $jobName, bool $enabled): void
    {
        $config = $this->resolveOrCreate($jobName);
        $enabled ? $config->enable() : $config->disable();
        $this->entityManager->flush();
    }

    private function resolveOrCreate(string $jobName): ScheduledJobConfig
    {
        $config = $this->repository->findOneByName($jobName);
        if (null === $config) {
            $config = new ScheduledJobConfig($jobName);
            $this->entityManager->persist($config);
        }

        return $config;
    }
}
