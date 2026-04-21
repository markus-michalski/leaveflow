<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\LeaveRequestDay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveRequestDay>
 */
class LeaveRequestDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveRequestDay::class);
    }
}
