<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The routes of the current site a visitor can actually open, read in (path, id) keyset order
 * for sitemap.xml and its numbered pages.
 *
 * A route is listed only when the storefront would render it — a URL that 404s costs the site
 * crawl budget and shows up as an error in Google Search Console:
 *  - its language is one the site has made public;
 *  - an entry route: the entry is published (soft-deletes respected) AND its translation in the
 *    route's language is published;
 *  - a category route: the category has a translation in the route's language;
 *  - a product route: the product is published (soft-deletes respected) and has a translation in
 *    the route's language. Cms's SiteRoute stays Catalog-agnostic (no product relation), so the
 *    Storefront matches product_id against a Catalog subquery here.
 *
 * Pages of a site over the limit are keyset slices: page n starts at the n-th cursor of a page
 * plan — the (path, id) of every max-th listed route, found by one window-function pass that
 * returns only the boundaries. The plan is cached per site for `cms.sitemap.plan_ttl` seconds,
 * so the whole-site pass runs once per TTL, never per request; nothing busts it on publish, so a
 * page can lag the content by up to the TTL (a sitemap is a crawl hint — the urls themselves
 * stay correct). The cursor ids live only server-side; page numbers are positions, not ids.
 */
final readonly class SitemapRoutes
{
    public function __construct(
        private SiteLocale $locales,
        private CurrentSite $site,
    ) {}

    /** URLs per file: config, clamped to the sitemaps.org ceiling of 50,000. */
    public function maxUrls(): int
    {
        return min(50000, max(1, Cast::int(config('cms.sitemap.max_urls'), 50000)));
    }

    /**
     * Up to $limit listed routes in (path, id) order, from $from (inclusive) up to $to
     * (exclusive) when given. Bounding a page by the next page's cursor keeps a URL from being
     * listed twice when routes before it were removed while the plan was cached.
     *
     * Rows are streamed as plain arrays rather than hydrated models: a 50,000-URL page would
     * otherwise hold 50,000 SiteRoute objects (and their Carbon dates) at once. lastmod still goes
     * through the model's own datetime cast, so the output format is the model's.
     *
     * @param  array{0: string, 1: int}|null  $from
     * @param  array{0: string, 1: int}|null  $to
     * @return list<array{path: string, locale: string, lastmod: string|null}>
     */
    public function slice(?array $from, int $limit, ?array $to = null): array
    {
        $this->withoutJit();

        $rows = $this->listed()
            ->when($from !== null, fn (Builder $query) => $query->whereRaw('(routes.path, routes.id) >= (?, ?)', $from))
            ->when($to !== null, fn (Builder $query) => $query->whereRaw('(routes.path, routes.id) < (?, ?)', $to))
            ->orderBy('routes.path')
            ->orderBy('routes.id')
            ->limit($limit)
            ->select(['routes.path', 'routes.locale', 'routes.updated_at'])
            ->toBase()
            ->cursor();

        $cast = new SiteRoute;
        $slice = [];
        foreach ($rows as $row) {
            $updated = $cast->setRawAttributes(['updated_at' => $row->updated_at])->getAttribute('updated_at');
            $slice[] = [
                'path' => Cast::string($row->path),
                'locale' => Cast::string($row->locale),
                'lastmod' => $updated instanceof CarbonInterface ? $updated->toAtomString() : null,
            ];
        }

        return $slice;
    }

    /**
     * The start cursor of every page, cached per site. A one-entry plan means the site fits in
     * a single file.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function plan(): array
    {
        $plan = $this->cachedPlan();
        if ($plan !== null) {
            return $plan;
        }

        $max = $this->maxUrls();
        $plan = $this->boundaries($max);
        // At least a minute: a TTL of 0 would run the whole-site pass on every page request.
        Cache::put($this->planKey($max), $plan, max(60, Cast::int(config('cms.sitemap.plan_ttl'), 3600)));

        return $plan;
    }

    /**
     * The plan rebuilt now, replacing a cached one — for a cached plan the site has outgrown.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function freshPlan(): array
    {
        Cache::forget($this->planKey($this->maxUrls()));

        return $this->plan();
    }

    /**
     * The cached plan when one exists, without building it. A value of any other shape (a
     * failed write, an older format) counts as no plan, so it is rebuilt instead of reaching SQL.
     *
     * @return list<array{0: string, 1: int}>|null
     */
    public function cachedPlan(): ?array
    {
        $cached = Cache::get($this->planKey($this->maxUrls()));
        if (! is_array($cached) || $cached === []) {
            return null;
        }

        $plan = [];
        foreach ($cached as $cursor) {
            if (! is_array($cursor) || ! is_string($cursor[0] ?? null) || ! is_int($cursor[1] ?? null)) {
                return null;
            }
            $plan[] = [$cursor[0], $cursor[1]];
        }

        return $plan;
    }

    private function planKey(int $max): string
    {
        return 'sitemap-plan:v1:'.$this->site->id().':'.$max;
    }

    /** @return list<array{0: string, 1: int}> */
    private function boundaries(int $max): array
    {
        $this->withoutJit();

        $numbered = $this->listed()
            ->select(['routes.path', 'routes.id'])
            ->selectRaw('row_number() over (order by routes.path, routes.id) as position');

        return array_values(DB::query()
            ->fromSub($numbered->toBase(), 'listed')
            ->whereRaw('(position - 1) % ? = 0', [$max])
            ->orderBy('position')
            ->get(['path', 'id'])
            ->map(fn (object $row): array => [Cast::string($row->path), Cast::int($row->id)])
            ->all());
    }

    /**
     * The listing filter is correlated subqueries over the whole site: on a big site the planner's
     * cost estimate crosses jit_above_cost and JIT compilation alone adds ~0.4 s per query while
     * saving nothing (measured at 120k routes). SET LOCAL ends with the tenant transaction.
     */
    private function withoutJit(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SET LOCAL jit = off');
        }
    }

    /** @return Builder<SiteRoute> */
    private function listed(): Builder
    {
        return SiteRoute::query()
            ->whereIn('locale', $this->locales->public())
            ->where(function (Builder $query): void {
                $query->whereHas('entry', fn (Builder $entry) => $entry
                    ->where('status', 'published')
                    ->whereHas('translations', fn (Builder $translation) => $translation
                        ->whereColumn('entry_translations.locale', 'routes.locale')
                        ->where('entry_translations.status', 'published')))
                    ->orWhereHas('taxonomy', fn (Builder $taxonomy) => $taxonomy
                        ->whereHas('translations', fn (Builder $translation) => $translation
                            ->whereColumn('taxonomy_translations.locale', 'routes.locale')))
                    ->orWhereIn('product_id', Product::query()
                        ->select('products.id')
                        ->where('status', 'published')
                        ->whereHas('translations', fn (Builder $translation) => $translation
                            ->whereColumn('product_translations.locale', 'routes.locale')));
            });
    }
}
