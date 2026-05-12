<?php

declare(strict_types=1);

namespace App\Application\TwoFactor;

/**
 * Produces a fresh batch of one-time backup codes. Codes are 8-char
 * lowercase alphanumeric strings drawn from a 32-symbol set (digits +
 * lowercase letters minus visually-ambiguous 0/o/1/l) so users can
 * copy them from a phone screen without mis-reading.
 *
 * The pair (plaintext, sha256-hash) is returned so the caller can show
 * the plaintext exactly once and store only the hashes — matching the
 * User entity's {@see \App\Domain\Entity\User::enableTotp()} contract.
 */
final readonly class BackupCodeGenerator
{
    private const string ALPHABET = '23456789abcdefghijkmnpqrstuvwxyz';
    private const int CODE_LENGTH = 8;

    public function generate(int $count): BackupCodeBundle
    {
        if ($count < 1) {
            throw new \InvalidArgumentException(\sprintf('Backup code count must be >= 1, got %d.', $count));
        }

        $plaintext = [];
        $hashed = [];
        $alphabetLength = \strlen(self::ALPHABET);

        for ($i = 0; $i < $count; ++$i) {
            $code = '';
            for ($j = 0; $j < self::CODE_LENGTH; ++$j) {
                $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
            }
            // Re-roll on the rare collision so the contract holds even
            // for large counts. With a 32^8 ≈ 1.1 × 10^12 keyspace this
            // loops zero times in practice.
            if (\in_array($code, $plaintext, true)) {
                --$i;
                continue;
            }
            $plaintext[] = $code;
            $hashed[] = hash('sha256', $code);
        }

        return new BackupCodeBundle($plaintext, $hashed);
    }
}
