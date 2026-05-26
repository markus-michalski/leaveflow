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

namespace App\Domain\Entity;

use App\Domain\Enum\LeaveEntitlementType;
use App\Domain\Repository\AbsenceTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Classifier for leave requests (e.g. Urlaub, Krankheit, Überstundenabbau).
 *
 * Company-scoped: every company maintains its own catalogue — same name in
 * different companies is allowed. Admins can add custom types (e.g. "Home
 * Office", "Mobile Work") beyond the six defaults seeded via fixtures.
 *
 * Policy flags:
 * - deductsFromLeave: true = consumes a regular/carryover entitlement
 * - requiresApproval: true = goes through manager approval state machine
 * - requiredBucket: optional bucket binding for deducting types. null =
 *   unified pool (any entitlement, FIFO by expiry); Regular = only counts
 *   against the current year's grant; Carryover = only counts against
 *   carryover entitlements. Used by the booker to scope consumption so
 *   "Resturlaub" requests can't silently eat into the regular grant when
 *   the carryover bucket is empty. Ignored when deductsFromLeave is false.
 *
 * Color is stored as uppercase 3- or 6-digit hex (#RGB or #RRGGBB) and used
 * for calendar rendering. An icon field will be added in Phase 7 when the
 * FullCalendar integration actually renders icons — with a proper picker, not
 * a free-text string.
 */
#[ORM\Entity(repositoryClass: AbsenceTypeRepository::class)]
#[ORM\Table(name: 'absence_types')]
#[ORM\UniqueConstraint(name: 'uniq_absence_type_name_per_company', columns: ['company_id', 'name'])]
class AbsenceType
{
    private const string HEX_COLOR_PATTERN = '/^#(?:[0-9A-F]{3}|[0-9A-F]{6})$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 7)]
    private string $color;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'company_id', nullable: false)]
        private Company $company,
        string $name,
        #[ORM\Column(name: 'deducts_from_leave', type: Types::BOOLEAN)]
        private bool $deductsFromLeave,
        #[ORM\Column(name: 'requires_approval', type: Types::BOOLEAN)]
        private bool $requiresApproval,
        string $color,
        #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
        private bool $active = true,
        #[ORM\Column(name: 'required_bucket', length: 20, enumType: LeaveEntitlementType::class, nullable: true)]
        private ?LeaveEntitlementType $requiredBucket = null,
        #[ORM\Column(name: 'is_illness_tracking', type: Types::BOOLEAN, options: ['default' => false])]
        private bool $illnessTracking = false,
    ) {
        $this->name = $this->normalizeName($name);
        $this->color = $this->normalizeColor($color);
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

    public function deductsFromLeave(): bool
    {
        return $this->deductsFromLeave;
    }

    public function requiresApproval(): bool
    {
        return $this->requiresApproval;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getRequiredBucket(): ?LeaveEntitlementType
    {
        return $this->requiredBucket;
    }

    /**
     * Whether requests of this type feed the rolling 6-week illness
     * counter. Companies typically flip this on for "Krankheit" only;
     * "Sonderurlaub", "Kur" etc. stay false even though they're absences.
     */
    public function isIllnessTracking(): bool
    {
        return $this->illnessTracking;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function update(
        string $name,
        bool $deductsFromLeave,
        bool $requiresApproval,
        string $color,
        ?LeaveEntitlementType $requiredBucket = null,
        bool $illnessTracking = false,
    ): void {
        $this->name = $this->normalizeName($name);
        $this->color = $this->normalizeColor($color);
        $this->deductsFromLeave = $deductsFromLeave;
        $this->requiresApproval = $requiresApproval;
        $this->requiredBucket = $deductsFromLeave ? $requiredBucket : null;
        $this->illnessTracking = $illnessTracking;
    }

    private function normalizeName(string $name): string
    {
        $trimmed = trim($name);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('AbsenceType.name must not be blank.');
        }

        return $trimmed;
    }

    private function normalizeColor(string $color): string
    {
        $normalized = strtoupper(trim($color));
        if (1 !== preg_match(self::HEX_COLOR_PATTERN, $normalized)) {
            throw new \InvalidArgumentException(\sprintf('AbsenceType.color must be a hex color like "#RRGGBB" or "#RGB", got "%s".', $color));
        }

        return $normalized;
    }
}
