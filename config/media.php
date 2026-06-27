<?php

return [

    // Single switch point for media URLs (ADR-17 L0). cloudflare | imgproxy | static.
    // Under Octane, changing this env needs `config:clear` + `octane:reload` (config is boot-cached).
    'driver' => env('MEDIA_DRIVER', 'cloudflare'),

    // Cloudflare Worker plane (cf.image reads ?w). Also the base for the `static` raw-master driver.
    'cloudflare' => [
        'base' => env('MEDIA_CF_BASE', 'https://files.baoson.net'),
    ],

    // Self-hosted imgproxy failover plane (L4, cold). key/salt are hex; verify the URL format
    // against the imgproxy version when this driver is activated (PR-5).
    'imgproxy' => [
        'base' => env('MEDIA_IMGPROXY_BASE'),
        'key' => env('IMGPROXY_KEY'),
        'salt' => env('IMGPROXY_SALT'),
        'source_bucket' => env('MEDIA_SOURCE_BUCKET', 's3://bswebsaas-media'),
    ],

    // Responsive width whitelist (the Worker rejects widths outside this set). Drives srcset (PR-6).
    'widths' => [320, 640, 960, 1280, 1600, 2048],

];
