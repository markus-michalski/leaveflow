<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Employee;
use App\Domain\Entity\IllnessAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IllnessAlert>
 */
class IllnessAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IllnessAlert::class);
    }

    public function existsForEmployeePeriod(Employee $employee, \DateTimeImmutable $periodStartedOn): bool
    {
        return null !== $this->findOneBy([
            'employee' => $employee,
            'periodStartedOn' => $periodStartedOn->setTime(0, 0),
        ]);
    }
}
