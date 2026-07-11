<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Media\Services\EnsureMediaFromKey;
use App\Modules\Provisioning\Exceptions\DryRunRollback;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time data migration: promotes An Vinh's "product-post" entries (posts filed under the
 * `san-pham` category, seeded by SeedAnVinhContent) into real catalog Product rows, preserving
 * their live URL exactly (no 301) and leaving the source post recoverable in Trash.
 *
 * The URL is preserved by a two-part trick:
 *   1. The `products` permalink section is pinned to the `base` preset with base `san-pham` —
 *      the SAME path a post's `category` preset already composes for a post whose primary
 *      category is san-pham, so a product route and the post route it replaces land on the
 *      identical path.
 *   2. Each post is migrated in its own step: create/update the Product first (so a failure here
 *      leaves the post's route untouched), THEN soft-delete the post and delete its route (this
 *      frees the path), THEN sync the product's route onto that now-free path. Deleting-before-
 *      syncing is required — RouteSync refuses to double-book a path.
 *
 * Each post is migrated inside its own DB::transaction() — a savepoint within whatever top-level
 * transaction the caller's Tenancy::enter() opened (Gate #1 mandates exactly one top-level
 * transaction per tenant-context entry, so "per item" here means failure-isolated, not
 * independently durable: a bad item rolls back to its own savepoint and is reported as failed,
 * without discarding items already processed in the same run). Idempotent: a post already
 * promoted no longer matches the source query (it is trashed), and a Product that already
 * exists at the target slug is updated in place rather than duplicated.
 *
 * `handle(dryRun: true)` runs the exact same code path inside one more transaction that is always
 * rolled back at the end — so the preview is provably identical to what a real run would do, and
 * provably writes nothing.
 *
 * Reversal: a promoted post sits in Trash (soft-deleted, translations intact) rather than being
 * purged, so it is always recoverable. Restoring it to being the live route again — deleting the
 * Product + its route, then Entry::restore() + RouteSync::sync() — is a separate, deliberate
 * operation this action does not perform automatically (it is not needed to make Trash recovery
 * safe, and auto-reversal risks undoing a promotion an operator already approved downstream).
 */
