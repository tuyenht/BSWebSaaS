<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Support\Cast;
use App\Modules\Storefront\Support\ListPage;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * What the search page shows for a search (SiteSearch): its groups in order (the products, the level-1
 * post categories in their own order, the rest), each with its name, count, rows and "Xem thêm" link;
 * every row's link, picture, title and short description (with the matched words marked, as plain
 * text pieces) and its group line. Loads the rows shown and nothing else: one read per kind of item
 * and relation, one for the category names. Primitives only, for the theme's search layout.
 *
 * A group is named in the address by a short key ("sp" the products, "khac" the rest) or by its level-1
 * category's slug in the page's language, never by an id.
 */
final readonly class SearchPage
{
    /** Longest short description kept for a row, in characters (the page shows two lines of it). */
    private const int TEXT_MAX = 240;

    public function __construct(
        private SiteSearch $search,
        private ListPage $lists,
        private CurrentSite $current,
    ) {}

    /**
     * @param  string  $base  the search page's address in this language (PathBuilder)
     * @param  string|null  $group  the group asked for ("nhom"), with $after its last row shown ("sau")
     * @return array{total: int, capped: bool, groups: list<array{key: string, anchor: string, name: string, count: int, capped: bool, rows: list<array{url: string, title: list<array{0: string, 1: bool}>, text: list<array{0: string, 1: bool}>, image: string, srcset: string|null, meta: string}>, more: array{url: string, label: string}|null}>, continued: bool}
     */
    public function build(SearchText $q, string $locale, string $base, ?string $group, ?string $after): array
    {
        $continued = false;
        if ($group !== null) {
            $key = $this->groupKey($group, $locale);
            $result = $key === null ? new SearchResult([], true) : $this->search->more($q, $locale, $key, self::ulidOrNull($after));
            $continued = true;
        } else {
            $result = $this->search->overview($q, $locale);
        }

        $productIds = [];
        $entryIds = [];
        $categoryIds = [];
        foreach ($result->groups as $found) {
            foreach ($found->hits as $hit) {
                if ($found->key === SiteSearch::PRODUCTS) {
                    $productIds[] = $hit->id;
                } else {
                    $entryIds[] = $hit->id;
                    if ($hit->primaryCategory !== null) {
                        $categoryIds[] = $hit->primaryCategory;
                    }
                }
            }
            if ($found->rootId() !== null) {
                $categoryIds[] = $found->rootId();
            }
        }
        $categories = $this->categories(array_values(array_unique($categoryIds)), $locale);
        $products = $productIds === [] ? collect() : Product::query()->whereIn('id', $productIds)
            ->with(['translations' => fn (Relation $query) => $query->where('locale', $locale), 'taxonomies.translations', 'images.media'])
            ->get(['id', 'ulid', 'sku', 'site_id'])->keyBy('id');
        $entries = $entryIds === [] ? collect() : Entry::query()->whereIn('id', $entryIds)
            ->with(['translations' => fn (Relation $query) => $query->where('locale', $locale), 'routes' => fn (Relation $query) => $query->where('locale', $locale)])
            ->get(['id', 'ulid', 'type', 'site_id', 'meta', 'published_at', 'parent_id'])->keyBy('id');
        $labels = array_map(static fn (array $category): string => $category['name'], $categories);

        $groups = [];
        $order = [];
        foreach ($result->groups as $found) {
            $root = $found->rootId();
            $rootInfo = $root === null ? null : ($categories[$root] ?? null);
            if ($root !== null && $rootInfo === null) {
                continue;   // a level-1 category with no name in this language: not a group the page can name
            }
            $urlKey = match (true) {
                $found->key === SiteSearch::PRODUCTS => SiteSearch::PRODUCTS,
                $rootInfo !== null => $rootInfo['slug'],
                default => SiteSearch::OTHER,
            };
            $name = match (true) {
                $found->key === SiteSearch::PRODUCTS => Cast::string(__('storefront.search.group_products')),
                $rootInfo !== null => $rootInfo['name'],
                default => Cast::string(__('storefront.search.group_other')),
            };
            $cards = TemplateCatalog::cards($rootInfo['template'] ?? null);

            $rows = [];
            $lastPosition = 0;
            $lastUlid = '';
            foreach ($found->hits as $hit) {
                if ($found->key === SiteSearch::PRODUCTS) {
                    $product = $products->get($hit->id);
                    $card = $product instanceof Product ? ($this->lists->productCards([$product], $locale)[0] ?? null) : null;
                    if ($card === null) {
                        continue;
                    }
                    $sku = trim((string) $product->sku);
                    $meta = implode(' · ', array_filter([$card['label'], $sku === '' ? '' : __('storefront.search.sku', ['sku' => $sku])], static fn (string $part): bool => $part !== ''));
                } else {
                    $entry = $entries->get($hit->id);
                    if (! $entry instanceof Entry) {
                        continue;
                    }
                    $entry->setAttribute('primary_taxonomy_id', $hit->primaryCategory);
                    $card = $this->lists->postCards([$entry], $locale, $labels, true)[0] ?? null;
                    if ($card === null) {
                        continue;
                    }
                    $meta = $this->postMeta($rootInfo === null ? null : $name, $cards, $entry->type, $card['date'], $card['datetime']);
                }
                $rows[] = [
                    'url' => $card['url'],
                    'title' => SearchHighlight::segments($card['title'], $q),
                    'text' => SearchHighlight::segments(self::plain($card['excerpt']), $q),
                    'image' => $card['image'],
                    'srcset' => $card['srcset'],
                    'meta' => $meta,
                ];
                $lastPosition = $hit->position;
                $lastUlid = $hit->ulid;
            }
            if ($rows === []) {
                continue;
            }

            $more = null;
            if ($found->more) {
                $left = $found->capped ? null : max(0, $found->count - $lastPosition);
                $more = [
                    'url' => $base.'?'.http_build_query(['q' => $q->text, 'nhom' => $urlKey, 'sau' => $lastUlid]),
                    'label' => $left === null || $left === 0
                        ? __('storefront.search.more')
                        : ($found->key === SiteSearch::PRODUCTS
                            ? __('storefront.search.more_products', ['count' => $left])
                            : __('storefront.search.more_in', ['count' => $left, 'group' => mb_strtolower($name)])),
                ];
            }
            $order[] = $found->key === SiteSearch::PRODUCTS ? [0, 0, 0] : ($rootInfo !== null ? [1, $rootInfo['position'], (int) $root] : [2, 0, 0]);
            $groups[] = [
                'key' => $urlKey,
                'anchor' => 'nhom-'.$urlKey,
                'name' => $name,
                'count' => $found->count,
                'capped' => $found->capped,
                'rows' => $rows,
                'more' => $more,
            ];
        }
        // the products, then the level-1 categories in their order, then the rest
        $sorted = array_keys($groups);
        usort($sorted, static fn (int $a, int $b): int => $order[$a] <=> $order[$b]);
        $groups = array_map(static fn (int $i): array => $groups[$i], $sorted);

        return [
            'total' => array_sum(array_column($groups, 'count')),
            'capped' => in_array(true, array_column($groups, 'capped'), true),
            'groups' => $groups,
            'continued' => $continued,
        ];
    }

    /**
     * The newest published product code of the site, the no-results page's example (null: none).
     */
    public function exampleSku(): ?string
    {
        $sku = Product::query()->where('status', 'published')->whereNotNull('sku')->where('sku', '<>', '')
            ->orderByDesc('published_at')->orderByDesc('id')->value('sku');

        return is_string($sku) && trim($sku) !== '' ? trim($sku) : null;
    }

    /**
     * A row's group line: its level-1 category, with the day for a news list or the year for a
     * project list; for a post of no category its day; nothing for a page of none.
     */
    private function postMeta(?string $group, ?string $cards, string $type, string $date, string $datetime): string
    {
        $when = match (true) {
            $group === null => $type === 'post' ? $date : '',
            $cards === 'news' => $date,
            $cards === 'project' => substr($datetime, 0, 4),
            default => '',
        };

        return implode(' · ', array_filter([$group ?? '', $when], static fn (string $part): bool => $part !== ''));
    }

    /**
     * The key of SiteSearch for a group named in the address: the products, the rest, or a level-1
     * post category by its slug in this language (one read). Null when no such group exists.
     */
    private function groupKey(string $group, string $locale): ?string
    {
        if ($group === SiteSearch::PRODUCTS || $group === SiteSearch::OTHER) {
            return $group;
        }
        if (preg_match('/^[\p{Ll}\p{Lo}\p{Nd}-]{1,190}$/u', $group) !== 1) {
            return null;
        }
        $site = $this->current->id();
        $id = DB::table('taxonomy_translations as tt')
            ->join('taxonomies as t', fn (JoinClause $join) => $join->on('t.id', '=', 'tt.taxonomy_id')->where('t.site_id', '=', $site))
            ->where('tt.site_id', $site)->where('tt.locale', $locale)->where('tt.slug', $group)
            ->where('t.vocabulary', 'post_cat')->whereNull('t.parent_id')
            ->value('t.id');

        return is_numeric($id) ? 'c'.$id : null;
    }

    /**
     * The names, slugs, order and list layouts of these categories in this language, in one read.
     *
     * @param  list<int>  $ids
     * @return array<int, array{name: string, slug: string, position: int, template: string|null}>
     */
    private function categories(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $site = $this->current->id();
        $rows = DB::table('taxonomies as t')
            ->join('taxonomy_translations as tt', fn (JoinClause $join) => $join->on('tt.taxonomy_id', '=', 't.id')->where('tt.site_id', '=', $site)->where('tt.locale', '=', $locale))
            ->where('t.site_id', $site)->whereIn('t.id', $ids)
            ->get(['t.id', 't.position', 't.list_template', 'tt.name', 'tt.slug']);
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[Cast::int($row['id'] ?? 0)] = [
                'name' => Cast::string($row['name'] ?? ''),
                'slug' => Cast::string($row['slug'] ?? ''),
                'position' => Cast::int($row['position'] ?? 0),
                'template' => Cast::stringOrNull($row['list_template'] ?? null),
            ];
        }

        return $out;
    }

    /** A short description as plain text on one line, cut to TEXT_MAX (a product's is HTML). */
    private static function plain(string $text): string
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::TEXT_MAX ? rtrim(mb_substr($text, 0, self::TEXT_MAX - 1)).'…' : $text;
    }

    /** A ulid from the address, or null (anything else is no cursor). */
    private static function ulidOrNull(?string $value): ?string
    {
        return $value !== null && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $value) === 1 ? $value : null;
    }
}
