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

namespace App\Domain\Repository;

use App\Domain\Entity\LeaveRequest;
use App\Domain\Entity\LeaveRequestAuditEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveRequestAuditEntry>
 */
class LeaveRequestAuditEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveRequestAuditEntry::class);
    }

    /**
     * Returns all audit entries for a request, newest first. Used by the
     * request detail page so managers and owners see the full decision trail.
     *
     * @return list<LeaveRequestAuditEntry>
     */
    public function findByLeaveRequest(LeaveRequest $leaveRequest): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.leaveRequest = :request')
            ->setParameter('request', $leaveRequest)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
