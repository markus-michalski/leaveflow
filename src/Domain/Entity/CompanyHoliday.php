<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Repository\CompanyHolidayRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Company-wide non-working day (Betriebsruhe, Brueckentag).
 *
 * Applies to all locations of the company regardless of federal state.
 * Non-recurring in v1 — recurring patterns deferred to Phase 9.
 */
#[ORM\Entity(repositoryClass: CompanyHolidayRepository::class)]
#[ORM\Table(name: 'company_holidays')]
#[ORM\UniqueConstraint(name: 'uniq_company_holiday_date', columns: ['company_id', 'holiday_date'])]
class CompanyHoliday
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'company_id', nullable: false)]
        private Company $company,
        #[ORM\Column(name: 'holiday_date', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column(length: 150)]
        private string $name,
    ) {
        $this->assertNotBlank($name);
        $this->date = $date->setTime(0, 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getYear(): int
    {
        return (int) $this->date->format('Y');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function update(\DateTimeImmutable $date, string $name): void
    {
        $this->assertNotBlank($name);
        $this->date = $date->setTime(0, 0);
        $this->name = $name;
    }

    private function assertNotBlank(string $name): void
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('CompanyHoliday.name must not be blank.');
        }
    }
}
