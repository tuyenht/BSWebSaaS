<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * The categories a storefront category list reads: a category and every category below it, leaving
 * out each group below it with a look of its own other than the list's (TemplateCatalog look sets).
 * A job category filed under a news category stores the job list layout: the news list then shows no
 * job offer, while the job category lists its own. A category that stores no layout (or "follow the
 * parent") heads nothing: it belongs to the list above it. The chips, the menus and the admin lists
 * still show the whole tree. (A post's "recent" and "related" read its own category only.)
 *
 * One subquery (no read of its own) over the ltree path, which holds the ids from the root down,
 * the category's own last; every read of taxonomies in it names the site, beside row-level security.
 */
final class LookBranch
{
    /**
     * The ids of $rootId's branch a list of look set $set shows, as a subquery for `taxonomy_id IN (…)`.
     *
     * @return Builder<Taxonomy>
     */
    public static function ids(int $rootId, ?string $set): Builder
    {
        return self::under('?', [$rootId], $set);
    }

    /**
     * @param  literal-string  $root  SQL for the root's id
     * @param  list<int|string>  $bindings  of $root
     * @return Builder<Taxonomy>
     */
    private static function under(string $root, array $bindings, ?string $set): Builder
    {
        // Every read of the table names the site, beside its row-level security (the path probe can
        // then use a site-led index).
        $site = app(CurrentSite::class)->id();
        $path = "(select root.path from taxonomies root where root.id = {$root} and root.site_id = ?)";
        $pathBindings = [...$bindings, $site];
        $ids = Taxonomy::query()
            ->select('taxonomies.id')
            ->where(fn (Builder $ids) => $ids
                ->whereRaw("taxonomies.id = {$root}", $bindings)
                ->orWhereRaw("taxonomies.path <@ {$path}", $pathBindings));
        $outside = TemplateCatalog::listsOutside($set);
        if ($outside === []) {
            return $ids;
        }
        // A group of the root's own stored set is the root's own kind (a project under the projects):
        // never left out, whatever look the reader has.
        /** @var literal-string $marks placeholders only; the names go in the bindings */
        $marks = implode(', ', array_fill(0, count($outside), '?'));
        $otherSet = TemplateCatalog::listSetSql('other.list_template');
        $rootSet = TemplateCatalog::listSetSql('root.list_template');

        return $ids->whereRaw(
            "not exists (select 1 from taxonomies other where other.site_id = ? and other.list_template in ({$marks}) and other.id <> {$root} and other.path <@ {$path}"
                ." and {$otherSet} is distinct from (select {$rootSet} from taxonomies root where root.id = {$root} and root.site_id = ?)"
                .' and taxonomies.path <@ other.path)',
            [$site, ...$outside, ...$bindings, ...$pathBindings, ...$bindings, $site],
        );
    }
}
