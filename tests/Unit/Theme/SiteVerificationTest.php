<?php

use App\Modules\Theme\Chrome\SiteVerification;

/*
 * The ownership codes Google, Bing and Facebook ask for: only a plain code is ever accepted, a
 * pasted meta tag is reduced to its content value, and what a page prints is rebuilt from codes
 * that pass the check, never taken as stored.
 */

it('takes the content value out of a pasted meta tag and trims plain text', function (string $raw, string $code): void {
    expect(SiteVerification::extract($raw))->toBe($code);
})->with([
    'plain code' => ['  abcDEF1234567890abcdEF-_xyz  ', 'abcDEF1234567890abcdEF-_xyz'],
    'whole tag' => ['<meta name="google-site-verification" content="abcDEF1234567890abcdEF-_xyz" />', 'abcDEF1234567890abcdEF-_xyz'],
    'single quotes and spaces' => ["<meta name='msvalidate.01' content = 'ABCDEF1234567890' >", 'ABCDEF1234567890'],
    'empty' => ['   ', ''],
]);

it('accepts each provider’s own code shape only', function (string $provider, string $token, bool $ok): void {
    expect(SiteVerification::valid($provider, $token))->toBe($ok);
})->with([
    'google valid' => ['google', 'abcDEF1234567890abcdEF-_xyz', true],
    'google too short' => ['google', 'abc123', false],
    'google with space' => ['google', 'abcDEF1234567890abc dEF', false],
    'bing valid (12 characters)' => ['bing', 'ABCDEF123456', true],
    'bing too short' => ['bing', 'ABCDEF12345', false],
    'facebook valid' => ['facebook', 'abcdef1234567890abcdef12', true],
    'facebook with underscore' => ['facebook', 'abcdef1234567890abcdef_2', false],
    'facebook too short' => ['facebook', 'abcdef123', false],
    'unknown provider' => ['yandex', 'abcDEF1234567890abcdEF-_xyz', false],
    'html' => ['google', '"><script>alert(1)</script>aaaaaaaaaaaaaaaaaaaa', false],
    'quote' => ['google', 'abcDEF1234567890abcdEF"x', false],
    'empty' => ['google', '', false],
]);

it('builds the tags in a fixed order from codes that are switched on and well-formed', function (): void {
    $tags = SiteVerification::tags([
        'facebook' => ['on' => true, 'token' => 'abcdef1234567890abcdef12'],
        'google' => ['on' => true, 'token' => 'abcDEF1234567890abcdEF-_xyz'],
        'bing' => ['on' => false, 'token' => 'ABCDEF123456'],
    ]);

    expect($tags)->toBe([
        ['name' => 'google-site-verification', 'content' => 'abcDEF1234567890abcdEF-_xyz'],
        ['name' => 'facebook-domain-verification', 'content' => 'abcdef1234567890abcdef12'],
    ]);
});

it('prints nothing for a missing, malformed or hand-edited setting', function (mixed $stored): void {
    expect(SiteVerification::tags($stored))->toBe([]);
})->with([
    'unset' => [null],
    'not an array' => ['google'],
    'row not an array' => [['google' => 'abcDEF1234567890abcdEF-_xyz']],
    'on is not a boolean' => [['google' => ['on' => 'yes', 'token' => 'abcDEF1234567890abcdEF-_xyz']]],
    'token is html' => [['google' => ['on' => true, 'token' => '"><script>alert(1)</script>aaaaaaaaaaaaaaaaaaaa']]],
    'token is a number' => [['bing' => ['on' => true, 'token' => 123456789012]]],
    'empty token' => [['bing' => ['on' => true, 'token' => '']]],
]);
