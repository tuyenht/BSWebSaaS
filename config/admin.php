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
];
