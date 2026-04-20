<?php

declare(strict_types=1);

namespace App\Application\Entitlement;

enum YearTransitionStatus: string
{
    case Created = 'created';
    case SkippedEmptyBalance = 'skipped_empty_balance';
    case SkippedAlreadyExists = 'skipped_already_exists';
}
