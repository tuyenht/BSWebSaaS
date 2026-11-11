<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Shared\Support\EdgeCachePause;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * Turns a write to what a site shows into one purge of its edge-cached pages, after the write
 * commits. Fed by a query listener (StorefrontServiceProvider), so every path is covered: Eloquent
 * saves, query-builder updates and upserts (menus, the category tree, routes) and imports alike.
 * Only writes made inside a tenant context count — the site is the one the context holds — and
 * none made inside EdgeCachePause::during() (the page-view tally changes nothing a visitor sees).
 *
 * Per request or job (scoped): one purge per site and transaction. A rollback forgets what was
 * marked, so a write committed after it still purges; a write rolled back never does.
 */
final class EdgeCachePurges
{
    /** Tables whose rows reach a public page (content, catalogue, menus, settings, theme, addresses). */
    private const array TABLES = [
        'content_sections', 'entries', 'entry_taxonomies', 'entry_translations', 'form_fields', 'forms',
        'media', 'mediables', 'menu_assignments', 'menu_item_translations', 'menu_items', 'menus',
        'product_images', 'product_taxonomies', 'product_translations', 'products', 'redirects', 'routes',
        'site_features', 'site_locales', 'site_modules', 'site_profile_translations', 'site_profiles',
        'site_settings', 'taxonomies', 'taxonomy_translations',
    ];

    /** @var array<int, true> */
    private array $marked = [];

    public function __construct(
        private readonly CurrentSite $current,
        private readonly EdgeCache $edge,
        private readonly EdgeCachePause $pause,
    ) {}

    /**
     * Whether a statement writes a table in TABLES. Runs for every query, so the first character
     * decides most of them. A statement opening with WITH (a recursive rewrite of the category
     * tree, a data-modifying CTE) counts when any write inside it names a listed table. A new
     * upload (an insert into media) shows nowhere until it is placed, and placing it writes a
     * table above, so it purges nothing by itself.
     */
    public static function writesPublicTable(string $sql): bool
    {
        $sql = ltrim($sql);
        $first = $sql[0] ?? '';
        if ($first === 'w' || $first === 'W') {
            if (preg_match('/^with\b/i', $sql) !== 1
                || preg_match_all('/\b(insert\s+into|update|delete\s+from)\s+"?([a-z_]+)"?/i', $sql, $matches, PREG_SET_ORDER) === 0) {
                return false;
            }
            foreach ($matches as $match) {
                if (self::isPublicWrite($match[1], $match[2])) {
                    return true;
                }
            }

            return false;
        }
        if ($first !== 'i' && $first !== 'u' && $first !== 'd' && $first !== 'I' && $first !== 'U' && $first !== 'D') {
            return false;
        }
        if (preg_match('/^(insert\s+into|update|delete\s+from)\s+"?([a-z_]+)"?/i', $sql, $match) !== 1) {
            return false;
        }

        return self::isPublicWrite($match[1], $match[2]);
    }

    private static function isPublicWrite(string $verb, string $table): bool
    {
        $table = strtolower($table);

        return in_array($table, self::TABLES, true) && ! ($table === 'media' && stripos($verb, 'insert') === 0);
    }

    /** A public table was written: purge the current site once this transaction commits. */
    public function written(): void
    {
        if ($this->edge instanceof NullEdgeCache || $this->pause->active() || ! $this->current->hasId()) {
            return;
        }
        $siteId = $this->current->id();
        if (isset($this->marked[$siteId])) {
            return;
        }
        $this->marked[$siteId] = true;

        DB::afterCommit(function () use ($siteId): void {
            unset($this->marked[$siteId]);
            PurgeSiteEdgeCache::dispatch($siteId);
        });
    }

    /** A transaction (or savepoint) rolled back: its after-commit purges are gone, so mark again. */
    public function rolledBack(): void
    {
        $this->marked = [];
    }
}
