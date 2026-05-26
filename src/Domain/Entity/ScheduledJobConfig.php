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

namespace App\Domain\Entity;

use App\Domain\Enum\ScheduledJobRunStatus;
use App\Domain\Repository\ScheduledJobConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Toggle + run-status row per scheduled job (#35 phase 2).
 *
 * Symfony Scheduler defines its tasks statically via #[AsSchedule]. To let
 * admins disable individual jobs at runtime without redeploying we keep a
 * tiny bookkeeping row per job name. Each handler asks the manager whether
 * its toggle is enabled before doing any work, and reports the outcome
 * back so admins can see at a glance "ran 4h ago, success" or "last run
 * threw NoEntitlementForYear".
 *
 * Names are stable identifiers (kebab-case, e.g. `year-transition`) — they
 * survive across releases and are referenced by string from the handlers.
 *
 * Invariants:
 * - name is non-empty and unique
 * - lastRunAt and lastRunStatus are paired: both null on a never-run row,
 *   both populated after the first run
 */
#[ORM\Entity(repositoryClass: ScheduledJobConfigRepository::class)]
#[ORM\Table(name: 'scheduled_job_configs')]
class ScheduledJobConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'last_run_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(name: 'last_run_status', length: 20, enumType: ScheduledJobRunStatus::class, nullable: true)]
    private ?ScheduledJobRunStatus $lastRunStatus = null;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public function __construct(
        #[ORM\Column(length: 80, unique: true)]
        private string $name,
        #[ORM\Column]
        private bool $enabled = true,
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('ScheduledJobConfig.name must not be empty.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastRunStatus(): ?ScheduledJobRunStatus
    {
        return $this->lastRunStatus;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function recordRun(\DateTimeImmutable $at, ScheduledJobRunStatus $status, ?string $error = null): void
    {
        $this->lastRunAt = $at;
        $this->lastRunStatus = $status;
        // Failure carries the message; success/skipped clear it so stale
        // failures don't linger after a recovery run.
        $this->lastError = ScheduledJobRunStatus::Failure === $status ? $error : null;
    }
}
