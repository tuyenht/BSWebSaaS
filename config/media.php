<?php

return [

    // Single switch point for media URLs (ADR-17 L0). cloudflare | imgproxy | static.
    // Under Octane, changing this env needs `config:clear` + `octane:reload` (config is boot-cached).
    'driver' => env('MEDIA_DRIVER', 'cloudflare'),

    // Storage disk media is written to (filesystems.php). r2/s3 → presigned direct upload;
    // local → upload proxied through Laravel (dev). The action reads this, not a hardcoded 'r2'.
    'disk' => env('MEDIA_DISK', 'r2'),

    // Content-hash length embedded in the storage key {code}/[{yyyy}/{mm}/]{slug}-{hashN}.{ext}
    // (cache-bust + uniqueness; NOT the dedup hash — that is the full 64-hex media.hash column).
    // Media is public, so anti-enumerate is waived; a collision only extends this length. Never
    // below 4 (MediaKey::MIN_HASH_LENGTH): without a suffix, replacing a file would keep its URL and
    // any cached copy of the old file. Default 4.
    'url_hash_length' => (int) env('MEDIA_URL_HASH_LENGTH', 4),

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
        'source_bucket' => env('MEDIA_SOURCE_BUCKET', 's3://baoson-media'),
    ],

    // Responsive width whitelist (the Worker rejects widths outside this set). Drives srcset (PR-6).
    'widths' => [320, 640, 960, 1280, 1600, 2048],

    // Days a soft-deleted (Thùng rác) master survives before media:purge-trash permanently deletes
    // it (row + R2 object). Manual "empty trash" / "delete permanently" purge immediately regardless.
    'trash_retention_days' => (int) env('MEDIA_TRASH_RETENTION_DAYS', 15),

];
