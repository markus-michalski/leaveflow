<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Repository\EmployeeRepository;
use App\Domain\ValueObject\WorkSchedule;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmployeeRepository::class)]
#[ORM\Table(name: 'employees')]
#[ORM\UniqueConstraint(name: 'uniq_employee_number_per_company', columns: ['company_id', 'employee_number'])]
class Employee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150, name: 'full_name')]
    private string $fullName;

    #[ORM\Column(length: 50, name: 'employee_number')]
    private string $employeeNumber;

    #[ORM\Embedded(class: WorkSchedule::class, columnPrefix: 'schedule_')]
    private WorkSchedule $workSchedule;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'department_id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

    #[ORM\Column(name: 'anonymized_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $anonymizedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'company_id', nullable: false)]
        private Company $company,
        string $fullName,
        string $employeeNumber,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'location_id', nullable: false)]
        private Location $location,
        WorkSchedule $workSchedule,
        #[ORM\Column(name: 'joined_at', type: 'date_immutable')]
        private \DateTimeImmutable $joinedAt,
        #[ORM\OneToOne(inversedBy: 'employee')]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, unique: true)]
        private ?User $user = null,
        #[ORM\Column(name: 'left_at', type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $leftAt = null,
    ) {
        $this->assertLocationBelongsToCompany($location, $company);
        $this->assertUserBelongsToCompany($user, $company);
        $this->assertLeftAfterJoined($joinedAt, $leftAt);

        $this->fullName = $this->normalizeName($fullName);
        $this->employeeNumber = $this->normalizeEmployeeNumber($employeeNumber);
        $this->workSchedule = $workSchedule;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getEmployeeNumber(): string
    {
        return $this->employeeNumber;
    }

    public function getLocation(): Location
    {
        return $this->location;
    }

    public function getWorkSchedule(): WorkSchedule
    {
        return $this->workSchedule;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function hasUser(): bool
    {
        return null !== $this->user;
    }

    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        if ($date < $this->joinedAt) {
            return false;
        }
        if (null !== $this->leftAt && $date > $this->leftAt) {
            return false;
        }

        return true;
    }

    public function rename(string $fullName): void
    {
        $this->fullName = $this->normalizeName($fullName);
    }

    public function reassignLocation(Location $location): void
    {
        $this->assertLocationBelongsToCompany($location, $this->company);
        $this->location = $location;
    }

    public function updateSchedule(WorkSchedule $workSchedule): void
    {
        $this->workSchedule = $workSchedule;
    }

    public function updateJoinedAt(\DateTimeImmutable $joinedAt): void
    {
        $this->assertLeftAfterJoined($joinedAt, $this->leftAt);
        $this->joinedAt = $joinedAt;
    }

    public function markLeft(\DateTimeImmutable $leftAt): void
    {
        $this->assertLeftAfterJoined($this->joinedAt, $leftAt);
        $this->leftAt = $leftAt;
    }

    public function linkUser(User $user): void
    {
        $this->assertUserBelongsToCompany($user, $this->company);
        $this->user = $user;
    }

    public function unlinkUser(): void
    {
        $this->user = null;
    }

    public function isAnonymized(): bool
    {
        return null !== $this->anonymizedAt;
    }

    public function getAnonymizedAt(): ?\DateTimeImmutable
    {
        return $this->anonymizedAt;
    }

    public function anonymize(string $anonymizedName, \DateTimeImmutable $at): void
    {
        if ($this->isAnonymized()) {
            throw new \LogicException(\sprintf('Employee #%d is already anonymized.', $this->id));
        }

        $this->fullName = $anonymizedName;
        $this->anonymizedAt = $at;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function assignToDepartment(?Department $department): void
    {
        if (null !== $department && $department->getCompany() !== $this->company) {
            throw new \InvalidArgumentException('Department must belong to the employee\'s company.');
        }
        $this->department = $department;
    }

    private function normalizeName(string $fullName): string
    {
        $trimmed = trim($fullName);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Employee.fullName must not be blank.');
        }

        return $trimmed;
    }

    private function normalizeEmployeeNumber(string $number): string
    {
        $trimmed = trim($number);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Employee.employeeNumber must not be blank.');
        }

        return $trimmed;
    }

    private function assertLocationBelongsToCompany(Location $location, Company $company): void
    {
        if ($location->getCompany() !== $company) {
            throw new \InvalidArgumentException('Location must belong to the employee\'s company.');
        }
    }

    private function assertUserBelongsToCompany(?User $user, Company $company): void
    {
        if (null === $user) {
            return;
        }
        if ($user->getCompany() !== $company) {
            throw new \InvalidArgumentException('Linked user must belong to the employee\'s company.');
        }
    }

    private function assertLeftAfterJoined(\DateTimeImmutable $joinedAt, ?\DateTimeImmutable $leftAt): void
    {
        if (null !== $leftAt && $leftAt < $joinedAt) {
            throw new \InvalidArgumentException('Employee.leftAt must not be earlier than joinedAt.');
        }
    }
}
