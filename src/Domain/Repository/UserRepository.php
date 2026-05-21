<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Company;
use App\Domain\Entity\User;
use App\Domain\Enum\AuthSource;
use App\Domain\Enum\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower(trim($email))]);
    }

    /**
     * Looks up a user by IdP source + the provider's subject identifier.
     * Used by OAuth/LDAP authenticators to find an already-provisioned user
     * on subsequent logins without relying on the (mutable) email address.
     */
    public function findByIdp(AuthSource $source, string $externalId): ?User
    {
        return $this->findOneBy([
            'authSource' => $source,
            'externalId' => $externalId,
        ]);
    }

    /**
     * Lookup for the public iCal feed endpoints. Inactive users are
     * excluded so deactivated accounts can't keep leaking the feed
     * via cached subscriptions in their calendar app.
     */
    public function findOneActiveByIcalToken(string $token): ?User
    {
        if ('' === $token) {
            return null;
        }

        return $this->findOneBy([
            'icalToken' => $token,
            'active' => true,
        ]);
    }

    /**
     * Active admin Users for a company. Recipient set for the
     * EscalationTriggered notification — the documented "last resort" path
     * when dept lead/deputy don't act on a Pending request.
     *
     * @return list<User>
     */
    public function findActiveAdminsByCompany(Company $company): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.company = :company')
            ->andWhere('u.role = :role')
            ->andWhere('u.active = :active')
            ->setParameter('company', $company)
            ->setParameter('role', UserRole::Admin)
            ->setParameter('active', true)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Paginated, filterable user list for the admin overview (#3 + #4).
     *
     * Filters:
     *  - $activeFilter: true → active only, false → deactivated only, null → all
     *  - $query: substring match on email OR employee.fullName (case-insensitive
     *    via LOWER + LIKE; both branches covered with OR so admins can search
     *    "jane" and find both `jane@…` and `Jane Doe`)
     *
     * The Employee join is a LEFT JOIN — Phase 2's User-without-Employee case
     * (admin/IT-only accounts) must still match if the email matches the query.
     *
     * @return list<User>
     */
    public function searchPaginated(?bool $activeFilter, ?string $query, int $page, int $perPage): array
    {
        $qb = $this->buildSearchQuery($activeFilter, $query);

        $offset = max(0, ($page - 1) * $perPage);

        return $qb
            ->orderBy('u.email', 'ASC')
            ->setMaxResults($perPage)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countSearch(?bool $activeFilter, ?string $query): int
    {
        $qb = $this->buildSearchQuery($activeFilter, $query);
        // The Employee LEFT JOIN can multiply rows when a User has multiple
        // Employee records (currently 1:1 by design but COUNT DISTINCT is the
        // correct shape regardless).
        $count = $qb
            ->select('COUNT(DISTINCT u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    private function buildSearchQuery(?bool $activeFilter, ?string $query): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('App\\Domain\\Entity\\Employee', 'e', 'WITH', 'e.user = u');

        if (null !== $activeFilter) {
            $qb->andWhere('u.active = :active')->setParameter('active', $activeFilter);
        }

        if (null !== $query && '' !== trim($query)) {
            $needle = '%'.strtolower(trim($query)).'%';
            $qb->andWhere('LOWER(u.email) LIKE :needle OR LOWER(e.fullName) LIKE :needle')
                ->setParameter('needle', $needle);
        }

        return $qb;
    }

    public function findOneBySlackUserId(string $slackUserId): ?User
    {
        return $this->findOneBy(['slackUserId' => $slackUserId]);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setHashedPassword($newHashedPassword);

        $entityManager = $this->getEntityManager();
        $entityManager->persist($user);
        $entityManager->flush();
    }
}
