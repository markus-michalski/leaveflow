<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveRequest;
use App\Domain\Enum\LeaveRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveRequest>
 */
class LeaveRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveRequest::class);
    }

    /**
     * Requests awaiting a manager decision, scoped to the approver's
     * department: Pending and CancelRequested. Four-eyes is enforced by
     * excluding the approver's own requests.
     *
     * @return list<LeaveRequest>
     */
    public function findActionableByApprover(Employee $approver): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->innerJoin('e.department', 'd')
            ->where('r.status IN (:statuses)')
            ->andWhere('d.active = true')
            ->andWhere('d.lead = :approver OR d.deputy = :approver')
            ->andWhere('e != :approver')
            ->setParameter('statuses', [
                LeaveRequestStatus::Pending->value,
                LeaveRequestStatus::CancelRequested->value,
            ])
            ->setParameter('approver', $approver)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Admin view: every actionable request in the company regardless of
     * department membership. Separate query so manager and admin code paths
     * stay cleanly split.
     *
     * @return list<LeaveRequest>
     */
    public function findActionableInCompany(Company $company): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.employee', 'e')
            ->where('r.status IN (:statuses)')
            ->andWhere('e.company = :company')
            ->setParameter('statuses', [
                LeaveRequestStatus::Pending->value,
                LeaveRequestStatus::CancelRequested->value,
            ])
            ->setParameter('company', $company)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
