<?php

use App\Modules\Admin\Support\SecretCipher;
use Illuminate\Support\Facades\Log;

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

it('returns an empty string for an unparseable payload instead of throwing', function () {
    Log::spy();

    expect(SecretCipher::decrypt('not-a-valid-payload'))->toBe('');

    Log::shouldHaveReceived('warning')->once();
});

it('returns an empty string when the settings key was rotated, never crashing its callers', function () {
    // A secret encrypted under the previous key is undecryptable after rotation — the caller
    // must degrade to "not set" (as the docblock promises), not fatal-error the whole request.
    $cipher = SecretCipher::encrypt('was-encrypted-under-the-old-key');

    config(['admin.encryption_key' => 'base64:'.base64_encode('ffffffffffffffffffffffffffffffff')]);

    expect(SecretCipher::decrypt($cipher))->toBe('');
});
