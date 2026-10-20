<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
     * Up to $limit listed routes in (path, id) order, starting at $from (inclusive) when given.
     *
     * @param  array{0: string, 1: int}|null  $from
     * @return Collection<int, SiteRoute>
     */
    public function slice(?array $from, int $limit): Collection
    {
        return $this->listed()
            ->when($from !== null, fn (Builder $query) => $query->whereRaw('(path, id) >= (?, ?)', $from))
            ->orderBy('path')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'path', 'locale', 'updated_at']);
    }

    /**
     * The start cursor of every page, cached per site. A one-entry plan means the site fits in
     * a single file.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function plan(): array
    {
        $max = $this->maxUrls();

        /** @var list<array{0: string, 1: int}> */
        return Cache::remember(
            'sitemap-plan:v1:'.$this->site->id().':'.$max,
            Cast::int(config('cms.sitemap.plan_ttl'), 3600),
            fn (): array => $this->boundaries($max),
        );
    }

    /**
     * The cached plan when one exists, without building it.
     *
     * @return list<array{0: string, 1: int}>|null
     */
    public function cachedPlan(): ?array
    {
        /** @var list<array{0: string, 1: int}>|null */
        return Cache::get('sitemap-plan:v1:'.$this->site->id().':'.$this->maxUrls());
    }

    /** @return list<array{0: string, 1: int}> */
    private function boundaries(int $max): array
    {
        $numbered = $this->listed()
            ->select(['path', 'id'])
            ->selectRaw('row_number() over (order by path, id) as position');

        return array_values(DB::query()
            ->fromSub($numbered->toBase(), 'listed')
            ->whereRaw('(position - 1) % ? = 0', [$max])
            ->orderBy('position')
            ->get(['path', 'id'])
            ->map(fn (object $row): array => [Cast::string($row->path), Cast::int($row->id)])
            ->all());
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
