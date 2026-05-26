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

namespace App\Application\Approval;

use App\Domain\Entity\LeaveRequest;

/**
 * Books and refunds entitlement hours for a LeaveRequest.
 *
 * Extracted purely for testability — application services that depend on the
 * booker (Phase 9 AdminTypeChangeService etc.) need to mock release/consume
 * without wiring up the full entitlement repository + clock pipeline.
 */
interface LeaveRequestEntitlementBookerInterface
{
    /**
     * @throws \DomainException when the new type would overdraw the entitlement
     */
    public function consume(LeaveRequest $request): void;

    public function release(LeaveRequest $request): void;
}
