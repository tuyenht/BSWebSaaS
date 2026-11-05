<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Menus;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What the Trình đơn screen shows about the page, category or product behind each menu item: its
 * kind, its name and public slug in the site's main language, and its state — live, not yet
 * published ("Trang chưa đăng"), in the trash, or gone ("không còn") — plus, for a category, the
 * languages it has no version in ("Chuyên mục chưa có bản …"). The website hides such an item by
 * itself (MenuResolver); the screen says why.
 *
 * Batched: at most two queries per kind of target (the rows, then their names), whatever the
 * number of items, and none for a kind the menu does not use. Every lookup goes through the site's
 * scope, so a target of another site reads as gone. Never an id in what it returns: the source is
 * named by its ulid and its slug.
 */
final class MenuSources
{
    /**
     * Item id → its source, for the items that link to a page, category or product. kind:
     * page|post|category|product; state: live|unpublished|trashed|gone.
     *
     * @param  Collection<int, MenuItem>  $items
     * @param  list<string>  $locales  the languages the menu is written for
     * @return array<int, array{kind: string, ref: string|null, title: string|null, slug: string|null, state: string, untranslated: list<string>}>
     */
    public function of(Collection $items, array $locales, string $mainLocale): array
    {
        $ids = [];   // link type → target ids
        foreach ($items as $item) {
            if ($item->routable_id !== null && in_array($item->link_type, ['entry', 'taxonomy', 'product'], true)) {
                $ids[$item->link_type][$item->routable_id] = true;
            }
        }

        $found = [];   // link type → [target id → source]
        foreach ($ids as $type => $set) {
            $found[$type] = match ($type) {
                'entry' => $this->entries(array_keys($set), $locales, $mainLocale),
                'taxonomy' => $this->categories(array_keys($set), $locales, $mainLocale),
                default => $this->products(array_keys($set), $mainLocale),
            };
        }

        $out = [];
        foreach ($items as $item) {
            if ($item->routable_id === null || ! isset($ids[$item->link_type])) {
                continue;
            }
            $out[$item->id] = $found[$item->link_type][$item->routable_id] ?? [
                'kind' => $item->link_type === 'taxonomy' ? 'category' : ($item->link_type === 'product' ? 'product' : 'page'),
                'ref' => null, 'title' => null, 'slug' => null, 'state' => 'gone', 'untranslated' => [],
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $locales
     * @return array<int, array{kind: string, ref: string|null, title: string|null, slug: string|null, state: string, untranslated: list<string>}>
     */
    private function entries(array $ids, array $locales, string $mainLocale): array
    {
        $rows = Entry::query()->withTrashed()->whereIn('id', $ids)->get(['id', 'ulid', 'type', 'status', 'deleted_at']);
        $names = $this->names(EntryTranslation::query()->whereIn('entry_id', $ids)->whereIn('locale', $locales)
            ->get(['entry_id', 'locale', 'title', 'slug']), 'entry_id', 'title', $mainLocale);

        $out = [];
        foreach ($rows as $row) {
            $out[$row->id] = [
                'kind' => $row->type,
                'ref' => $row->ulid,
                ...$names[$row->id] ?? ['title' => null, 'slug' => null],
                'state' => $row->deleted_at !== null ? 'trashed' : ($row->status === 'published' ? 'live' : 'unpublished'),
                'untranslated' => [],
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $locales
     * @return array<int, array{kind: string, ref: string|null, title: string|null, slug: string|null, state: string, untranslated: list<string>}>
     */
    private function categories(array $ids, array $locales, string $mainLocale): array
    {
        $rows = Taxonomy::query()->whereIn('id', $ids)->get(['id', 'ulid']);
        $translations = TaxonomyTranslation::query()->whereIn('taxonomy_id', $ids)->whereIn('locale', $locales)->get(['taxonomy_id', 'locale', 'name', 'slug']);
        $names = $this->names($translations, 'taxonomy_id', 'name', $mainLocale);
        $has = [];
        foreach ($translations as $translation) {
            $has[$translation->taxonomy_id][$translation->locale] = true;
        }

        $out = [];
        foreach ($rows as $row) {
            $out[$row->id] = [
                'kind' => 'category',
                'ref' => $row->ulid,
                ...$names[$row->id] ?? ['title' => null, 'slug' => null],
                'state' => 'live',
                'untranslated' => array_values(array_filter($locales, fn (string $locale): bool => ! isset($has[$row->id][$locale]))),
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{kind: string, ref: string|null, title: string|null, slug: string|null, state: string, untranslated: list<string>}>
     */
    private function products(array $ids, string $mainLocale): array
    {
        $rows = Product::query()->withTrashed()->whereIn('id', $ids)->get(['id', 'ulid', 'status', 'deleted_at']);
        $names = $this->names(ProductTranslation::query()->whereIn('product_id', $ids)->where('locale', $mainLocale)
            ->get(['product_id', 'locale', 'name', 'slug']), 'product_id', 'name', $mainLocale);

        $out = [];
        foreach ($rows as $row) {
            $out[$row->id] = [
                'kind' => 'product',
                'ref' => $row->ulid,
                ...$names[$row->id] ?? ['title' => null, 'slug' => null],
                'state' => $row->deleted_at !== null ? 'trashed' : ($row->status === 'published' ? 'live' : 'unpublished'),
                'untranslated' => [],
            ];
        }

        return $out;
    }

    /**
     * The name and slug of each target in the main language, else in the first language it has.
     *
     * @param  iterable<Model>  $translations
     * @return array<int, array{title: string|null, slug: string|null}>
     */
    private function names(iterable $translations, string $owner, string $titleColumn, string $mainLocale): array
    {
        $names = [];
        foreach ($translations as $translation) {
            $id = Cast::int($translation->getAttribute($owner));
            if (isset($names[$id]) && $translation->getAttribute('locale') !== $mainLocale) {
                continue;
            }
            $title = $translation->getAttribute($titleColumn);
            $slug = $translation->getAttribute('slug');
            $names[$id] = ['title' => is_string($title) ? $title : null, 'slug' => is_string($slug) ? $slug : null];
        }

        return $names;
    }
}
