<?php

use App\Modules\Identity\Hashing\PepperedArgon2IdHasher;
use Illuminate\Support\Facades\Hash;

/*
 * P0.1 — password hashing invariant (CLAUDE.md §2.6 / design.md §29):
 * Argon2id + global pepper (HMAC pre-hash) + zero-downtime rotation. The pepper is
 * a defense-in-depth layer: a stolen DB without the pepper cannot be brute-forced.
 */

it('round-trips through the Hash facade and is Argon2id underneath', function (): void {
    $hash = Hash::make('correct horse battery staple');

    expect(Hash::check('correct horse battery staple', $hash))->toBeTrue()
        ->and(Hash::check('wrong', $hash))->toBeFalse()
        ->and($hash)->toContain('$argon2id$');
});

it('produces a distinct salted hash on every call', function (): void {
    expect(Hash::make('same-input'))->not->toBe(Hash::make('same-input'));
});

it('binds the secret to the pepper — a different pepper rejects the hash', function (): void {
    $a = new PepperedArgon2IdHasher(['pepper-A'], argonTestOptions());
    $b = new PepperedArgon2IdHasher(['pepper-B'], argonTestOptions());

    $hash = $a->make('secret');

    expect($a->check('secret', $hash))->toBeTrue()
        ->and($b->check('secret', $hash))->toBeFalse();
});

it('verifies a previous-pepper hash and flags it for rehash (rotation)', function (): void {
    $old = new PepperedArgon2IdHasher(['old-pepper'], argonTestOptions());
    $legacy = $old->make('secret');

    // After rotation: current = new-pepper, history keeps old-pepper.
    $rotated = new PepperedArgon2IdHasher(['new-pepper', 'old-pepper'], argonTestOptions());

    expect($rotated->check('secret', $legacy))->toBeTrue()
        ->and($rotated->needsRehash($legacy))->toBeTrue();

    $fresh = $rotated->make('secret');
    expect($rotated->needsRehash($fresh))->toBeFalse();
});

it('fails closed when no pepper is configured', function (): void {
    new PepperedArgon2IdHasher([], argonTestOptions());
})->throws(RuntimeException::class);

/**
 * @return array<string, int>
 */
function argonTestOptions(): array
{
    return ['memory' => 1024, 'time' => 1, 'threads' => 1];
}
