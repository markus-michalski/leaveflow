<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

/**
 * Single line on the system-requirements check page rendered before
 * the first-run wizard. `remedy` carries copy-pasteable commands or
 * env-var hints for the `fail` and `warn` cases.
 */
final readonly class RequirementCheck
{
    public function __construct(
        public string $label,
        public RequirementStatus $status,
        public string $detail,
        public ?string $remedy = null,
    ) {
    }

    public function isPassing(): bool
    {
        return RequirementStatus::Pass === $this->status;
    }

    public function isBlocking(): bool
    {
        return RequirementStatus::Fail === $this->status;
    }
}
