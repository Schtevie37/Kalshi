<?php

declare(strict_types=1);

final class Vault
{
    public static function key(string $appKey): string
    {
        $key = base64_decode($appKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY must be a base64-encoded 32-byte key. Run bin/setup.sh again.');
        }

        return $key;
    }

    /**
     * @return array{0: string, 1: string} cipher and nonce, both base64
     */
    public static function seal(string $plaintext, string $appKey): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::key($appKey));

        return [base64_encode($cipher), base64_encode($nonce)];
    }

    public static function open(string $cipherB64, string $nonceB64, string $appKey): string
    {
        $cipher = base64_decode($cipherB64, true);
        $nonce = base64_decode($nonceB64, true);
        if ($cipher === false || $nonce === false) {
            throw new RuntimeException('Stored API key could not be read.');
        }
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key($appKey));
        if ($plain === false) {
            throw new RuntimeException('Stored API key could not be decrypted. The app key may have changed.');
        }

        return $plain;
    }
}
