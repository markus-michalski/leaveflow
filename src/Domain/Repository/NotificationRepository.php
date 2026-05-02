<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Returns the most recent notifications for a user (read or unread),
     * newest first. Used by the inbox view.
     *
     * @return list<Notification>
     */
    public function findRecentForUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.recipient = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts unread notifications for the bell badge. Hot-path query — uses
     * the (recipient_id, read_at) index.
     */
    public function countUnreadForUser(User $user): int
    {
        $count = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Marks all unread notifications for this user as read. Used by the
     * "mark all read" inbox action. Caller is responsible for flushing.
     */
    public function markAllAsReadForUser(User $user, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
