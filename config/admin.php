<?php

return [
    // Admin chrome branding — env-sourced so a white-label / rebrand (ADR-12) is a config
    // change, not a code hunt. Consumed by the admin shell (brand mark + tagline).
    'brand' => [
        'name' => env('ADMIN_BRAND_NAME', 'Bảo Sơn'),
        'tagline' => env('ADMIN_BRAND_TAGLINE', 'Giúp bạn thành công'),
    ],
];
