<?php

declare(strict_types=1);

namespace App\Application\Entitlement;

use App\Domain\Entity\LeaveEntitlement;

/**
 * Deducts hours from a list of leave entitlements using FIFO expiry order.
 *
 * Callers (Phase 5 LeaveRequest flow) fetch the employee's entitlements, hand
 * them to this service along with the consumption amount and reference date.
 * The service mutates entities in place — persistence is the caller's
 * responsibility so it can participate in its own transaction.
 *
 * Ordering:
 * 1. Entitlements with the earliest expiresAt first (oldest Carryover wins).
 * 2. Entitlements without expiresAt last (Regular entitlements).
 *
 * Preflight: aggregate available balance is validated before any mutation.
 * A failed preflight leaves every entitlement untouched.
 */
final readonly class EntitlementConsumer
{
    private const float BALANCE_EPSILON = 0.0001;

    /**
     * @param list<LeaveEntitlement> $entitlements
     *
     * @throws \InvalidArgumentException when hours is negative
     * @throws \DomainException when the aggregated available balance is insufficient
     */
    public function consume(array $entitlements, float $hours, \DateTimeImmutable $asOf): void
    {
        if ($hours < 0) {
            throw new \InvalidArgumentException('EntitlementConsumer.consume requires non-negative hours.');
        }
        if (0.0 === $hours) {
            return;
        }

        $available = $this->availableEntitlements($entitlements, $asOf);
        $totalAvailable = array_sum(array_map(
            static fn (LeaveEntitlement $e): float => $e->getHoursRemaining(),
            $available,
        ));

        if ($totalAvailable + self::BALANCE_EPSILON < $hours) {
            throw new \DomainException(\sprintf('Insufficient entitlement balance: requested %.2fh, available %.2fh.', $hours, $totalAvailable));
        }

        $remaining = $hours;
        foreach ($available as $entitlement) {
            if ($remaining <= self::BALANCE_EPSILON) {
                break;
            }
            $take = min($remaining, $entitlement->getHoursRemaining());
            if ($take <= 0) {
                continue;
            }
            $entitlement->consume($take);
            $remaining -= $take;
        }
    }

    /**
     * @param list<LeaveEntitlement> $entitlements
     *
     * @return list<LeaveEntitlement>
     */
    private function availableEntitlements(array $entitlements, \DateTimeImmutable $asOf): array
    {
        $filtered = array_values(array_filter(
            $entitlements,
            static fn (LeaveEntitlement $e): bool => !$e->isExpiredOn($asOf) && $e->getHoursRemaining() > 0,
        ));

        usort($filtered, static function (LeaveEntitlement $a, LeaveEntitlement $b): int {
            $aExpiry = $a->getExpiresAt();
            $bExpiry = $b->getExpiresAt();
            if (null === $aExpiry && null === $bExpiry) {
                return 0;
            }
            if (null === $aExpiry) {
                return 1;
            }
            if (null === $bExpiry) {
                return -1;
            }

            return $aExpiry <=> $bExpiry;
        });

        return $filtered;
    }
}
