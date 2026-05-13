<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

enum RequirementStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Warn = 'warn';
}
