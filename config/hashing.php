<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Our default is a peppered Argon2id hasher (design.md §29). It HMAC-peppers
    | the secret before Argon2id and supports zero-downtime pepper rotation.
    |
    */

    'driver' => env('HASH_DRIVER', 'peppered-argon'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Pepper (global) + rotation
    |--------------------------------------------------------------------------
    |
    | A server-side secret mixed in BEFORE Argon2id (HMAC). A stolen database
    | without the pepper cannot be brute-forced. NEVER derive it from email/id.
    | Rotate by setting AUTH_PASSWORD_PEPPER to a new secret and moving the old
    | one into AUTH_PASSWORD_PEPPER_PREVIOUS (comma-separated): old hashes keep
    | verifying and are re-hashed on next login.
    |
    */

    'pepper' => env('AUTH_PASSWORD_PEPPER', ''),

    'previous_peppers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AUTH_PASSWORD_PEPPER_PREVIOUS', '')),
    ))),

    'rehash_on_login' => true,

];
