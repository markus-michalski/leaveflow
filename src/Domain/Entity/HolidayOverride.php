<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\FederalState;
use App\Domain\Enum\HolidayOverrideType;
use App\Domain\Repository\HolidayOverrideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-company override for the calculated holiday list.
 *
 * Added: inject a date as holiday (e.g. Augsburger Friedensfest).
 * Removed: suppress a calculated holiday (e.g. Mariae Himmelfahrt in a
 * protestant Bavarian region where it does not apply in practice).
 *
 * Scope is (company, federalState, optional location, date):
 * - location IS NULL  → applies to every employee in that state for the
 *   company (the original Phase-3 shape).
 * - location IS NOT NULL → applies only to employees whose work location
 *   matches. Lets companies model municipality-level holidays correctly
 *   (Augsburger Friedensfest in Augsburg, Fronleichnam in Eichsfeld /
 *   Bautzen, Mariä Himmelfahrt for protestant-minority BY Gemeinden).
 *
 * The location belongs to the same company as the override; the
 * constructor verifies tenant integrity.
 */
#[ORM\Entity(repositoryClass: HolidayOverrideRepository::class)]
#[ORM\Table(name: 'holiday_overrides')]
#[ORM\UniqueConstraint(name: 'uniq_override_company_state_loc_date', columns: ['company_id', 'federal_state', 'location_id', 'override_date'])]
class HolidayOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'company_id', nullable: false)]
        private Company $company,
        #[ORM\Column(name: 'federal_state', length: 10, enumType: FederalState::class)]
        private FederalState $federalState,
        #[ORM\Column(name: 'override_date', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column(length: 150)]
        private string $name,
        #[ORM\Column(length: 10, enumType: HolidayOverrideType::class)]
        private HolidayOverrideType $type,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'location_id', nullable: true)]
        private ?Location $location = null,
    ) {
        $this->assertNotBlank($name);
        $this->assertLocationBelongsToCompany($location, $company);
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

    public function getFederalState(): FederalState
    {
        return $this->federalState;
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

    public function getType(): HolidayOverrideType
    {
        return $this->type;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function update(
        FederalState $state,
        \DateTimeImmutable $date,
        string $name,
        HolidayOverrideType $type,
        ?Location $location = null,
    ): void {
        $this->assertNotBlank($name);
        $this->assertLocationBelongsToCompany($location, $this->company);
        $this->federalState = $state;
        $this->date = $date->setTime(0, 0);
        $this->name = $name;
        $this->type = $type;
        $this->location = $location;
    }

    private function assertNotBlank(string $name): void
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('HolidayOverride.name must not be blank.');
        }
    }

    private function assertLocationBelongsToCompany(?Location $location, Company $company): void
    {
        if (null !== $location && $location->getCompany() !== $company) {
            throw new \InvalidArgumentException('HolidayOverride.location must belong to the same company.');
        }
    }
}
