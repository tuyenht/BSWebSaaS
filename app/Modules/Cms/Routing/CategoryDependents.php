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
 * and how to re-sync the public paths of a list of its items. A category rename, move or deletion
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
     * @var array<string, array{pivot: string, column: string, section: string, resync: Closure(list<int>): int}>
     */
    private array $kinds = [];

    /**
     * @param  string  $kind  the item kind, e.g. 'product'
     * @param  string  $pivot  the item ↔ taxonomy pivot table, e.g. 'product_taxonomies'
     * @param  string  $column  the pivot column naming the item, e.g. 'product_id'
     * @param  string  $section  the PermalinkConfig section (and type) that composes the kind's paths
     * @param  Closure(list<int>): int  $resync  re-syncs the public paths of these items; returns routes moved
     */
    public function register(string $kind, string $pivot, string $column, string $section, Closure $resync): void
    {
        // The table and column are interpolated into the re-filing SQL, so only plain identifiers.
        if (preg_match(self::IDENTIFIER, $pivot) !== 1 || preg_match(self::IDENTIFIER, $column) !== 1) {
            throw new InvalidArgumentException("Category dependent '{$kind}' needs a plain table and column name.");
        }

        $this->kinds[$kind] = ['pivot' => $pivot, 'column' => $column, 'section' => $section, 'resync' => $resync];
    }

    /**
     * @return array<string, array{pivot: string, column: string, section: string, resync: Closure(list<int>): int}>
     */
    public function all(): array
    {
        return $this->kinds;
    }
}
