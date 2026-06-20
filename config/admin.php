<?php

return [
    // Admin chrome branding — env-sourced so a white-label / rebrand (ADR-12) is a config
    // change, not a code hunt. Consumed by the admin shell (brand mark + tagline).
    'brand' => [
        'name' => env('ADMIN_BRAND_NAME', 'Bảo Sơn'),
        'tagline' => env('ADMIN_BRAND_TAGLINE', 'Giúp bạn thành công'),
        // Legal/company name + site shown in the copyright footer (the name links here).
        'company' => env('ADMIN_BRAND_COMPANY', 'BaoSon Ads'),
        'company_url' => env('ADMIN_BRAND_URL', 'https://baoson.net'),
    ],

    // URL prefix for the whole admin plane (login + dashboard + …). Change via env to
    // rebrand the entry path, e.g. ADMIN_PREFIX=bsadm → /bsadm/login. Route NAMES stay
    // admin.* so route('admin.login') keeps resolving regardless of the prefix.
    'prefix' => env('ADMIN_PREFIX', 'admin'),

    // Dedicated key (separate from APP_KEY) for encrypting stored secrets — SMTP/OAuth
    // credentials in site_settings. AES-256-GCM via SecretCipher; rotating it invalidates
    // all stored secrets by design.
    'encryption_key' => env('SETTINGS_ENCRYPTION_KEY'),

    // The single canonical OAuth redirect URI registered with each provider (Google/MS/
    // Yahoo/Facebook), on the project's MAIN domain — shared by all tenants + the platform.
    // Defaults to https://{PLATFORM_HOST}/oauth/callback when unset. The callback resolves
    // which tenant initiated from signed state, so one URI covers every site.
    'oauth_callback_url' => env('OAUTH_CALLBACK_URL'),

    // Genesis platform super_admin (the Bảo Sơn operator) seeded as the FIRST account.
    // env-sourced so credentials never live in the repo (it is public); with no password
    // set the seeder generates a random one and the operator claims it via password-reset.
    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL', 'admin@baoson.net'),
        'name' => env('SUPER_ADMIN_NAME', 'Bảo Sơn Operator'),
        'password' => env('SUPER_ADMIN_PASSWORD', ''),
    ],
];
