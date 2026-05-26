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
