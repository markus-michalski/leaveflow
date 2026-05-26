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

namespace App\Application\Entitlement;

/**
 * Year-end carryover transition contract.
 *
 * Extracted purely for testability — the YearTransitionHandler dispatched
 * by the Symfony Scheduler needs to mock the service without wiring up the
 * full repository + EntityManager pipeline.
 */
interface YearTransitionServiceInterface
{
    /**
     * @return list<YearTransitionEntry>
     */
    public function transition(int $sourceYear, bool $dryRun = false): array;
}
