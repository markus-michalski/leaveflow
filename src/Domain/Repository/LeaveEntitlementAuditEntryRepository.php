<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Entity\LeaveEntitlementAuditEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveEntitlementAuditEntry>
 */
class LeaveEntitlementAuditEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveEntitlementAuditEntry::class);
    }

    /**
     * @return list<LeaveEntitlementAuditEntry>
     */
    public function findByEntitlement(LeaveEntitlement $entitlement): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.entitlement = :entitlement')
            ->setParameter('entitlement', $entitlement)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
