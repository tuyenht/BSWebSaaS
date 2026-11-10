<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Menus;

use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Navigation\MenuItemOptions;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The add panel's search ("Thêm mục"): pages, posts, categories and products of this site whose
 * name in the site's main language contains what the editor typed, ignoring accents and case
 * ("dich vu" finds "Dịch vụ"). With nothing typed it lists the newest ones. Each kind returns at
 * most LIMIT rows (no OFFSET: the panel asks the editor to type more instead of paging), so a
 * search costs one query per kind plus one for "already in this menu", whatever the site's size.
 *
 * Each result: key "<type>:<ulid>" and link {type, ref} (what a draft's `link` takes), the title,
 * the kind (page|post|category|product), whether visitors can open it (published), and whether
 * the menu already links to it (inMenu), and its own short text in that language (summary: the
 * excerpt, description or product summary, else the Google description; plain text, capped like an
 * item description) for "Lấy từ mô tả trang". Never an id. Categories are those of posts and products
 * (tags are not menu targets); items in the trash are left out.
 */
final class MenuSourceSearch
{
    public const int LIMIT = 20;

    /** What the editor may type, in letters. */
    public const int QUERY_MAX = 100;

    public const array KINDS = ['page', 'post', 'category', 'product'];

    /**
     * @return list<array{key: string, link: array{type: string, ref: string}, title: string, kind: string, published: bool, inMenu: bool, summary: string|null}>
     */
    public function search(string $query, ?string $kind, ?int $menuId, string $locale): array
    {
        $needle = VietnameseFold::text(trim(mb_substr(trim($query), 0, self::QUERY_MAX)));
        $like = $needle === '' ? null : VietnameseFold::like($needle);

        $found = [];   // list of [link type, target id, ulid, title, kind, published, summary]
        foreach ($kind === null ? self::KINDS : [$kind] as $wanted) {
            foreach ($this->rows($wanted, $like, $locale) as $row) {
                $found[] = $row;
            }
        }

        $inMenu = [];
        if ($menuId !== null && $found !== []) {
            $byType = [];
            foreach ($found as [$type, $id]) {
                $byType[$type][] = $id;
            }
            $linked = MenuItem::query()->where('menu_id', $menuId)
                ->where(function (Builder $query) use ($byType): void {
                    foreach ($byType as $type => $ids) {
                        $query->orWhere(fn (Builder $branch) => $branch->where('link_type', $type)->whereIn('routable_id', $ids));
                    }
                })
                ->get(['link_type', 'routable_id']);
            foreach ($linked as $item) {
                $inMenu[$item->link_type][(int) $item->routable_id] = true;
            }
        }

        return array_map(fn (array $row): array => [
            'key' => $row[0].':'.$row[2],
            'link' => ['type' => $row[0], 'ref' => $row[2]],
            'title' => $row[3],
            'kind' => $row[4],
            'published' => $row[5],
            'inMenu' => isset($inMenu[$row[0]][$row[1]]),
            'summary' => $row[6],
        ], $found);
    }

    /**
     * @return list<array{0: string, 1: int, 2: string, 3: string, 4: string, 5: bool, 6: string|null}>
     */
    private function rows(string $kind, ?string $like, string $locale): array
    {
        [$query, $title, $type, $published] = match ($kind) {
            'page', 'post' => [
                EntryTranslation::query()->join('entries', 'entries.id', '=', 'entry_translations.entry_id')
                    ->where('entries.type', $kind)->whereNull('entries.deleted_at')
                    ->where('entry_translations.locale', $locale)
                    ->select(['entries.id as target_id', 'entries.ulid as target_ulid', 'entries.status as target_status', 'entry_translations.title as target_title',
                        'entry_translations.excerpt as target_summary', 'entry_translations.seo_description as target_seo']),
                'entry_translations.title', 'entry', 'published',
            ],
            'category' => [
                TaxonomyTranslation::query()->join('taxonomies', 'taxonomies.id', '=', 'taxonomy_translations.taxonomy_id')
                    ->whereIn('taxonomies.vocabulary', ['post_cat', 'product_cat'])->whereNotNull('taxonomies.ulid')
                    ->where('taxonomy_translations.locale', $locale)
                    ->select(['taxonomies.id as target_id', 'taxonomies.ulid as target_ulid', 'taxonomy_translations.name as target_title',
                        'taxonomy_translations.description as target_summary', 'taxonomy_translations.seo_description as target_seo']),
                'taxonomy_translations.name', 'taxonomy', null,
            ],
            default => [
                ProductTranslation::query()->join('products', 'products.id', '=', 'product_translations.product_id')
                    ->whereNull('products.deleted_at')
                    ->where('product_translations.locale', $locale)
                    ->select(['products.id as target_id', 'products.ulid as target_ulid', 'products.status as target_status', 'product_translations.name as target_title',
                        'product_translations.summary as target_summary', 'product_translations.seo_description as target_seo']),
                'product_translations.name', 'product', 'published',
            ],
        };
        /** @var Builder<Model> $query */
        if ($like !== null) {
            $query->whereRaw(VietnameseFold::sql($title).' LIKE ?', [$like]);
        }
        $rows = $query->orderByDesc('target_id')->limit(self::LIMIT)->get();

        $out = [];
        foreach ($rows as $row) {
            $summary = $row->getAttribute('target_summary');
            $seo = $row->getAttribute('target_seo');
            $out[] = [
                $type,
                Cast::int($row->getAttribute('target_id')),
                Cast::string($row->getAttribute('target_ulid')),
                Cast::string($row->getAttribute('target_title')),
                $kind,
                $published === null || $row->getAttribute('target_status') === $published,
                MenuItemOptions::suggestedDescription(is_string($summary) ? $summary : null)
                    ?? MenuItemOptions::suggestedDescription(is_string($seo) ? $seo : null),
            ];
        }

        return $out;
    }
}
