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

use App\Domain\Repository\LocationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LocationRepository::class)]
#[ORM\Table(name: 'locations')]
class Location
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'company_id', nullable: false)]
        private Company $company,
        #[ORM\Column(length: 150)]
        private string $name,
        #[ORM\Column(length: 2)]
        private string $country,
        // ISO 3166-2 subdivision code (e.g., "DE-BY" for Bavaria). Used by HolidayCalculator in Phase 3.
        #[ORM\Column(length: 10)]
        private string $federalState,
        #[ORM\Column(length: 150)]
        private string $city,
    ) {
        $this->assertNotBlank($name, 'name');
        $this->assertNotBlank($country, 'country');
        $this->assertNotBlank($federalState, 'federalState');
        $this->assertNotBlank($city, 'city');
        $this->country = strtoupper($country);
        $this->federalState = strtoupper($federalState);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getFederalState(): string
    {
        return $this->federalState;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function rename(string $name): void
    {
        $this->assertNotBlank($name, 'name');
        $this->name = $name;
    }

    public function moveTo(string $country, string $federalState, string $city): void
    {
        $this->assertNotBlank($country, 'country');
        $this->assertNotBlank($federalState, 'federalState');
        $this->assertNotBlank($city, 'city');
        $this->country = strtoupper($country);
        $this->federalState = strtoupper($federalState);
        $this->city = $city;
    }

    private function assertNotBlank(string $value, string $field): void
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException(\sprintf('Location.%s must not be blank.', $field));
        }
    }
}
