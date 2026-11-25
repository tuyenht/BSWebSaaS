<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * The categories a storefront list or a post's "recent" and "related" read: a category and every
 * category below it, leaving out each group below it with a look of its own other than the list's
 * (TemplateCatalog look sets). «Tuyển dụng» filed under «Tin tức» stores the job list layout: the
 * news list and a news post's side column then show no job offer, while /tuyen-dung lists its own,
 * and a job offer's side column lists other offers. A category that stores no layout (or "follow the
 * parent") heads nothing: it belongs to the list above it. The chips, the menus and the admin lists
 * still show the whole tree.
 *
 * One subquery (no read of its own) over the ltree path, which holds the ids from the root down,
 * the category's own last; the site's row-level security and the model's site scope bound it.
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
     * The ids a post of look set $set in $category reads its neighbours from: the branch of the
     * topmost category, from the post's own up, that heads a group of the set (the job list for a
     * job offer), else of the category's root; the groups of other sets below it left out.
     *
     * @return Builder<Taxonomy>
     */
    public static function forPost(Taxonomy $category, ?string $set): Builder
    {
        // A category whose path was never written is its own root.
        $first = explode('.', (string) $category->path)[0];
        $rootId = ctype_digit($first) ? (int) $first : $category->id;
        $heads = $set === null ? [] : TemplateCatalog::listsOf($set);
        if ($heads === [] || $category->path === null) {
            return self::under('?', [$rootId], $set);
        }
        /** @var literal-string $marks placeholders only; the names go in the bindings */
        $marks = implode(', ', array_fill(0, count($heads), '?'));

        return self::under(
            "coalesce((select head.id from taxonomies head where head.list_template in ({$marks}) and head.path @> ?::ltree order by nlevel(head.path) limit 1), ?)",
            [...$heads, (string) $category->path, $rootId],
            $set,
        );
    }

    /**
     * @param  literal-string  $root  SQL for the root's id
     * @param  list<int|string>  $bindings  of $root
     * @return Builder<Taxonomy>
     */
    private static function under(string $root, array $bindings, ?string $set): Builder
    {
        $path = "(select root.path from taxonomies root where root.id = {$root})";
        $ids = Taxonomy::query()
            ->select('taxonomies.id')
            ->where(fn (Builder $ids) => $ids
                ->whereRaw("taxonomies.id = {$root}", $bindings)
                ->orWhereRaw("taxonomies.path <@ {$path}", $bindings));
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
            "not exists (select 1 from taxonomies other where other.list_template in ({$marks}) and other.id <> {$root} and other.path <@ {$path}"
                ." and {$otherSet} is distinct from (select {$rootSet} from taxonomies root where root.id = {$root})"
                .' and taxonomies.path <@ other.path)',
            [...$outside, ...$bindings, ...$bindings, ...$bindings],
        );
    }
}
