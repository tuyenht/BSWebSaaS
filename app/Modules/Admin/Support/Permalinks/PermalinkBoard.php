<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Permalinks;

use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Support\Categories\CategorySections;
use App\Modules\Catalog\Support\ProductRoutes;
use App\Modules\Cms\Actions\Permalinks\ReflowSitePermalinks;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PermalinkItem;
use App\Modules\Cms\Routing\PermalinkKinds;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * What «Đường dẫn» shows (board «Khung quản trị», section «Đường dẫn»): one card per content section
 * — pages, each section that files posts, and products when the website sells through the catalog —
 * with how many published items it holds (counted up to COUNT_CAP, then «10.000+»), the preset it
 * uses and the one staged while a change is being applied, and a few of its items to draw the
 * option examples and the preview from.
 *
 * Nothing is written: an address «after the change» is composed through PathBuilder with the
 * draft preset (PermalinkConfig::withTargets), the way the run will compose it. The preview reads
 * SAMPLE items per section: the board's «Khoảng N» is the section's count times the share of the
 * sample that changes, and a clash is one of the sample's new addresses another item already holds.
 * A fixed number of reads whatever the website's size.
 */
final readonly class PermalinkBoard
{
    /** Counted up to this many; one more reads «10.000+». */
    public const int COUNT_CAP = 10000;

    /** Items per section read to draw examples and the preview. */
    public const int SAMPLE = 20;

    /** Rows of the preview per section. */
    public const int ROWS = 4;

    /** The presets of each type in the board's order, by the board's names. */
    public const array STYLES = [
        'pages' => ['flat', 'nested', 'base'],
        'posts' => ['flat', 'base', 'category', 'category_path'],
        'products' => ['flat', 'base', 'category', 'category_path'],
    ];

    /** Board icons of the pages and products cards; a post section shows its own (the sidebar's). */
    private const array ICONS = ['pages' => 'ri-pages', 'products' => 'ri-box-3'];

    public function __construct(
        private PermalinkConfig $permalinks,
        private CategorySections $categorySections,
        private ReflowSitePermalinks $engine,
        private PermalinkKinds $kinds,
        private ProductRoutes $products,
    ) {}

    /**
     * @return list<array{key: string, type: string, name: string, icon: string, count: int, capped: bool, pattern: string, base: string|null, pending: array{pattern: string, base: string|null}|null, items: list<PermalinkItem>}>
     */
    public function sections(): array
    {
        $stored = $this->permalinks->sections();
        $cards = [['key' => 'pages', 'type' => 'pages', 'name' => __('admin.pl.pages'), 'roots' => []]];
        $postKeys = [];
        foreach ($this->categorySections->postSections() as $section) {
            $cards[] = ['key' => $section['key'], 'type' => 'posts', 'name' => $section['label'], 'roots' => $section['roots']];
            $postKeys[] = $section['key'];
        }
        $productKind = $this->categorySections->find(CategorySections::CATALOG) !== null ? $this->productKind() : null;
        if ($productKind !== null) {
            $cards[] = ['key' => 'products', 'type' => 'products', 'name' => __('admin.pl.products'), 'roots' => []];
        }

        $icons = $postKeys === [] ? [] : ContentSection::query()->whereIn('key', $postKeys)->get(['id', 'key', 'icon'])
            ->mapWithKeys(fn (ContentSection $section): array => [$section->key => AdminNav::sectionIcon($section)])->all();
        $rootPaths = $this->rootPaths(array_merge([], ...array_column($cards, 'roots')));

        // the sample items, entries of every card in one load
        $entryIds = [];
        $counts = [];
        foreach ($cards as $card) {
            if ($card['type'] === 'products') {
                continue;
            }
            $query = $this->entriesOf($card['type'], array_values(array_filter(array_map(fn (int $id): ?string => $rootPaths[$id] ?? null, $card['roots']))));
            $counts[$card['key']] = $this->capped($query);
            $entryIds = [...$entryIds, ...$this->sampleIds($query)];
        }
        $bySection = [];
        foreach ($this->engine->items(ReflowSitePermalinks::ENTRIES, array_values(array_unique($entryIds))) as ['section' => $section, 'item' => $item]) {
            if ($section !== null) {
                $bySection[$section][] = $item;
            }
        }
        if ($productKind !== null) {
            ['count' => $counts['products'], 'ids' => $productIds] = $this->products->permalinkSample(self::COUNT_CAP + 1, self::SAMPLE);
            foreach ($this->engine->items($productKind, $productIds) as ['item' => $item]) {
                $bySection['products'][] = $item;
            }
        }

        $out = [];
        foreach ($cards as $card) {
            $key = $card['key'];
            $count = $counts[$key] ?? 0;
            $row = $stored[$key] ?? null;
            $out[] = [
                'key' => $key,
                'type' => $card['type'],
                'name' => $card['name'],
                'icon' => self::ICONS[$key] ?? ($icons[$key] ?? 'ri-file-text'),
                'count' => min($count, self::COUNT_CAP),
                'capped' => $count > self::COUNT_CAP,
                'pattern' => $this->permalinks->pattern($key, $card['type']),
                'base' => $this->permalinks->base($key),
                'pending' => ($row['pending'] ?? null) === null ? null : ['pattern' => $row['pending']['pattern'], 'base' => $row['pending']['base']],
                'items' => array_slice($bySection[$key] ?? [], 0, self::SAMPLE),
            ];
        }

        return $out;
    }

    /**
     * The address an item of $section would have under a preset, for the option's example: the
     * sample item whose address shows the preset most (the deepest: a page under a parent, a post
     * under a sub-category); null with no sample.
     *
     * @param  list<PermalinkItem>  $items
     */
    public function example(string $section, array $items, string $pattern, ?string $base): ?string
    {
        $best = null;
        foreach ($items as $item) {
            $locale = array_key_first($item->routes);
            if ($locale === null || ! isset($item->slugs[$locale])) {
                continue;
            }
            $slug = trim($item->slugs[$locale], '/');
            $path = $this->permalinks->withTargets([$section => ['pattern' => $pattern, 'base' => $base]], fn (): string => ($item->compose)($locale, $slug));
            // the deepest address shows the format best; the home page ('/') only when nothing else
            if ($best === null || $best === '/' || ($path !== '/' && substr_count($path, '/') > substr_count($best, '/'))) {
                $best = $path;
            }
        }

        return $best;
    }

    /**
     * The preview of the draft choices: for each section whose choice changes, its sample's
     * old → new addresses (a clash marked with the holder of the address), the estimated number of
     * addresses that change, and the totals.
     *
     * @param  list<array{key: string, type: string, name: string, icon: string, count: int, capped: bool, pattern: string, base: string|null, pending: array{pattern: string, base: string|null}|null, items: list<PermalinkItem>}>  $sections
     * @param  array<string, array{pattern: string, base: string|null}>  $drafts  the changed sections
     * @return array{list: list<array{key: string, name: string, from: string, to: string, estimate: int, capped: bool, rows: list<array{old: string, new: string, dup: string|null, holder: string|null}>, more: int}>, changed: int, capped: bool, dups: int}
     */
    public function preview(array $sections, array $drafts): array
    {
        $planned = [];
        foreach ($sections as $section) {
            $draft = $drafts[$section['key']] ?? null;
            if ($draft === null) {
                continue;
            }
            $rows = [];
            $total = 0;
            foreach ($section['items'] as $item) {
                foreach ($item->routes as $locale => $old) {
                    if (! isset($item->slugs[$locale])) {
                        continue;
                    }
                    $total++;
                    $slug = trim($item->slugs[$locale], '/');
                    $new = $this->permalinks->withTargets([$section['key'] => $draft], fn (): string => ($item->compose)($locale, $slug));
                    if ($new !== $old) {
                        $rows[] = ['old' => $old, 'new' => $new, 'locale' => $locale, 'column' => $item->column, 'id' => $item->id];
                    }
                }
            }
            $planned[] = ['section' => $section, 'draft' => $draft, 'rows' => $rows, 'total' => $total];
        }

        $holders = $this->holders(array_merge([], ...array_column($planned, 'rows')));
        $list = [];
        $changed = 0;
        $capped = false;
        $dups = 0;
        foreach ($planned as ['section' => $section, 'draft' => $draft, 'rows' => $rows, 'total' => $total]) {
            $share = $total === 0 ? 0.0 : count($rows) / $total;
            $estimate = (int) round(($section['capped'] ? self::COUNT_CAP : $section['count']) * $share);
            $out = [];
            foreach ($rows as $row) {
                $holder = $holders[$row['locale'].' '.$row['new']] ?? null;
                if ($holder !== null && $holder['column'] === $row['column'] && $holder['id'] === $row['id']) {
                    $holder = null;
                }
                $out[] = ['old' => $row['old'], 'new' => $holder === null ? $row['new'] : $row['new'].'-2', 'dup' => $holder === null ? null : $row['new'], 'holder' => $holder['label'] ?? null];
            }
            $dups += count(array_filter($out, static fn (array $row): bool => $row['dup'] !== null));
            $changed += $estimate;
            $capped = $capped || ($section['capped'] && $estimate > 0);
            $shown = array_slice($out, 0, self::ROWS);
            $list[] = [
                'key' => $section['key'],
                'name' => $section['name'],
                'from' => $section['pattern'],
                'to' => $draft['pattern'],
                'estimate' => $estimate,
                'capped' => $section['capped'] && $estimate > 0,
                'rows' => $shown,
                'more' => max(0, $estimate - count($shown)),
            ];
        }

        return ['list' => $list, 'changed' => $changed, 'capped' => $capped, 'dups' => $dups];
    }

    /** The registered kind whose items sit in the `products` section, null when Catalog registered none. */
    private function productKind(): ?string
    {
        foreach ($this->kinds->all() as $kind => $registered) {
            if ($registered['section'] === 'products') {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Who holds each of these new addresses already (another item's route, or a page of the
     * platform), keyed "{locale} {path}". One read.
     *
     * @param  list<array{old: string, new: string, locale: string, column: string, id: int}>  $rows
     * @return array<string, array{column: string, id: int, label: string}>
     */
    private function holders(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            // two items of the sample heading for one address: the second takes «-2»
            $key = $row['locale'].' '.$row['new'];
            if (isset($seen[$key])) {
                $out[$key] ??= ['column' => '', 'id' => 0, 'label' => $seen[$key]];
            }
            $seen[$key] = $row['column'] === 'entry_id' ? __('admin.pl.holder_content') : __('admin.pl.products');
        }

        $routes = SiteRoute::query()
            ->where(function (Builder $query) use ($rows): void {
                foreach ($rows as $row) {
                    $query->orWhere(fn (Builder $one) => $one->where('locale', $row['locale'])->where('path', $row['new']));
                }
            })
            ->get(['locale', 'path', 'entry_id', 'product_id', 'taxonomy_id']);
        $types = Entry::query()->whereIn('id', $routes->pluck('entry_id')->filter()->all())->pluck('type', 'id')->all();
        foreach ($routes as $route) {
            [$column, $id] = match (true) {
                $route->entry_id !== null => ['entry_id', Cast::int($route->entry_id)],
                $route->product_id !== null => ['product_id', Cast::int($route->product_id)],
                default => ['taxonomy_id', Cast::int($route->taxonomy_id)],
            };
            $label = match ($column) {
                'entry_id' => ($types[$id] ?? null) === 'page' ? __('admin.pl.pages') : __('admin.pl.holder_post'),
                'product_id' => __('admin.pl.products'),
                default => __('admin.pl.holder_category'),
            };
            $out[$route->locale.' '.$route->path] = ['column' => $column, 'id' => $id, 'label' => $label];
        }

        return $out;
    }

    /**
     * The published pages, or the published posts filed (primary category) under one of these
     * category paths.
     *
     * @param  list<string>  $paths
     * @return Builder<Entry>
     */
    private function entriesOf(string $type, array $paths): Builder
    {
        $query = Entry::query()->where('entries.status', 'published')->where('entries.type', $type === 'pages' ? 'page' : 'post');
        if ($type === 'pages') {
            return $query;
        }
        if ($paths === []) {
            return $query->whereRaw('false');
        }

        return $query
            ->join('entry_taxonomies as et', fn (JoinClause $join) => $join->on('et.entry_id', '=', 'entries.id')
                ->on('et.site_id', '=', 'entries.site_id')->where('et.position', 0))
            ->join('taxonomies as t', fn (JoinClause $join) => $join->on('t.id', '=', 'et.taxonomy_id')->on('t.site_id', '=', 'et.site_id'))
            ->whereRaw('t.path <@ ?::ltree[]', ['{'.implode(',', $paths).'}']);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> taxonomy id => its ltree path
     */
    private function rootPaths(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $paths = [];
        foreach (Taxonomy::query()->whereIn('id', array_values(array_unique($ids)))->get(['id', 'path']) as $row) {
            if (is_string($row->path) && preg_match('/^\d+(\.\d+)*$/', $row->path) === 1) {
                $paths[$row->id] = $row->path;
            }
        }

        return $paths;
    }

    /**
     * The rows of $query counted up to one past COUNT_CAP.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function capped(Builder $query): int
    {
        $table = $query->getModel()->getTable();

        return DB::query()->fromSub((clone $query)->select($table.'.id')->limit(self::COUNT_CAP + 1)->toBase(), 'c')->count();
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return list<int>
     */
    private function sampleIds(Builder $query): array
    {
        $table = $query->getModel()->getTable();

        return array_values(array_map(static fn (mixed $id): int => Cast::int($id), (clone $query)->orderBy($table.'.id')->limit(self::SAMPLE)->pluck($table.'.id')->all()));
    }
}
