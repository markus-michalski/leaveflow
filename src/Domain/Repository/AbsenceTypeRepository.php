<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\AbsenceType;
use App\Domain\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AbsenceType>
 */
class AbsenceTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbsenceType::class);
    }

    /**
     * @return list<AbsenceType>
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->setParameter('company', $company)
            ->orderBy('t.active', 'DESC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<AbsenceType>
     */
    public function findActiveByCompany(Company $company): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->andWhere('t.active = true')
            ->setParameter('company', $company)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCompanyAndName(Company $company, string $name): ?AbsenceType
    {
        return $this->findOneBy(['company' => $company, 'name' => $name]);
    }
}
