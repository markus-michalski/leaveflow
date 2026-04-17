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
}
