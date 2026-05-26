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

namespace App\Tests\Unit\Application\Security;

use App\Application\Security\EncryptionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EncryptionService::class)]
class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;

    protected function setUp(): void
    {
        // Generate a valid 32-byte key encoded as base64 for testing
        $key = base64_encode(str_repeat('a', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->service = new EncryptionService($key);
    }

    #[Test]
    public function encryptAndDecryptRoundTrip(): void
    {
        $plaintext = 's3cr3t-LDAP-p@ssword!';

        $ciphertext = $this->service->encrypt($plaintext);
        $result = $this->service->tryDecrypt($ciphertext);

        self::assertSame($plaintext, $result);
    }

    #[Test]
    public function producesUniqueOutputForSameInput(): void
    {
        $plaintext = 'same-password';

        $first = $this->service->encrypt($plaintext);
        $second = $this->service->encrypt($plaintext);

        self::assertNotSame($first, $second, 'Each call must use a fresh random nonce');
    }

    #[Test]
    public function encryptedValueIsBase64Encoded(): void
    {
        $ciphertext = $this->service->encrypt('test');

        self::assertNotFalse(base64_decode($ciphertext, strict: true));
    }

    #[Test]
    public function tryDecryptReturnNullForPlaintext(): void
    {
        // Simulates upgrading from pre-encryption: plaintext in DB must not crash
        $result = $this->service->tryDecrypt('plaintext-not-encrypted');

        self::assertNull($result);
    }

    #[Test]
    public function tryDecryptReturnNullForTamperedCiphertext(): void
    {
        $ciphertext = $this->service->encrypt('original');
        $tampered = base64_encode(str_repeat('x', 64));

        $result = $this->service->tryDecrypt($tampered);

        self::assertNull($result);
    }

    #[Test]
    public function throwsOnInvalidKeyLength(): void
    {
        $this->expectException(\RuntimeException::class);

        (new EncryptionService(base64_encode('too-short')))->encrypt('x');
    }

    #[Test]
    public function throwsOnNonBase64Key(): void
    {
        $this->expectException(\RuntimeException::class);

        (new EncryptionService('not-valid-base64!!!'))->encrypt('x');
    }

    #[Test]
    public function tryDecryptReturnsNullForCiphertextEncryptedUnderDifferentKey(): void
    {
        $otherKey = base64_encode(str_repeat('b', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $otherService = new EncryptionService($otherKey);

        $ciphertext = $otherService->encrypt('secret');
        $result = $this->service->tryDecrypt($ciphertext);

        self::assertNull($result);
    }
}
