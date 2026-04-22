<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Repository\DepartmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Org unit that owns the approval chain for its members.
 *
 * A department has a lead (primary approver) and an optional deputy (fallback
 * when the lead is unavailable). Lead and deputy must be distinct to keep the
 * fallback chain meaningful — if both are the same person the resolver
 * collapses to a single approver anyway.
 *
 * Inactive departments still exist for historical reporting but route new
 * approvals straight to the Admin fallback (enforced by ApproverResolver, not
 * here — the entity only tracks the flag).
 *
 * Not hierarchical in Phase 6: flat list per company. Nested departments
 * (sub-teams under a lead) are a deferred Phase 9 concern.
 */
#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'departments')]
#[ORM\UniqueConstraint(name: 'uniq_department_company_name', columns: ['company_id', 'name'])]
class Department
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'active', type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'company_id', nullable: false)]
        private Company $company,
        #[ORM\Column(length: 150)]
        private string $name,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'lead_id', nullable: true, onDelete: 'SET NULL')]
        private ?Employee $lead = null,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'deputy_id', nullable: true, onDelete: 'SET NULL')]
        private ?Employee $deputy = null,
    ) {
        $this->assertNotBlank($name, 'name');
        $this->name = trim($name);

        if (null !== $lead) {
            $this->assertSameCompany($lead, 'lead');
        }
        if (null !== $deputy) {
            $this->assertSameCompany($deputy, 'deputy');
        }
        $this->assertLeadAndDeputyDistinct($lead, $deputy);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLead(): ?Employee
    {
        return $this->lead;
    }

    public function getDeputy(): ?Employee
    {
        return $this->deputy;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function rename(string $name): void
    {
        $this->assertNotBlank($name, 'name');
        $this->name = trim($name);
    }

    public function assignLead(?Employee $lead): void
    {
        if (null !== $lead) {
            $this->assertSameCompany($lead, 'lead');
        }
        $this->assertLeadAndDeputyDistinct($lead, $this->deputy);
        $this->lead = $lead;
    }

    public function assignDeputy(?Employee $deputy): void
    {
        if (null !== $deputy) {
            $this->assertSameCompany($deputy, 'deputy');
        }
        $this->assertLeadAndDeputyDistinct($this->lead, $deputy);
        $this->deputy = $deputy;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    private function assertNotBlank(string $value, string $field): void
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException(\sprintf('Department.%s must not be blank.', $field));
        }
    }

    private function assertSameCompany(Employee $employee, string $field): void
    {
        if ($employee->getCompany() !== $this->company) {
            throw new \InvalidArgumentException(\sprintf('Department.%s must belong to the same company as the department.', $field));
        }
    }

    private function assertLeadAndDeputyDistinct(?Employee $lead, ?Employee $deputy): void
    {
        if (null !== $lead && null !== $deputy && $lead === $deputy) {
            throw new \InvalidArgumentException('Department.lead and Department.deputy must be distinct employees.');
        }
    }
}
