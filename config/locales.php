<?php

return [
    // Native display labels for known locale codes. A code without an entry falls back
    // to the intl display name (or the upper-cased code). Drop a lang/{code}.json file
    // and the switcher picks the language up automatically — no code change needed.
    'labels' => [
        'en' => 'English',
        'vi' => 'Tiếng Việt',
        'ja' => '日本語',
        'zh' => '中文',
        'es' => 'Español',
        'fr' => 'Français',
        'pt' => 'Português',
        'ru' => 'Русский',
        'ar' => 'العربية',
        'hi' => 'हिन्दी',
        'bn' => 'বাংলা',
        'ko' => '한국어',
        'de' => 'Deutsch',
        'id' => 'Bahasa Indonesia',
        'it' => 'Italiano',
        'th' => 'ไทย',
    ],

    // Preferred order in the switcher. Discovered codes not listed here are appended
    // alphabetically afterwards.
    'order' => ['en', 'vi', 'ja', 'zh', 'es', 'fr', 'pt', 'ru', 'ar', 'hi', 'bn'],
];
