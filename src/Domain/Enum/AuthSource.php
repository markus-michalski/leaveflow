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

enum AuthSource: string
{
    case Local = 'local';
    case Ldap = 'ldap';
    case Google = 'google';
    case Entra = 'entra';

    public function isLocal(): bool
    {
        return $this === self::Local;
    }

    /** OAuth IdPs (Google, Entra) enforce MFA on their side — skip our TOTP. */
    public function skipsTwoFactor(): bool
    {
        return $this === self::Google || $this === self::Entra;
    }
}
