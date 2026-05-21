<?php

declare(strict_types=1);

namespace App\Application\Security;

final class EncryptionService implements EncryptionServiceInterface
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'APP_LDAP_ENCRYPTION_KEY')]
        private readonly string $base64Key,
    ) {
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->resolvedKey();
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    /** Returns null if the value cannot be decrypted (e.g. legacy plaintext or tampered data). */
    public function tryDecrypt(string $value): ?string
    {
        try {
            $key = $this->resolvedKey();
            $decoded = base64_decode($value, strict: true);

            if (false === $decoded
                || \strlen($decoded) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                return null;
            }

            $nonce = substr($decoded, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($decoded, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

            return false === $plaintext ? null : $plaintext;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvedKey(): string
    {
        if ('' === $this->base64Key) {
            throw new \RuntimeException('APP_LDAP_ENCRYPTION_KEY is not set. Generate one with: php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . PHP_EOL;"');
        }

        $decoded = base64_decode($this->base64Key, strict: true);

        if (false === $decoded || \strlen($decoded) !== \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(\sprintf('APP_LDAP_ENCRYPTION_KEY must be exactly %d bytes base64-encoded.', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }

        return $decoded;
    }
}
