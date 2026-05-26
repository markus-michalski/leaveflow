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

use App\Domain\Enum\ExclusionReason;
use App\Domain\Enum\LeaveDayStatus;
use App\Domain\Repository\LeaveRequestDayRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persisted snapshot of one calendar day inside a LeaveRequest.
 *
 * Mirrors the shape of the LeaveDay value object but lives in its own table so
 * admin/audit views can query day-level detail without recomputing. Populated
 * exclusively via LeaveRequest::applyBreakdown — callers should not construct
 * these directly.
 *
 * Invariants mirror LeaveDay's: Excluded requires a reason and hours = 0.0,
 * non-excluded must not carry a reason, hours must not be negative.
 */
#[ORM\Entity(repositoryClass: LeaveRequestDayRepository::class)]
#[ORM\Table(name: 'leave_request_days')]
#[ORM\UniqueConstraint(name: 'uniq_leave_request_day', columns: ['leave_request_id', 'date'])]
class LeaveRequestDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'days')]
        #[ORM\JoinColumn(name: 'leave_request_id', nullable: false)]
        private LeaveRequest $leaveRequest,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column(type: Types::FLOAT)]
        private float $hours,
        #[ORM\Column(length: 20, enumType: LeaveDayStatus::class)]
        private LeaveDayStatus $status,
        #[ORM\Column(length: 30, enumType: ExclusionReason::class, nullable: true)]
        private ?ExclusionReason $reason = null,
    ) {
        $this->date = $date->setTime(0, 0, 0, 0);
        $this->assertInvariants($hours, $status, $reason);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLeaveRequest(): LeaveRequest
    {
        return $this->leaveRequest;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getHours(): float
    {
        return $this->hours;
    }

    public function getStatus(): LeaveDayStatus
    {
        return $this->status;
    }

    public function getReason(): ?ExclusionReason
    {
        return $this->reason;
    }

    private function assertInvariants(float $hours, LeaveDayStatus $status, ?ExclusionReason $reason): void
    {
        if ($hours < 0.0) {
            throw new \InvalidArgumentException('LeaveRequestDay.hours must not be negative.');
        }

        $isExcluded = LeaveDayStatus::Excluded === $status;

        if ($isExcluded && null === $reason) {
            throw new \InvalidArgumentException('Excluded LeaveRequestDay requires a reason.');
        }
        if (!$isExcluded && null !== $reason) {
            throw new \InvalidArgumentException('Non-excluded LeaveRequestDay must not carry a reason.');
        }
        if ($isExcluded && 0.0 !== $hours) {
            throw new \InvalidArgumentException('Excluded LeaveRequestDay must have 0.0 hours.');
        }
        if (!$isExcluded && 0.0 === $hours) {
            throw new \InvalidArgumentException('Non-excluded LeaveRequestDay must have hours > 0.');
        }
    }
}
