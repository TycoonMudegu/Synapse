<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Env;
use RuntimeException;

final class Crypto
{
    private static function key(): string
    {
        $key = base64_decode(Env::get('APP_KEY'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'APP_KEY must be a base64-encoded 32-byte key. Run php scripts/key.php.',
            );
        }
        return $key;
    }

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key()));
    }

    public static function decrypt(string $ciphertext): string
    {
        $data = base64_decode($ciphertext, true);
        if ($data === false || strlen($data) < 40) {
            throw new RuntimeException('Encrypted credentials are invalid.');
        }
        $plaintext = sodium_crypto_secretbox_open(
            substr($data, 24),
            substr($data, 0, 24),
            self::key(),
        );
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt credentials with this application key.');
        }
        return $plaintext;
    }
}
