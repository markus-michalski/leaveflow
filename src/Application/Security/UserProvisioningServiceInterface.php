<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\AuthSource;
use App\Domain\Enum\UserRole;

interface UserProvisioningServiceInterface
{
    public function provisionLocal(
        Company $company,
        string $email,
        UserRole $role,
        ?string $plainPassword = null,
    ): User;

    public function provisionFromIdpClaims(
        Company $company,
        string $email,
        AuthSource $authSource,
        string $externalId,
        UserRole $role,
    ): User;
}
