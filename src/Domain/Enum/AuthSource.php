<?php

declare(strict_types=1);

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
