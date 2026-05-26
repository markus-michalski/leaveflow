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

namespace App\Application\Security;

interface EncryptionServiceInterface
{
    public function encrypt(string $plaintext): string;

    /** Returns null if the value cannot be decrypted (e.g. legacy plaintext or tampered data). */
    public function tryDecrypt(string $value): ?string;
}
