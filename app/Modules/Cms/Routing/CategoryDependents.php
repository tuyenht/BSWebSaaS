<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use Closure;
use InvalidArgumentException;

/**
 * Item kinds owned by a module Cms must not depend on (Catalog's `product`) whose public address
 * can carry their primary category — the `category` / `category_path` permalink presets. The owning
 * module registers the kind at boot: the pivot that files it under categories (the same shape and
 * rules as entry_taxonomies, HasCategoryTaxonomies), which permalink section composes its paths,
 * how to re-sync the public paths of a list of its items, how to mark that their content changed
 * (a re-filing that changes an item's main category changes its breadcrumb), and the item table (its rows carry
 * `site_id` and the soft-delete `deleted_at`, so the category totals, CategoryItemCounts, can count
 * the live ones) and, optionally, its translations table (keyed by the same column): a kind that
 * names one is counted in the categories' public totals (CategoryPublicCounts) — a row is public
 * when its `status` is 'published', in each language it has a translation in. A category rename, move or deletion
 * (Cms\Actions\Taxonomies, via CategoryRelinker) then re-files and re-routes those items exactly
 * like Cms's own entries, in the same transaction, while Cms imports nothing of the module.
 *
 * Holds boot-time closures only, no request state, so a singleton is Octane-safe; a closure
 * resolves what it needs at call time through the container.
 */
final class CategoryDependents
{
    private const string IDENTIFIER = '/\A[a-z][a-z0-9_]*\z/';

    /**
     * @var array<string, array{pivot: string, column: string, section: string, resync: Closure(list<int>): int, items: string, changed: Closure(list<int>): void, translations: string|null}>
     */
    private array $kinds = [];

    /**
     * @param  string  $kind  the item kind, e.g. 'product'
     * @param  string  $pivot  the item ↔ taxonomy pivot table, e.g. 'product_taxonomies'
     * @param  string  $column  the pivot column naming the item, e.g. 'product_id'
     * @param  string  $section  the PermalinkConfig section (and type) that composes the kind's paths
     * @param  Closure(list<int>): int  $resync  re-syncs the public paths of these items; returns routes moved
     * @param  string  $items  the item table, e.g. 'products' (with `site_id` and `deleted_at`)
     * @param  Closure(list<int>): void  $changed  marks that these items' main content changed
     * @param  string|null  $translations  the item's translations table (one row per language, keyed by $column), e.g. 'product_translations'
     */
    public function register(string $kind, string $pivot, string $column, string $section, Closure $resync, string $items, Closure $changed, ?string $translations = null): void
    {
        // The tables and column are interpolated into the re-filing and counting SQL, so only plain identifiers.
        foreach ([$pivot, $column, $items, ...($translations === null ? [] : [$translations])] as $identifier) {
            if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
                throw new InvalidArgumentException("Category dependent '{$kind}' needs plain table and column names.");
            }
        }

        $this->kinds[$kind] = ['pivot' => $pivot, 'column' => $column, 'section' => $section, 'resync' => $resync, 'items' => $items, 'changed' => $changed, 'translations' => $translations];
    }

    /**
     * @return array<string, array{pivot: string, column: string, section: string, resync: Closure(list<int>): int, items: string, changed: Closure(list<int>): void, translations: string|null}>
     */
    public function all(): array
    {
        return $this->kinds;
    }
}
