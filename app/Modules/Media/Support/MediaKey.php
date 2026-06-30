<?php

namespace App\Modules\Media\Support;

use App\Modules\Media\Models\Media;
use Illuminate\Support\Str;

/**
 * Builds the opaque content-hash storage key (ADR-17): {tenant}/{collection}/{slug}-{hashN}.{ext}.
 * Media is public, so anti-enumeration is intentionally NOT a goal — the hash suffix is purely for
 * cache-busting (different content → different key → CDN serves fresh) and key uniqueness. It starts
 * short (config('media.url_hash_length'), default 4) and extends only on the rare same-site collision,
 * so URLs stay clean without ever overwriting another object. NOTE this is SEPARATE from the full
 * 64-hex media.hash column used for dedup — shortening this suffix never affects dedup.
 */
final class MediaKey
{
    /** The configured media storage disk (filesystems.php), as a string (config() returns mixed). */
    public static function disk(): string
    {
        $disk = config('media.disk');

        return is_string($disk) ? $disk : 'r2';
    }

    public static function unique(string $siteName, string $collection, string $slug, string $hash, string $ext): string
    {
        $configured = config('media.url_hash_length', 4);
        // 0 = no suffix at all (clean {slug}.{ext}); collisions still extend up from there.
        $start = is_int($configured) ? max(0, $configured) : 4;

        for ($len = $start; $len <= 64; $len++) {
            $key = self::compose($siteName, $collection, $slug, substr($hash, 0, $len), $ext);

            // RLS scopes the lookup to the current site (storage_key is unique within a site).
            if (! Media::query()->where('storage_key', $key)->exists()) {
                return $key;
            }
        }

        // Unreachable in practice (the full 64-hex hash is already unique within the site).
        return self::compose($siteName, $collection, $slug, $hash, $ext);
    }

    /**
     * Compose the key. An empty hash part (length 0) → a clean {slug}.{ext} (no dash). The tenant
     * prefix is dropped in standalone (non-SaaS) mode → {collection}/{slug}.{ext}.
     */
    private static function compose(string $siteName, string $collection, string $slug, string $hashPart, string $ext): string
    {
        $suffix = $hashPart === '' ? '' : "-{$hashPart}";
        $prefix = config('media.tenant_prefix', true) ? Str::slug($siteName).'/' : '';

        return "{$prefix}{$collection}/{$slug}{$suffix}.{$ext}";
    }
}
