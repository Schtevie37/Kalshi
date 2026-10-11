<?php

declare(strict_types=1);

/**
 * Signs Kalshi requests.
 * Ed25519 keys sign the message directly.
 * RSA keys use RSA-PSS with SHA-256, MGF1-SHA-256, and a salt as long as the digest.
 */
final class Signer
{
    public static function detectType(string $pem): string
    {
        $der = self::pemBody($pem);
        if (str_contains($der, "\x06\x03\x2b\x65\x70")) {
            return 'ed25519';
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('Could not read that private key. Paste the PEM file from the Kalshi demo site, without a passphrase.');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA) {
            return 'rsa';
        }

        throw new RuntimeException('That key is not an Ed25519 or RSA private key.');
    }

    public static function sign(string $pem, string $timestamp, string $method, string $path): string
    {
        $path = explode('?', $path, 2)[0];
        $message = $timestamp . strtoupper($method) . $path;

        return base64_encode(self::signMessage($pem, $message));
    }

    public static function signMessage(string $pem, string $message): string
    {
        if (self::detectType($pem) === 'ed25519') {
            $seed = self::ed25519Seed($pem);
            $keypair = sodium_crypto_sign_seed_keypair($seed);
            $secret = sodium_crypto_sign_secretkey($keypair);

            return sodium_crypto_sign_detached($message, $secret);
        }

        return self::rsaPssSign($pem, $message);
    }

    private static function ed25519Seed(string $pem): string
    {
        $der = self::pemBody($pem);
        $marker = "\x04\x20";
        $pos = strrpos($der, $marker);
        if ($pos === false) {
            throw new RuntimeException('Could not find an Ed25519 seed in that PEM.');
        }
        $seed = substr($der, $pos + 2, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        if (strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new RuntimeException('The Ed25519 seed in that PEM is incomplete.');
        }

        return $seed;
    }

    private static function rsaPssSign(string $pem, string $message): string
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('Could not read the RSA private key.');
        }
        $details = openssl_pkey_get_details($key);
        $modBits = (int) ($details['bits'] ?? 0);
        if ($modBits < 2048) {
            throw new RuntimeException('RSA keys must be at least 2048 bits.');
        }

        $modBytes = intdiv($modBits + 7, 8);
        $emBits = $modBits - 1;
        $em = self::emsaPss($message, $emBits, 32);
        $signature = '';
        $ok = openssl_private_encrypt($em, $signature, $key, OPENSSL_NO_PADDING);
        if (!$ok) {
            $errors = [];
            while ($err = openssl_error_string()) {
                $errors[] = $err;
            }
            throw new RuntimeException('RSA-PSS signing failed. ' . implode('; ', $errors));
        }
        if (strlen($signature) < $modBytes) {
            $signature = str_pad($signature, $modBytes, "\0", STR_PAD_LEFT);
        }

        return $signature;
    }

    /**
     * EMSA-PSS from RFC 8017, with SHA-256 and MGF1-SHA-256.
     */
    private static function emsaPss(string $message, int $emBits, int $saltLength): string
    {
        $hashLen = 32;
        $emLen = intdiv($emBits + 7, 8);
        if ($emLen < $hashLen + $saltLength + 2) {
            throw new RuntimeException('RSA key is too short for PSS encoding.');
        }

        $messageHash = hash('sha256', $message, true);
        $salt = random_bytes($saltLength);
        $digest = hash('sha256', str_repeat("\0", 8) . $messageHash . $salt, true);
        $ps = str_repeat("\0", $emLen - $saltLength - $hashLen - 2);
        $db = $ps . "\x01" . $salt;
        $masked = $db ^ self::mgf1($digest, strlen($db));
        $unusedBits = (8 * $emLen) - $emBits;
        if ($unusedBits > 0) {
            $masked[0] = chr(ord($masked[0]) & (0xFF >> $unusedBits));
        }

        return $masked . $digest . "\xbc";
    }

    private static function mgf1(string $seed, int $length): string
    {
        $out = '';
        $counter = 0;
        while (strlen($out) < $length) {
            $out .= hash('sha256', $seed . pack('N', $counter), true);
            $counter++;
        }

        return substr($out, 0, $length);
    }

    private static function pemBody(string $pem): string
    {
        $trimmed = trim($pem);
        if (!str_contains($trimmed, 'BEGIN ') || !str_contains($trimmed, 'END ')) {
            throw new RuntimeException('Paste the private key in PEM format, including the BEGIN and END lines.');
        }
        $body = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $trimmed) ?? '';
        $der = base64_decode($body, true);
        if ($der === false || $der === '') {
            throw new RuntimeException('The private key PEM is not valid base64.');
        }

        return $der;
    }
}
