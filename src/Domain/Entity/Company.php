<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Repository\CompanyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\Table(name: 'companies')]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 200)]
        private string $name,
        #[ORM\Column(name: 'retention_period_months', options: ['default' => 36])]
        private int $retentionPeriodMonths = 36,
        /**
         * Threshold in calendar days after which a still-Pending leave
         * request triggers an EscalationTriggered notification to admins.
         * Default 3 days mirrors typical SMB SLA expectations.
         */
        #[ORM\Column(name: 'approval_escalation_days', options: ['default' => 3])]
        private int $approvalEscalationDays = 3,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRetentionPeriodMonths(): int
    {
        return $this->retentionPeriodMonths;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getApprovalEscalationDays(): int
    {
        return $this->approvalEscalationDays;
    }

    public function setApprovalEscalationDays(int $days): void
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Company.approvalEscalationDays must be at least 1.');
        }
        $this->approvalEscalationDays = $days;
    }
}
