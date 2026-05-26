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
