<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use Closure;

/**
 * One item whose address a permalink run may move: a page, a post, or an item of a kind another
 * module owns (Catalog's products, registered through PermalinkKinds). It carries its slug and name
 * per language and three steps the run needs, each built by the module that owns the item and each
 * going through PathBuilder and RouteSync, so a run composes and moves addresses the one way every
 * save does:
 *   - compose(locale, slug): the path the item would take with this slug (no write);
 *   - sync(locale, slug): move its route there (RouteSync: a 301 from the old address);
 *   - rename(locale, slug): store a new slug for that language (a clash's «-2»); a page's sub-pages
 *     follow its new address when pages nest.
 */
final readonly class PermalinkItem
{
    /**
     * @param  string  $type  what the item is, for a run's summary: page · post · product
     * @param  string  $column  the routes column naming it: entry_id · product_id
     * @param  array<string, string>  $slugs  locale => slug
     * @param  array<string, string>  $titles  locale => name
     * @param  array<string, string>  $routes  locale => the path its route stands at now
     * @param  Closure(string, string): string  $compose
     * @param  Closure(string, string): string  $sync
     * @param  Closure(string, string): void  $rename
     */
    public function __construct(
        public string $type,
        public string $column,
        public int $id,
        public array $slugs,
        public array $titles,
        public array $routes,
        public Closure $compose,
        public Closure $sync,
        public Closure $rename,
    ) {}
}
