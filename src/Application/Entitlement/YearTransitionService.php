<?php

declare(strict_types=1);

namespace App\Application\Entitlement;

use App\Domain\Entity\Employee;
use App\Domain\Entity\LeaveEntitlement;
use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\LeaveEntitlementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates carryover entitlements for the year following `sourceYear`.
 *
 * For each Regular entitlement in the source year with remaining balance, a
 * corresponding Carryover entitlement is created for the next year with
 * expiry set to `{target-year}-03-31` (BUrlG §7 Abs. 3 default). Admins can
 * extend the expiry afterwards via the admin UI when illness, parental leave,
 * or missing employer notice (BAG case law) require it.
 *
 * The service is idempotent in the conservative sense: if a Carryover already
 * exists for the target year, the employee is skipped rather than overwritten.
 * Dry-run mode builds the same report but persists nothing.
 */
final readonly class YearTransitionService
{
    public function __construct(
        private LeaveEntitlementRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<YearTransitionEntry>
     */
    public function transition(int $sourceYear, bool $dryRun = false): array
    {
        $targetYear = $sourceYear + 1;
        $expiresAt = (new \DateTimeImmutable())->setDate($targetYear, 3, 31)->setTime(0, 0);

        /** @var list<LeaveEntitlement> $regularEntitlements */
        $regularEntitlements = $this->repository->findBy([
            'year' => $sourceYear,
            'type' => LeaveEntitlementType::Regular,
        ]);

        $report = [];
        foreach ($regularEntitlements as $regular) {
            $employee = $regular->getEmployee();
            $remaining = $regular->getHoursRemaining();

            if ($remaining <= 0 || $this->employeeLeftBeforeTargetYear($employee, $targetYear)) {
                $report[] = new YearTransitionEntry($employee, 0.0, YearTransitionStatus::SkippedEmptyBalance);
                continue;
            }

            if (null !== $this->repository->findOneByEmployeeYearAndType($employee, $targetYear, LeaveEntitlementType::Carryover)) {
                $report[] = new YearTransitionEntry($employee, 0.0, YearTransitionStatus::SkippedAlreadyExists);
                continue;
            }

            if (!$dryRun) {
                $carryover = new LeaveEntitlement(
                    $employee,
                    $targetYear,
                    LeaveEntitlementType::Carryover,
                    $remaining,
                    $expiresAt,
                );
                $this->entityManager->persist($carryover);
            }

            $report[] = new YearTransitionEntry($employee, $remaining, YearTransitionStatus::Created);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $report;
    }

    private function employeeLeftBeforeTargetYear(Employee $employee, int $targetYear): bool
    {
        $leftAt = $employee->getLeftAt();
        if (null === $leftAt) {
            return false;
        }

        return (int) $leftAt->format('Y') < $targetYear;
    }
}
