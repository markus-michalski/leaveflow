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
