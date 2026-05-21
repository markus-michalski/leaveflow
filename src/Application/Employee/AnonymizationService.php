<?php

declare(strict_types=1);

namespace App\Application\Employee;

use App\Domain\Entity\Employee;
use App\Domain\Repository\EmployeeRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Executes DSGVO data anonymization for former employees whose retention
 * period has expired. The caller owns the surrounding transaction and must
 * call flush() after this method returns.
 */
final readonly class AnonymizationService
{
    public function __construct(
        private EmployeeRepository $repository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Anonymizes a single employee and their linked user account.
     *
     * Acquires a pessimistic write lock on the Employee row to prevent
     * concurrent double-anonymization under parallel admin requests.
     *
     * Preconditions (throws on violation):
     *  - Employee must be persisted (id != null)
     *  - Employee must have leftAt set (not still active)
     *  - Retention period (leftAt + company.retentionPeriodMonths) must be elapsed
     *  - Employee must not already be anonymized
     *
     * @throws \LogicException on precondition failure
     * @throws AnonymizationNotDueException when retention period has not elapsed
     */
    public function anonymize(Employee $employee): void
    {
        if (null === $employee->getId()) {
            throw new \LogicException('Cannot anonymize an unpersisted Employee.');
        }

        $this->entityManager->lock($employee, LockMode::PESSIMISTIC_WRITE);

        $asOf = $this->clock->now();

        if (null === $employee->getLeftAt()) {
            throw new \LogicException(\sprintf('Cannot anonymize employee "%s": still active (no exit date set).', $employee->getFullName()));
        }

        if ($employee->isAnonymized()) {
            throw new \LogicException(\sprintf('Employee #%d is already anonymized.', $employee->getId()));
        }

        $retentionMonths = $employee->getCompany()->getRetentionPeriodMonths();
        $dueAt = $employee->getLeftAt()->modify(\sprintf('+%d months', $retentionMonths));

        if ($asOf < $dueAt) {
            throw new AnonymizationNotDueException(\sprintf('Employee "%s" cannot be anonymized yet — retention period of %d months expires on %s.', $employee->getFullName(), $retentionMonths, $dueAt->format('Y-m-d')));
        }

        $anonymizedName = \sprintf('Ehemaliger Mitarbeiter #%d', $employee->getId());
        $employee->anonymize($anonymizedName, $asOf);

        $user = $employee->getUser();
        if (null !== $user) {
            if (null === $user->getId()) {
                throw new \LogicException('Cannot anonymize an unpersisted User.');
            }
            $user->anonymize(\sprintf('anonymized-%d@leaveflow.local', $user->getId()));
        }
    }

    /**
     * Returns employees whose retention period has elapsed and who are not
     * yet anonymized.
     *
     * @return list<Employee>
     */
    public function findDue(\DateTimeImmutable $asOf): array
    {
        return $this->repository->findDueForAnonymization($asOf);
    }
}
