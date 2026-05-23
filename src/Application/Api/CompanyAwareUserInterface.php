<?php

declare(strict_types=1);

namespace App\Application\Api;

interface CompanyAwareUserInterface
{
    public function getCompanyId(): int;
}
