<?php

declare(strict_types=1);

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
