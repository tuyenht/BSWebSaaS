<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Provisioning\Exceptions\DryRunRollback;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reflow every opted-in permalink section of the current site back to the flat default and move
 * each affected route to its flat `/{slug}` path — posts via RouteSync::sync, products via
 * RouteSync::syncProduct, so each move records a 301 from the old prefixed path (SEO-safe) and a
 * genuine slug collision is reported, never silently double-booked.
 *
 * Runs inside the caller's Tenancy::enter() (one top-level transaction, RLS-scoped). The permalink
 * settings are rewritten FIRST, then the PermalinkConfig memo is flushed so the re-sync composes
 * from the NEW (flat) pattern rather than the value this process already cached.
 *
 * `handle(dryRun: true)` runs the identical write path inside one more transaction that is always
 * rolled back — so the preview is provably what a real run would do and provably writes nothing.
 * Reversal of a real run is the pre-run DB backup (or a later reflow back to the old preset); this
 * action does not auto-restore.
 */
final readonly class ReflowSitePermalinks
{
    private const string TARGET_PATTERN = 'flat';

    private const string PERMALINK_GROUP = 'permalinks';

    public function __construct(
        private RouteSync $routes,
        private PermalinkConfig $permalinks,
    ) {}

    /**
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string}>
     */
    public function handle(bool $dryRun = false): array
    {
        return $dryRun ? $this->preview() : $this->reflow();
    }

    /**
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string}>
     */
    private function reflow(): array
    {
        $this->flattenPermalinkSettings();
        // The settings above are now flat; drop the cached patterns so RouteSync composes /{slug}.
        $this->permalinks->flush();

        return [...$this->reflowPosts(), ...$this->reflowProducts()];
    }

    /**
     * Rewrite every permalink section that currently opts into a non-flat preset back to flat.
     * Idempotent: a section already flat is skipped, so a re-run writes nothing new.
     */
    private function flattenPermalinkSettings(): void
    {
        $rows = SiteSetting::query()->where('group_name', self::PERMALINK_GROUP)->get();

        foreach ($rows as $row) {
            if (($row->value['pattern'] ?? null) !== self::TARGET_PATTERN) {
                $row->update(['value' => ['pattern' => self::TARGET_PATTERN]]);
            }
        }
    }

    /**
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string}>
     */
    private function reflowPosts(): array
    {
        $summary = [];

        /** @var Collection<int, Entry> $posts */
        $posts = Entry::query()
            ->where('type', 'post')
            ->where('status', 'published')
            ->with(['translations', 'routes', 'taxonomies'])
            ->get();

        foreach ($posts as $post) {
            foreach ($post->routes as $route) {
                $translation = $post->translations->firstWhere('locale', $route->locale);
                if ($translation === null) {
                    continue;
                }

                $slug = trim((string) $translation->slug, '/');
                $summary[] = $this->move('post', $translation->title, $route->locale, (string) $route->path, fn (): string => $this->routes->sync($post, $route->locale, $slug));
            }
        }

        return $summary;
    }

    /**
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string}>
     */
    private function reflowProducts(): array
    {
        $summary = [];

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->where('status', 'published')
            ->with(['translations', 'taxonomies'])
            ->get();

        foreach ($products as $product) {
            $primaryCategory = $product->primaryCategory();

            /** @var Collection<int, SiteRoute> $productRoutes */
            $productRoutes = SiteRoute::query()->where('product_id', $product->id)->get();

            foreach ($productRoutes as $route) {
                $translation = $product->translations->firstWhere('locale', $route->locale);
                if ($translation === null) {
                    continue;
                }

                $slug = trim((string) $translation->slug, '/');
                $summary[] = $this->move('product', $translation->name, $route->locale, (string) $route->path, fn (): string => $this->routes->syncProduct($product->id, $primaryCategory, $route->locale, $slug));
            }
        }

        return $summary;
    }

    /**
     * Run one route move, turning a slug clash into a reported row instead of aborting the run.
     *
     * @param  callable(): string  $sync  performs the move and returns the new path
     * @return array{type: string, title: string, locale: string, old: string, new: string, status: string}
     */
    private function move(string $type, string $title, string $locale, string $old, callable $sync): array
    {
        try {
            $new = $sync();
            $status = $new === $old ? 'unchanged' : 'moved';
        } catch (RouteConflictException) {
            return ['type' => $type, 'title' => $title, 'locale' => $locale, 'old' => $old, 'new' => $old, 'status' => 'conflict'];
        }

        return ['type' => $type, 'title' => $title, 'locale' => $locale, 'old' => $old, 'new' => $new, 'status' => $status];
    }

    /**
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string}>
     */
    private function preview(): array
    {
        $summary = [];

        try {
            DB::transaction(function () use (&$summary): never {
                $summary = $this->reflow();

                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            // Expected — every write reflow() made above has just been rolled back.
        }

        // reflow() flushed the memo and re-read the in-transaction flat patterns; those writes are
        // now rolled back, so flush once more — a later read in this process must see the real
        // (unchanged) settings, not the flat ones this preview briefly cached.
        $this->permalinks->flush();

        return $summary;
    }
}
