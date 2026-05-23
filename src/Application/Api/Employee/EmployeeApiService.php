<?php

declare(strict_types=1);

namespace App\Application\Api\Employee;

use App\Application\Employee\EmployeeExitService;
use App\Application\Security\UserProvisioningServiceInterface;
use App\Domain\Entity\Company;
use App\Domain\Entity\Employee;
use App\Domain\Enum\UserRole;
use App\Domain\Enum\Weekday;
use App\Domain\Repository\EmployeeRepository;
use App\Domain\Repository\LocationRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class EmployeeApiService
{
    public function __construct(
        private readonly UserProvisioningServiceInterface $userProvisioning,
        private readonly EmployeeRepository $employeeRepository,
        private readonly LocationRepository $locationRepository,
        private readonly EmployeeExitService $exitService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(CreateEmployeeRequest $request, Company $company): Employee
    {
        $location = $this->locationRepository->find($request->locationId);
        if (null === $location || $location->getCompany()->getId() !== $company->getId()) {
            throw new NotFoundHttpException('Location not found.');
        }

        if (null !== $this->employeeRepository->findOneByEmployeeNumber($company, $request->employeeNumber)) {
            throw new UnprocessableEntityHttpException('Employee number already in use.');
        }

        if ('admin' === strtolower($request->role)) {
            throw new UnprocessableEntityHttpException('Role "admin" cannot be assigned via the API.');
        }

        $role = match (strtolower($request->role)) {
            'manager' => UserRole::Manager,
            default => UserRole::Employee,
        };

        // Wrapping in a transaction ensures no orphan User row if Employee persist fails.
        return $this->entityManager->wrapInTransaction(function () use ($request, $company, $location, $role): Employee {
            $user = $this->userProvisioning->provisionLocal(
                company: $company,
                email: $request->email,
                role: $role,
            );

            $schedule = WorkSchedule::autoDistribute(
                $request->weeklyHours,
                [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday],
            );

            // joinedAt format is already validated by #[Assert\Date] on the DTO.
            $joinedAt = \DateTimeImmutable::createFromFormat('Y-m-d', $request->joinedAt);
            \assert($joinedAt instanceof \DateTimeImmutable);

            $employee = new Employee(
                company: $company,
                fullName: $request->name,
                employeeNumber: $request->employeeNumber,
                location: $location,
                workSchedule: $schedule,
                joinedAt: $joinedAt,
                user: $user,
            );

            $this->entityManager->persist($employee);

            return $employee;
        });
    }

    public function update(Employee $employee, UpdateEmployeeRequest $request): void
    {
        if (null !== $request->name) {
            $employee->rename($request->name);
        }

        if (null !== $request->locationId) {
            $location = $this->locationRepository->find($request->locationId);
            if (null === $location || $location->getCompany()->getId() !== $employee->getCompany()->getId()) {
                throw new NotFoundHttpException('Location not found.');
            }
            $employee->reassignLocation($location);
        }

        if (null !== $request->weeklyHours) {
            $schedule = WorkSchedule::autoDistribute(
                $request->weeklyHours,
                [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday],
            );
            $employee->updateSchedule($schedule);
        }

        $this->entityManager->flush();
    }

    public function deactivate(Employee $employee, ?\DateTimeImmutable $exitDate = null): void
    {
        $date = $exitDate ?? $this->clock->now();
        $this->exitService->execute($employee, $date, $employee->getCompany()->getExitLeaveHandling());
    }
}
