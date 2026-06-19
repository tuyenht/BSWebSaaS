<?php

namespace App\Modules\Admin\Support;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Encrypts/decrypts stored secrets (SMTP / OAuth credentials kept in site_settings) with
 * a DEDICATED key (SETTINGS_ENCRYPTION_KEY) that is separate from APP_KEY — AES-256-GCM,
 * authenticated. Only this system, holding that key, can decrypt the values; rotating the
 * key invalidates every stored secret by design.
 */
final class SecretCipher
{
    public static function encrypt(string $plain): string
    {
        return self::cipher()->encryptString($plain);
    }

    public static function decrypt(string $payload): string
    {
        return self::cipher()->decryptString($payload);
    }

    private static function cipher(): Encrypter
    {
        $configured = (string) config('admin.encryption_key', '');

        $key = str_starts_with($configured, 'base64:')
            ? (base64_decode(substr($configured, 7), true) ?: '')
            : $configured;

        if (strlen($key) !== 32) {
            throw new RuntimeException('SETTINGS_ENCRYPTION_KEY is missing or not a 32-byte base64: key.');
        }

        return new Encrypter($key, 'aes-256-gcm');
    }
}
