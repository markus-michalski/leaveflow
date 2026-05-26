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

namespace App\Application\TwoFactor;

/**
 * Paired plaintext + hash output of {@see BackupCodeGenerator}. The
 * plaintext is shown to the user once on setup; the hashes are what
 * gets persisted on the User entity. Same order in both arrays so the
 * caller can render and store without re-pairing.
 */
final readonly class BackupCodeBundle
{
    /**
     * @param list<string> $plaintextCodes
     * @param list<string> $hashedCodes
     */
    public function __construct(
        public array $plaintextCodes,
        public array $hashedCodes,
    ) {
    }
}
