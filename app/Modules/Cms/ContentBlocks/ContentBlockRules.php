<?php

declare(strict_types=1);

namespace App\Modules\Cms\ContentBlocks;

use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\Slugger;
use Illuminate\Support\Facades\DB;

/**
 * The shared rules of the block library's write path: what a name and a region may be, the per-site
 * lock that serialises creating and copying blocks (so the cap and the free reference code are
 * decided one at a time), the cap itself, a free reference code, and the block as it is now, read
 * inside the transaction. Runs inside the tenant context; holds no state.
 */
final class ContentBlockRules
{
    /** A suffix ("-200") always fits after a base this long. */
    private const int KEY_BASE_MAX = ContentBlock::KEY_MAX - 4;

    /** The reference code for a name with no usable letters (only symbols). */
    private const string KEY_FALLBACK = 'khoi';

    /** @throws ContentBlockChangeRejectedException */
    public static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > ContentBlock::NAME_MAX) {
            throw new ContentBlockChangeRejectedException('name_invalid');
        }

        return $name;
    }

    /** @throws ContentBlockChangeRejectedException */
    public static function region(string $region): string
    {
        if (! in_array($region, ContentBlock::REGIONS, true)) {
            throw new ContentBlockChangeRejectedException('region_invalid');
        }

        return $region;
    }

    /** One block-library change at a time on this website (held until the transaction ends). */
    public static function lock(int $siteId): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['content_blocks:'.$siteId]);
    }

    /** How many blocks one website may keep. */
    public static function max(): int
    {
        return max(0, Cast::int(config('cms.blocks.max_per_site', 200)));
    }

    /**
     * Refuse a new block when the website already keeps the most it may. Call under lock().
     *
     * @throws ContentBlockChangeRejectedException
     */
    public static function ensureRoom(): void
    {
        if (ContentBlock::query()->count() >= self::max()) {
            throw new ContentBlockChangeRejectedException('library_full');
        }
    }

    /**
     * A reference code free on this website, made from $text: lower-case letters, digits and single
     * dashes, then "-2", "-3"… on a clash. Call under lock().
     *
     * @throws ContentBlockChangeRejectedException
     */
    public static function freeKey(string $text): string
    {
        $base = Slugger::make($text, max: self::KEY_BASE_MAX);
        if ($base === '') {
            $base = self::KEY_FALLBACK;
        }
        $taken = array_map(
            static fn (mixed $key): string => mb_strtolower(Cast::string($key)),
            ContentBlock::query()->where('key', 'like', $base.'%')->pluck('key')->all(),
        );

        $limit = self::max() + 2;
        $key = $base;
        for ($suffix = 2; in_array($key, $taken, true); $suffix++) {
            if ($suffix > $limit) {
                throw new ContentBlockChangeRejectedException('key_exhausted');
            }
            $key = "{$base}-{$suffix}";
        }

        return $key;
    }

    /**
     * The block as it is now, locked for this transaction, and only if it is on this website (RLS
     * and the site scope hide another website's rows).
     *
     * @throws ContentBlockChangeRejectedException
     */
    public static function reload(ContentBlock $block): ContentBlock
    {
        $fresh = ContentBlock::query()->whereKey($block->id)->lockForUpdate()->first();
        if (! $fresh instanceof ContentBlock) {
            throw new ContentBlockChangeRejectedException('block_missing');
        }

        return $fresh;
    }

    /**
     * Refuse a change made from an older state of the block.
     *
     * @throws ContentBlockChangeRejectedException
     */
    public static function ensureVersion(ContentBlock $fresh, string $version): void
    {
        if (! hash_equals(ContentBlockVersion::current($fresh), $version)) {
            throw new ContentBlockChangeRejectedException('version_stale');
        }
    }
}
