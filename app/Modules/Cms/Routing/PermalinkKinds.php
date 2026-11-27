<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use Closure;
use InvalidArgumentException;

/**
 * Item kinds owned by a module Cms must not depend on (Catalog's `product`) whose addresses a
 * permalink run moves (ReflowSitePermalinks). The owning module registers the kind at boot: the
 * routes column that names its items, the permalink section that composes their paths, and how to
 * load a list of them as PermalinkItem (only the ones a visitor can open: published), so a change of
 * the section's preset moves them exactly like pages and posts while Cms imports nothing of the
 * module.
 *
 * Holds boot-time closures only, no request state, so a singleton is Octane-safe; a closure
 * resolves what it needs at call time through the container.
 */
final class PermalinkKinds
{
    /** The routes columns a kind may name (the table's own target columns, written into queries). */
    private const array COLUMNS = ['product_id'];

    /** @var array<string, array{column: string, section: string, module: string|null, items: Closure(list<int>): list<PermalinkItem>}> */
    private array $kinds = [];

    /**
     * @param  string  $kind  e.g. 'product'
     * @param  string  $column  the routes column naming its items, e.g. 'product_id'
     * @param  string  $section  the PermalinkConfig section composing its paths, e.g. 'products'
     * @param  Closure(list<int>): list<PermalinkItem>  $items  the published items among these ids
     * @param  string|null  $module  the module a website needs for the section to be offered (Entitlement), null: always
     */
    public function register(string $kind, string $column, string $section, Closure $items, ?string $module = null): void
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("Permalink kind '{$kind}' names an unknown routes column.");
        }

        $this->kinds[$kind] = ['column' => $column, 'section' => $section, 'module' => $module, 'items' => $items];
    }

    /** @return array<string, array{column: string, section: string, module: string|null, items: Closure(list<int>): list<PermalinkItem>}> */
    public function all(): array
    {
        return $this->kinds;
    }
}
