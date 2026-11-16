<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The rows under a search box while the visitor types (SiteSuggest finds them), at most
 * SiteSuggest::ROWS: the matched categories, then the products whose code starts with what was typed,
 * then the titles newest first; then put under their group's heading, the groups in the order their
 * first row came (the categories' «Nhóm» first, «Sản phẩm», each level-1 post category as the search
 * page names it, «Khác»). A row is its title, the title cut into pieces marked matched (plain text, for
 * bold), its group and the group's key (fixed words or the category's slug, never an id), an icon name,
 * its address (PathBuilder), the picture's address at THUMB_WIDTH from its storage key (null: no
 * picture, the box draws a tile with the icon) and its meta line: a product's code (the part typed
 * marked), a post's day in the site's format, a category's kind. Never a description.
 * One more read for the category names, and one for the site's day format when a dated row is shown.
 */
final readonly class SearchSuggestions
{
    /** The picture's width asked of the image service (the nearest width it serves, MediaUrlResolver). */
    public const int THUMB_WIDTH = 96;

    /** Results counted for «Xem tất cả N kết quả» at most: one more than shown, so the box says «50+». */
    public const int COUNT_CAP = 51;

    public function __construct(
        private SiteSuggest $suggest,
        private SiteSearch $search,
        private PathBuilder $paths,
        private CurrentSite $current,
        private MediaUrlResolver $media,
        private SiteTime $time,
    ) {}

    /**
     * @return list<array{kind: 'group'|'item'|'sku', label: string, parts: list<array{0: string, 1: bool}>, group: string, group_key: string, icon: string, url: string, thumb: string|null, meta: string, meta_parts?: list<array{0: string, 1: bool}>, sku?: string}>
     */
    public function build(SearchText $q, string $locale): array
    {
        $found = $this->suggest->find($q, $locale);
        $roots = array_values(array_filter(array_unique(array_column($found['entries'], 'root')), static fn (int $id): bool => $id > 0));
        $categories = $this->categories(array_values(array_unique([...$found['categories'], ...$roots])), $locale);

        $rows = [];
        $heading = Cast::string(__('storefront.search.suggest_heading_groups'));
        foreach ($found['categories'] as $id) {
            $category = $categories[$id] ?? null;
            if ($category === null || $category['path'] === null) {
                continue;
            }
            $rows[] = [
                'kind' => 'group',
                'label' => $category['name'],
                'parts' => SearchHighlight::segments($category['name'], $q),
                'group' => $heading,
                'group_key' => 'nhom',
                'icon' => 'folder',
                'url' => $this->paths->publicUrl($category['path'], $locale),
                'thumb' => null,
                'meta' => Cast::string(__($category['vocabulary'] === 'product_cat' ? 'storefront.search.group_products' : 'storefront.search.suggest_kind_posts')),
            ];
        }

        // the product codes that start with what was typed, then every other title newest first
        $compact = SiteSuggest::compact($q->text);
        $products = Cast::string(__('storefront.search.group_products'));
        $titles = [];
        foreach ($found['products'] as $product) {
            $prefix = $compact === '' || $product['sku'] === '' ? null : self::codePrefix($product['sku'], $compact);
            $row = [
                'kind' => $prefix === null ? 'item' : 'sku',
                'label' => $product['title'],
                'parts' => SearchHighlight::segments($product['title'], $q),
                'group' => $products,
                'group_key' => SiteSearch::PRODUCTS,
                'icon' => 'box',
                'url' => $this->paths->publicUrl($product['path'], $locale),
                'thumb' => $this->thumb($product['image']),
                'meta' => $product['sku'] === '' ? '' : Cast::string(__('storefront.search.suggest_sku', ['sku' => $product['sku']])),
            ];
            if ($prefix === null) {
                $titles[] = [$product['at'], $row];

                continue;
            }
            [$before, $after] = self::around(Cast::string(__('storefront.search.suggest_sku')), ':sku');
            $rows[] = $row + [
                'meta_parts' => self::join([[$before, false], [$prefix, true], [mb_substr($product['sku'], mb_strlen($prefix)).$after, false]]),
                'sku' => $product['sku'],
            ];
        }
        foreach ($found['entries'] as $entry) {
            $root = $entry['root'] === 0 ? null : ($categories[$entry['root']] ?? null);
            if ($entry['root'] !== 0 && $root === null) {
                continue;   // a level-1 category with no name in this language: the search page leaves its group out too
            }
            $cards = TemplateCatalog::cards($root['template'] ?? null);
            // the day as the results page shows it: a news list's posts and the posts of no category (a project, a page: none)
            $dated = $root === null ? $entry['type'] === 'post' : $cards === 'news';
            $titles[] = [$entry['at'], [
                'kind' => 'item',
                'label' => $entry['title'],
                'parts' => SearchHighlight::segments($entry['title'], $q),
                'group' => $root === null ? Cast::string(__('storefront.search.group_other')) : $root['name'],
                'group_key' => $root === null ? SiteSearch::OTHER : 'c:'.$root['slug'],
                'icon' => $cards === 'project' ? 'briefcase' : 'doc',
                'url' => $this->paths->publicUrl($entry['path'], $locale),
                'thumb' => $this->thumb($entry['image']),
                'meta' => $dated && $entry['at'] !== null ? $this->time->day(CarbonImmutable::parse($entry['at']), $locale) : '',
            ]];
        }
        // newest first, never-dated last (each list came in that order: a stable sort keeps it on a tie)
        $time = static fn (?string $at): float => $at === null ? -INF : (float) (strtotime($at) ?: 0);
        usort($titles, static fn (array $a, array $b): int => $time($b[0]) <=> $time($a[0]));
        foreach ($titles as [, $row]) {
            $rows[] = $row;
        }

        return self::grouped(array_slice($rows, 0, SiteSuggest::ROWS));
    }

    /**
     * How many results the search page lists for these words, up to COUNT_CAP (SiteSearch: the same
     * rules, one more read for its plan and one for the count). `lower`: at least that many.
     *
     * @return array{count: int, lower: bool}
     */
    public function total(SearchText $q, string $locale): array
    {
        return $this->search->countUpTo($q, $locale, self::COUNT_CAP);
    }

    /**
     * The rows under their headings: each group's rows together, the groups in the order their first
     * row came, the rows of a group in their own order.
     *
     * @template T of array{group_key: string}
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    private static function grouped(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['group_key']][] = $row;
        }

        return array_merge(...array_values($groups));
    }

    /**
     * The picture's address from what a row stores: a storage key (no scheme, has a slash) at
     * THUMB_WIDTH; a full web address as it is (a cover saved before keys were stored); else null.
     */
    private function thumb(string $stored): ?string
    {
        if ($stored === '' || ! str_contains($stored, '/')) {
            return null;
        }
        if (str_contains($stored, '://')) {
            return preg_match('#^https?://#i', $stored) === 1 ? $stored : null;
        }

        return $this->media->url($stored, self::THUMB_WIDTH);
    }

    /**
     * The start of $sku that what was typed covers ($compact: its letters and digits folded), or null
     * when the code does not start with it.
     */
    public static function codePrefix(string $sku, string $compact): ?string
    {
        $need = mb_strlen($compact);
        $seen = '';
        $taken = '';
        foreach (mb_str_split($sku) as $char) {
            $taken .= $char;
            $seen .= (string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', VietnameseFold::text($char));
            if (mb_strlen($seen) >= $need) {
                return str_starts_with($seen, $compact) ? $taken : null;
            }
        }

        return null;
    }

    /**
     * A translation's text before and after its placeholder.
     *
     * @param  non-empty-string  $placeholder
     * @return array{0: string, 1: string}
     */
    private static function around(string $text, string $placeholder): array
    {
        $parts = explode($placeholder, $text, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * The pieces without empty ones, neighbours of the same kind joined.
     *
     * @param  list<array{0: string, 1: bool}>  $pieces
     * @return list<array{0: string, 1: bool}>
     */
    private static function join(array $pieces): array
    {
        $out = [];
        foreach ($pieces as [$text, $matched]) {
            if ($text === '') {
                continue;
            }
            $end = array_key_last($out);
            if ($end !== null && $out[$end][1] === $matched) {
                $out[$end][0] .= $text;
            } else {
                $out[] = [$text, $matched];
            }
        }

        return $out;
    }

    /**
     * The names, slugs, kinds, list layouts and page addresses of these categories in this language, in one read.
     *
     * @param  list<int>  $ids
     * @return array<int, array{name: string, slug: string, vocabulary: string, template: string|null, path: string|null}>
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
            ->select(['t.id', 't.vocabulary', 't.list_template', 'tt.name', 'tt.slug'])
            ->selectSub(DB::table('routes as r')->select('r.path')->where('r.site_id', $site)->where('r.locale', $locale)->whereColumn('r.taxonomy_id', 't.id')->orderBy('r.id')->limit(1), 'path')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $name = Cast::string($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[Cast::int($row['id'] ?? 0)] = [
                'name' => $name,
                'slug' => Cast::string($row['slug'] ?? ''),
                'vocabulary' => Cast::string($row['vocabulary'] ?? ''),
                'template' => Cast::stringOrNull($row['list_template'] ?? null),
                'path' => Cast::stringOrNull($row['path'] ?? null),
            ];
        }

        return $out;
    }
}
