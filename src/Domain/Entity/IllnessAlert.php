<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Repository\IllnessAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Idempotency record for the 6-week illness alarm.
 *
 * One row per (employee, periodStartedOn) — written when the
 * IllnessAlertCheckHandler dispatches the IllnessSixWeekAlert
 * notification. The handler queries this table before each potential
 * dispatch so a daily re-sweep on an unchanged ongoing illness doesn't
 * re-spam recipients.
 *
 * `daysCount` snapshots the run length at alert time. Subsequent sweeps
 * may keep extending the actual illness run, but the alert remains
 * tied to the period start — there is no "second 42-day milestone"
 * within the same continuous illness.
 */
#[ORM\Entity(repositoryClass: IllnessAlertRepository::class)]
#[ORM\Table(name: 'illness_alerts')]
#[ORM\UniqueConstraint(name: 'uniq_illness_alert_employee_period', columns: ['employee_id', 'period_started_on'])]
#[ORM\Index(name: 'idx_illness_alert_employee', columns: ['employee_id'])]
class IllnessAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'employee_id', nullable: false)]
        private Employee $employee,
        #[ORM\Column(name: 'period_started_on', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $periodStartedOn,
        #[ORM\Column(name: 'days_count', type: Types::INTEGER)]
        private int $daysCount,
        #[ORM\Column(name: 'alerted_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $alertedAt,
    ) {
        $this->periodStartedOn = $periodStartedOn->setTime(0, 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): Employee
    {
        return $this->employee;
    }

    public function getPeriodStartedOn(): \DateTimeImmutable
    {
        return $this->periodStartedOn;
    }

    public function getDaysCount(): int
    {
        return $this->daysCount;
    }

    public function getAlertedAt(): \DateTimeImmutable
    {
        return $this->alertedAt;
    }
}
