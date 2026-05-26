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

namespace App\Domain\Enum;

/**
 * Determines how unused leave balance is handled when an employee exits mid-year.
 *
 * Configured per company; applied by the exit workflow when `Employee.leftAt` is set.
 */
enum ExitLeaveHandling: string
{
    /** Remaining hours are paid out (Urlaubsabgeltung per §7 Abs. 4 BUrlG). */
    case PayOut = 'pay_out';

    /** Employee must consume all remaining leave before the exit date (Urlaubserfüllung). */
    case MandatoryConsumption = 'mandatory_consumption';

    /**
     * Remaining leave is converted into a paid release from work obligation
     * ("Freistellung unter Anrechnung des Urlaubsanspruchs"). Requires a
     * dedicated AbsenceType of the same name configured in the company.
     */
    case Freistellung = 'freistellung';

    public function translationKey(): string
    {
        return 'admin.exit_handling.label.'.$this->value;
    }
}
