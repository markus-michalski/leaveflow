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

namespace App\Application\Import;

use App\Domain\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestrates the parse → validate → optional commit pipeline.
 *
 * The dry-run flow (preview screen) calls {@see preview} which returns
 * row results without persisting anything. The commit flow re-parses
 * and re-validates the same CSV (DB state may have shifted between
 * preview and confirm — race-tolerant, no surprise corruption) and
 * persists only the rows that still pass.
 *
 * Persists in a single flush so a partial failure rolls back the
 * whole batch — admins should re-validate, fix, re-import rather
 * than reasoning about half-imported state.
 */
final readonly class EmployeeImportService
{
    public function __construct(
        private EmployeeCsvParser $parser,
        private EmployeeImportValidator $validator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<EmployeeImportRowResult>
     */
    public function preview(string $csvContent, Company $company): array
    {
        $rows = $this->parser->parse($csvContent);

        return $this->validator->validate($company, $rows);
    }

    /**
     * Persists every row that passes re-validation. Returns the same
     * result list so the controller can render a post-commit summary.
     *
     * @return list<EmployeeImportRowResult>
     */
    public function commit(string $csvContent, Company $company): array
    {
        $results = $this->preview($csvContent, $company);

        $hasInvalid = false;
        foreach ($results as $result) {
            if (!$result->isValid()) {
                $hasInvalid = true;
                break;
            }
        }
        if ($hasInvalid) {
            // Don't persist a partial batch — admins re-validate after
            // fixing the offending rows.
            return $results;
        }

        foreach ($results as $result) {
            if (null !== $result->employee) {
                $this->entityManager->persist($result->employee);
            }
        }
        $this->entityManager->flush();

        return $results;
    }
}
