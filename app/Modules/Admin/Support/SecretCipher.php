<?php

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Support\Cast;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
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
        // An empty stored secret means "not set" — decrypt to empty instead of throwing on an
        // unparseable payload, so blank/optional credentials never crash their callers.
        if ($payload === '') {
            return '';
        }

        try {
            return self::cipher()->decryptString($payload);
        } catch (DecryptException) {
            // Undecryptable: the settings key was rotated (which invalidates every stored secret
            // by design) or the value is corrupt. Treat it as "not set" so an optional credential
            // degrades gracefully instead of crashing every caller. Never log the payload itself.
            Log::warning('SecretCipher could not decrypt a stored secret; treating it as unset.');

            return '';
        }
    }

    private static function cipher(): Encrypter
    {
        $configured = Cast::string(config('admin.encryption_key'));

        $key = str_starts_with($configured, 'base64:')
            ? (base64_decode(substr($configured, 7), true) ?: '')
            : $configured;

        if (strlen($key) !== 32) {
            throw new RuntimeException('SETTINGS_ENCRYPTION_KEY is missing or not a 32-byte base64: key.');
        }

        return new Encrypter($key, 'aes-256-gcm');
    }
}
