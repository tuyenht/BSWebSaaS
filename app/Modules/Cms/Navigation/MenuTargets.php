<?php

declare(strict_types=1);

namespace App\Modules\Cms\Navigation;

use App\Modules\Cms\Models\SiteRoute;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menu link kinds owned by a module Cms must not depend on — Catalog's `product`. The owning
 * module registers the kind at boot: which `routes` column names its target, how to narrow a
 * routes query to targets a visitor can open, and what an unlabelled item is called. The menu
 * then treats the kind exactly like Cms's own entries and categories — no link to an unpublished
 * or trashed target, an empty label falling back to the target's live name — while Cms imports
 * nothing of the module (the module registers into Cms, never the other way round).
 *
 * Holds boot-time closures only, no request state, so a singleton is Octane-safe.
 */
final class MenuTargets
{
    /**
     * @var array<string, array{column: string, reachable: Closure(Builder<SiteRoute>): mixed, titles: Closure(list<int>, string): array<int, string>}>
     */
    private array $kinds = [];

    /**
     * @param  string  $linkType  menu_items.link_type, which is also the target's morph alias
     * @param  string  $routeColumn  the routes column naming the target, e.g. 'product_id'
     * @param  Closure(Builder<SiteRoute>): mixed  $reachable  narrows a routes query to targets visitors can open
     * @param  Closure(list<int>, string): array<int, string>  $titles  target id → display name in a locale
     */
    public function register(string $linkType, string $routeColumn, Closure $reachable, Closure $titles): void
    {
        $this->kinds[$linkType] = ['column' => $routeColumn, 'reachable' => $reachable, 'titles' => $titles];
    }

    /**
     * @return array<string, array{column: string, reachable: Closure(Builder<SiteRoute>): mixed, titles: Closure(list<int>, string): array<int, string>}>
     */
    public function all(): array
    {
        return $this->kinds;
    }
}
