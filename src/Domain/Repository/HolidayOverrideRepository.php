<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\HolidayOverride;
use App\Domain\Entity\Location;
use App\Domain\Enum\FederalState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HolidayOverride>
 */
class HolidayOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HolidayOverride::class);
    }

    /**
     * State-wide overrides only (location IS NULL). Drives the admin
     * holiday overview per state — location-specific overrides surface
     * elsewhere and would only confuse a state-level calendar.
     *
     * @return list<HolidayOverride>
     */
    public function findByCompanyYearAndState(Company $company, int $year, FederalState $state): array
    {
        return $this->buildYearStateQuery($company, $year, $state)
            ->andWhere('o.location IS NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Resolves the overrides that apply to a given employee in a given
     * year. Returns:
     * - state-wide overrides (location IS NULL) for the employee's state
     * - location-specific overrides matching the employee's work location
     *
     * Use this for runtime calculations (LeaveRequestService etc.) — the
     * admin holiday overview should stick with
     * {@see findByCompanyYearAndState}.
     *
     * @return list<HolidayOverride>
     */
    public function findByEmployeeAndYear(Employee $employee, int $year): array
    {
        $location = $employee->getLocation();
        $state = FederalState::from($location->getFederalState());

        return $this->buildYearStateQuery($employee->getCompany(), $year, $state)
            ->andWhere('o.location IS NULL OR o.location = :location')
            ->setParameter('location', $location)
            ->getQuery()
            ->getResult();
    }

    /**
     * Looks up an existing override at the same (company, state,
     * location, date) coordinate. Used as an application-side
     * duplicate guard since MySQL's unique index treats NULL
     * location_id values as distinct, which would otherwise let two
     * state-wide overrides slip through for the same date.
     */
    public function findOneByConflict(
        Company $company,
        FederalState $state,
        ?Location $location,
        \DateTimeImmutable $date,
    ): ?HolidayOverride {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.company = :company')
            ->andWhere('o.federalState = :state')
            ->andWhere('o.date = :date')
            ->setParameter('company', $company)
            ->setParameter('state', $state)
            ->setParameter('date', $date->setTime(0, 0));

        if (null === $location) {
            $qb->andWhere('o.location IS NULL');
        } else {
            $qb->andWhere('o.location = :location')
                ->setParameter('location', $location);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return list<HolidayOverride>
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.company = :company')
            ->setParameter('company', $company)
            ->orderBy('o.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function buildYearStateQuery(Company $company, int $year, FederalState $state): \Doctrine\ORM\QueryBuilder
    {
        $start = (new \DateTimeImmutable())->setDate($year, 1, 1)->setTime(0, 0);
        $end = (new \DateTimeImmutable())->setDate($year, 12, 31)->setTime(23, 59, 59);

        return $this->createQueryBuilder('o')
            ->andWhere('o.company = :company')
            ->andWhere('o.federalState = :state')
            ->andWhere('o.date BETWEEN :start AND :end')
            ->setParameter('company', $company)
            ->setParameter('state', $state)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('o.date', 'ASC');
    }
}
