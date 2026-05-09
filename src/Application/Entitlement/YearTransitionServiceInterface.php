<?php

declare(strict_types=1);

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
