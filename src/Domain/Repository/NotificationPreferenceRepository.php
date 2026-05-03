<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\NotificationPreference;
use App\Domain\Entity\User;
use App\Domain\Enum\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationPreference>
 */
class NotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationPreference::class);
    }

    public function findOneByUserAndType(User $user, NotificationType $type): ?NotificationPreference
    {
        return $this->findOneBy(['user' => $user, 'type' => $type]);
    }

    /**
     * Encodes the lazy-default rule: no row means email is enabled. Callers
     * (NotificationDispatcher) consult this before dispatching emails.
     */
    public function isEmailEnabledFor(User $user, NotificationType $type): bool
    {
        $pref = $this->findOneByUserAndType($user, $type);
        if (null === $pref) {
            return true;
        }

        return $pref->isEmailEnabled();
    }

    /**
     * Returns all preferences for one user, keyed by NotificationType value.
     * Drives the toggle UI — combined with the type enum, the UI shows a row
     * per type with the saved (or default) state.
     *
     * @return array<string, NotificationPreference>
     */
    public function findAllForUserKeyedByType(User $user): array
    {
        $rows = $this->findBy(['user' => $user]);

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row->getType()->value] = $row;
        }

        return $byType;
    }
}
