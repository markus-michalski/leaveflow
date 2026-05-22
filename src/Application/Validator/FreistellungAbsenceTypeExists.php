<?php

declare(strict_types=1);

namespace App\Application\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class FreistellungAbsenceTypeExists extends Constraint
{
    public const string MESSAGE = 'admin.company_settings.error.freistellung_absence_type_missing';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
