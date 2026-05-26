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
 * German federal states by ISO 3166-2 code.
 *
 * Used as the identifying key for state-specific holiday rules in the
 * HolidayCalculator as well as the `Location.federalState` column.
 */
enum FederalState: string
{
    case BadenWuerttemberg = 'DE-BW';
    case Bayern = 'DE-BY';
    case Berlin = 'DE-BE';
    case Brandenburg = 'DE-BB';
    case Bremen = 'DE-HB';
    case Hamburg = 'DE-HH';
    case Hessen = 'DE-HE';
    case MecklenburgVorpommern = 'DE-MV';
    case Niedersachsen = 'DE-NI';
    case NordrheinWestfalen = 'DE-NW';
    case RheinlandPfalz = 'DE-RP';
    case Saarland = 'DE-SL';
    case Sachsen = 'DE-SN';
    case SachsenAnhalt = 'DE-ST';
    case SchleswigHolstein = 'DE-SH';
    case Thueringen = 'DE-TH';

    public function label(): string
    {
        return 'federal_state.'.strtolower(substr($this->value, 3));
    }
}
