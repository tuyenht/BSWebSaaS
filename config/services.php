<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Cloudflare Turnstile (public-form anti-spam, §23/R20). Keys are env-only: the public
    // site key renders the widget; the secret verifies tokens server-side and MUST stay out
    // of git. When unset (e.g. local/dev), Turnstile verification is skipped and the form
    // still relies on the honeypot + rate-limit.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],

    // Cloudflare account API token for R2 BUCKET administration (e.g. object-lifecycle rules — the R2
    // S3 credentials only cover objects, not bucket config). Account-scoped, so it stays out of git and
    // is unset in most envs. The R2 account id is derived from the R2 endpoint, not stored here.
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

];
