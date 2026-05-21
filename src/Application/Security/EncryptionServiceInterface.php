<?php

declare(strict_types=1);

namespace App\Application\Security;

interface EncryptionServiceInterface
{
    public function encrypt(string $plaintext): string;

    /** Returns null if the value cannot be decrypted (e.g. legacy plaintext or tampered data). */
    public function tryDecrypt(string $value): ?string;
}
