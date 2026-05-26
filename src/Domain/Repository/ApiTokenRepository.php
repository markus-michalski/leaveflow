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

namespace App\Domain\Repository;

use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * Finds a token by its SHA-256 hash. Used by the API authenticator on
     * every request — hits the idx_api_token_hash index.
     */
    public function findByHash(string $hash): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * Returns all tokens for a company, newest first.
     *
     * @return list<ApiToken>
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.company = :company')
            ->setParameter('company', $company)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
