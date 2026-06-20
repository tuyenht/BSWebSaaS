<?php

use App\Modules\Admin\Support\SecretCipher;

/*
 * SecretCipher round-trips secrets with the dedicated settings key, and treats an empty
 * payload as "not set" (returns '') so a blank/optional stored secret never throws a
 * DecryptException in its callers (mailer, OAuth).
 */

beforeEach(function () {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
});

it('returns an empty string when decrypting an empty payload', function () {
    expect(SecretCipher::decrypt(''))->toBe('');
});

it('round-trips a secret through encrypt and decrypt', function () {
    $cipher = SecretCipher::encrypt('s3cret-app-password');

    expect($cipher)->not->toBe('s3cret-app-password')
        ->and(SecretCipher::decrypt($cipher))->toBe('s3cret-app-password');
});
