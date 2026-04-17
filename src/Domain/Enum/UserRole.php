<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';

    public function asSymfonyRole(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }

    public function label(): string
    {
        return 'user.role.'.$this->value;
    }
}