final readonly class PromoteAnVinhProductPostsToCatalog
{
    private const string PRODUCTS_SECTION_KEY = 'products';

    private const string PRODUCTS_BASE_SLUG = 'san-pham';

    public function __construct(
        private RouteSync $routes,
        private PathBuilder $paths,
        private EnsureMediaFromKey $ensureMedia,
        private RichTextSanitizer $sanitizer,
    ) {}

    /**
     * @return list<array{postSlug: string, productUrl: string, action: string, error?: string}>
     */
    public function handle(bool $dryRun = false): array
    {
        return $dryRun ? $this->preview() : $this->promote();
    }

    /**
     * @return list<array{postSlug: string, productUrl: string, action: string, error?: string}>
     */
    private function promote(): array
    {
        $locale = $this->defaultLocale();
        $this->pinProductsPermalinkToBase();

        $taxonomyId = $this->productsTaxonomyId();
        if ($taxonomyId === null) {
            return [];
        }

        $summary = [];
        foreach ($this->sourcePosts($taxonomyId, $locale) as $entry) {
            $summary[] = $this->migrateOne($entry, $locale);
        }

        return $summary;
    }

    /**
     * Runs promote() for real inside a transaction that is unconditionally rolled back, so the
     * preview exercises the identical write path (same pin, same per-post transaction, same
     * failure handling) yet persists nothing. Action labels are relabeled "would_*" afterward —
     * a pure presentation concern, never a second code path.
     *
     * @return list<array{postSlug: string, productUrl: string, action: string, error?: string}>
     */
    private function preview(): array
    {
        $summary = [];

        try {
            DB::transaction(function () use (&$summary): never {
                $summary = $this->promote();

                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            // Expected — every write promote() made above has just been rolled back.
        }

        return array_map(
            static fn (array $row): array => [...$row, 'action' => match ($row['action']) {
                'created' => 'would_create',
                'updated' => 'would_update',
                default => 'would_fail',
            }],
            $summary,
        );
    }

    /**
     * Pin the catalog's permalink section to a fixed base equal to the post category slug the
     * source posts already route through — so a product lands on the exact path its source post
     * had. Idempotent; safe to run whether or not any source posts remain.
     */
    private function pinProductsPermalinkToBase(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'permalinks', 'key' => self::PRODUCTS_SECTION_KEY],
            ['value' => ['pattern' => 'base', 'base' => self::PRODUCTS_BASE_SLUG]],
        );
    }

    /**
     * Bridge a retired post path to the product's new path with a 301 — mirrors the redirect
     * bookkeeping RouteSync does on a slug change (collapse chains onto the new path, record
     * old → new idempotently, and never leave a redirect FROM the live path). Product redirects
     * carry no target attribution, same as RouteSync::syncProduct. No-op when the handover
     * landed on the identical path (the normal An Vinh case).
     */
    private function bridgeRetiredPath(string $locale, string $oldPath, string $newPath): void
    {
        if ($oldPath === $newPath) {
            return;
        }

        Redirect::query()
            ->where('locale', $locale)
            ->where('to_path', $oldPath)
            ->update(['to_path' => $newPath]);

        Redirect::query()->updateOrCreate(
            ['locale' => $locale, 'from_path' => $oldPath],
            ['to_path' => $newPath, 'status_code' => 301],
        );

        Redirect::query()->where('locale', $locale)->where('from_path', $newPath)->delete();
    }

    /** The san-pham taxonomy id, resolved via the `products` ContentSection (not a slug guess). */
    private function productsTaxonomyId(): ?int
    {
        $value = ContentSection::query()->where('key', self::PRODUCTS_SECTION_KEY)->value('taxonomy_id');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Published/draft/archived posts whose PRIMARY category (entry_taxonomies position 0) is the
     * given taxonomy. Eager-loads just what migrateOne() needs (its locale translation + ordered
     * categories) so the loop costs no N+1. A soft-deleted post (already promoted by a prior run)
     * is excluded by Entry's own SoftDeletes scope — the natural idempotency net.
     *
     * @return Collection<int, Entry>
     */
    private function sourcePosts(int $taxonomyId, string $locale): Collection
    {
        return Entry::query()
            ->where('type', 'post')
            ->whereHas('taxonomies', fn ($query) => $query->where('taxonomies.id', $taxonomyId))
            ->with('translations', function (Relation $query) use ($locale): void {
                $query->where('locale', $locale);
            })
            ->with('taxonomies', function (Relation $query): void {
                if ($query instanceof BelongsToMany) {
                    $query->orderByPivot('position');
                }
            })
            ->get()
            ->filter(fn (Entry $entry): bool => $entry->taxonomies->first()?->id === $taxonomyId)
            ->values();
    }

    /**
     * Migrate one post inside its own transaction: create/update the Product, THEN retire the
     * post (soft-delete + drop its route) to free the path, THEN sync the product route onto it.
     * A failure anywhere rolls back just this post's savepoint and is reported, not thrown — one
     * bad post must not block the rest of the run.
     *
     * @return array{postSlug: string, productUrl: string, action: string, error?: string}
     */
    private function migrateOne(Entry $entry, string $locale): array
    {
        $translation = $entry->translationFor($locale);

        if (! $translation instanceof EntryTranslation) {
            return [
                'postSlug' => '(entry #'.$entry->id.')',
                'productUrl' => '',
                'action' => 'failed',
                'error' => "Entry #{$entry->id} has no '{$locale}' translation.",
            ];
        }

        $slug = $translation->slug;
        $intendedUrl = $this->paths->forProduct(null, $slug, $locale);

        try {
            return DB::transaction(function () use ($entry, $translation, $locale, $slug): array {
                $result = $this->upsertProduct($entry, $translation, $locale);

                // The identical-path handover holds only for posts the seeder pinned onto
                // /san-pham/{slug}; capture the retiring routes so any that actually lived
                // elsewhere get bridged with a 301 instead of going dark.
                /** @var \Illuminate\Support\Collection<string, string> $oldPaths locale => path (both NOT NULL) */
                $oldPaths = SiteRoute::query()->where('entry_id', $entry->id)->pluck('path', 'locale');

                $entry->delete();
                SiteRoute::query()->where('entry_id', $entry->id)->delete();

                $path = $this->routes->syncProduct($result['product']->id, null, $locale, $slug);

                foreach ($oldPaths as $oldLocale => $oldPath) {
                    $this->bridgeRetiredPath($oldLocale, $oldPath, $path);
                }

                return [
                    'postSlug' => $slug,
                    'productUrl' => $path,
                    'action' => $result['wasNew'] ? 'created' : 'updated',
                ];
            });
        } catch (Throwable $e) {
            return [
                'postSlug' => $slug,
                'productUrl' => $intendedUrl,
                'action' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create the Product (name/slug/summary/description/status/published_at) or, if one already
     * exists at this locale+slug, update it in place — the idempotency guarantee for a re-run
     * that somehow still sees the source post (the natural exclusion above is the common path;
     * this is the defensive one). Then syncs its category + cover image.
     *
     * @return array{product: Product, wasNew: bool}
     */
    private function upsertProduct(Entry $entry, EntryTranslation $translation, string $locale): array
    {
        $existingTranslation = ProductTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $translation->slug)
            ->first();

        $wasNew = $existingTranslation === null;
        $product = $wasNew ? new Product : Product::query()->findOrFail($existingTranslation->product_id);

        $product->fill([
            'status' => $this->mapStatus($entry->status),
            'published_at' => $entry->published_at,
        ])->save();

        $product->translations()->updateOrCreate(
            ['locale' => $locale],
            [
                'name' => $translation->title,
                'slug' => $translation->slug,
                'summary' => $translation->excerpt !== null ? $this->sanitizer->basic($translation->excerpt) : null,
                'description' => $this->sanitizer->full($this->flattenBodyToHtml($translation->body)),
            ],
        );

        $this->assignProductCategories($product, $entry);
        $this->attachCoverImage($product, $entry);

        return ['product' => $product, 'wasNew' => $wasNew];
    }

    /** products has no 'pending' state (draft|published|archived); treat pending as not-yet-published. */
    private function mapStatus(string $entryStatus): string
    {
        return match ($entryStatus) {
            'published' => 'published',
            'archived' => 'archived',
            default => 'draft',
        };
    }

    /**
     * The product's own category is the post's `product_cat` sub-category (do-ga/khuon-mau/...) —
     * NOT san-pham itself, which is a `post_cat` term for entries and plays no role once routing
     * is pinned to a fixed base. Filtering by vocabulary (not position) is deliberate: it is
     * correct regardless of how many categories the source post carried.
     */
    private function assignProductCategories(Product $product, Entry $entry): void
    {
        /** @var list<int> $categoryIds */
        $categoryIds = $entry->taxonomies->where('vocabulary', 'product_cat')->pluck('id')->values()->all();

        $product->assignCategories($categoryIds);
    }

    /** Materializes the post's featured image (a raw R2 key) into a Media row and sets it as the cover. */
    private function attachCoverImage(Product $product, Entry $entry): void
    {
        $key = $entry->meta['image_url'] ?? null;

        if (! is_string($key) || $key === '') {
            return;
        }

        $media = $this->ensureMedia->handle($key);

        $product->images()->updateOrCreate(['media_id' => $media->id], ['position' => 0]);
    }

    /**
     * Flattens a post's Page-JSON body to plain HTML for the product's `description`, then passed
     * through RichTextSanitizer like every other write to that column. Only `richtext` blocks
     * (heading + paragraphs) are understood — the shape SeedAnVinhContent writes for a product
     * post; any other block type is skipped, mirroring BlockRenderer's fail-safe "unknown block
     * types render as nothing" convention rather than guessing at a shape.
     *
     * @param  array<string, mixed>  $body
     */
    private function flattenBodyToHtml(array $body): string
    {
        $blocks = is_array($body['blocks'] ?? null) ? $body['blocks'] : [];

        $html = '';
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'richtext') {
                continue;
            }

            /** @var array<string, mixed> $props */
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];

            $heading = $props['heading'] ?? null;
            if (is_string($heading) && $heading !== '') {
                $html .= '<h3>'.e($heading).'</h3>';
            }

            $paragraphs = is_array($props['paragraphs'] ?? null) ? $props['paragraphs'] : [];
            foreach ($paragraphs as $paragraph) {
                if (is_string($paragraph) && $paragraph !== '') {
                    $html .= '<p>'.e($paragraph).'</p>';
                }
            }
        }

        return $html;
    }

    private function defaultLocale(): string
    {
        $locale = SiteLocale::query()->where('is_default', true)->value('locale');

        return is_string($locale) ? $locale : 'vi';
    }
}
