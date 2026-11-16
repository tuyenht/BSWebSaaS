<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The rows under a search box while the visitor types (SiteSuggest finds them), at most
 * SiteSuggest::ROWS: the matched categories («Nhóm: …» with the kind of category), then the products
 * whose code starts with what was typed («Mã: … · name»), then the titles newest first, each with its
 * group as the search page names it (Sản phẩm, the level-1 post category, Khác). A row is its label,
 * the label cut into pieces marked matched (plain text, for bold), its group, an icon name and its
 * address (PathBuilder). Never a description, never a picture. One more read, for the category names.
 */
final readonly class SearchSuggestions
{
    public function __construct(
        private SiteSuggest $suggest,
        private PathBuilder $paths,
        private CurrentSite $current,
    ) {}

    /**
     * @return list<array{kind: 'group'|'item'|'sku', label: string, parts: list<array{0: string, 1: bool}>, group: string, icon: string, url: string, sku?: string}>
     */
    public function build(SearchText $q, string $locale): array
    {
        $found = $this->suggest->find($q, $locale);
        $roots = array_values(array_filter(array_unique(array_column($found['entries'], 'root')), static fn (int $id): bool => $id > 0));
        $categories = $this->categories(array_values(array_unique([...$found['categories'], ...$roots])), $locale);

        $rows = [];
        foreach ($found['categories'] as $id) {
            $category = $categories[$id] ?? null;
            if ($category === null || $category['path'] === null) {
                continue;
            }
            [$before, $after] = self::around(Cast::string(__('storefront.search.suggest_group')), ':name');
            $rows[] = [
                'kind' => 'group',
                'label' => $before.$category['name'].$after,
                'parts' => self::join([[$before, false], ...SearchHighlight::segments($category['name'], $q), [$after, false]]),
                'group' => Cast::string(__($category['vocabulary'] === 'product_cat' ? 'storefront.search.kind_product_cat' : 'storefront.search.kind_post_cat')),
                'icon' => 'folder',
                'url' => $this->paths->publicUrl($category['path'], $locale),
            ];
        }

        // the product codes that start with what was typed, then every other title newest first
        $compact = SiteSuggest::compact($q->text);
        $titles = [];
        foreach ($found['products'] as $product) {
            $prefix = $compact === '' || $product['sku'] === '' ? null : self::codePrefix($product['sku'], $compact);
            $url = $this->paths->publicUrl($product['path'], $locale);
            $group = Cast::string(__('storefront.search.group_products'));
            if ($prefix === null) {
                $titles[] = [$product['at'], ['kind' => 'item', 'label' => $product['title'], 'parts' => SearchHighlight::segments($product['title'], $q), 'group' => $group, 'icon' => 'box', 'url' => $url]];

                continue;
            }
            [$before, $after] = self::around(Cast::string(__('storefront.search.sku')), ':sku');
            $tail = mb_substr($product['sku'], mb_strlen($prefix)).$after.' · '.$product['title'];
            $rows[] = [
                'kind' => 'sku',
                'label' => $before.$prefix.$tail,
                'parts' => self::join([[$before, false], [$prefix, true], [$tail, false]]),
                'group' => $group,
                'icon' => 'tag',
                'url' => $url,
                'sku' => $product['sku'],
            ];
        }
        foreach ($found['entries'] as $entry) {
            $root = $entry['root'] === 0 ? null : ($categories[$entry['root']] ?? null);
            if ($entry['root'] !== 0 && $root === null) {
                continue;   // a level-1 category with no name in this language: the search page leaves its group out too
            }
            $titles[] = [$entry['at'], [
                'kind' => 'item',
                'label' => $entry['title'],
                'parts' => SearchHighlight::segments($entry['title'], $q),
                'group' => $root === null ? Cast::string(__('storefront.search.group_other')) : $root['name'],
                'icon' => $root !== null && TemplateCatalog::cards($root['template']) === 'project' ? 'briefcase' : 'doc',
                'url' => $this->paths->publicUrl($entry['path'], $locale),
            ]];
        }
        // newest first, never-dated last (each list came in that order: a stable sort keeps it on a tie)
        $time = static fn (?string $at): float => $at === null ? -INF : (float) (strtotime($at) ?: 0);
        usort($titles, static fn (array $a, array $b): int => $time($b[0]) <=> $time($a[0]));
        foreach ($titles as [, $row]) {
            $rows[] = $row;
        }

        return array_slice($rows, 0, SiteSuggest::ROWS);
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
     * The names, kinds, list layouts and page addresses of these categories in this language, in one read.
     *
     * @param  list<int>  $ids
     * @return array<int, array{name: string, vocabulary: string, template: string|null, path: string|null}>
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
            ->select(['t.id', 't.vocabulary', 't.list_template', 'tt.name'])
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
                'vocabulary' => Cast::string($row['vocabulary'] ?? ''),
                'template' => Cast::stringOrNull($row['list_template'] ?? null),
                'path' => Cast::stringOrNull($row['path'] ?? null),
            ];
        }

        return $out;
    }
}
