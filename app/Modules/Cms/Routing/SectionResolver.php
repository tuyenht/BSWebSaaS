<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\ContentSections;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * Resolves which permalink SECTION a post belongs to — the (key, type) pair PermalinkConfig is
 * keyed on. A post carries no stored section (there is no content_section_id yet); membership is
 * derived from its PRIMARY category (pivot position 0) matched to a ContentSection's bound
 * `taxonomy_id`, going up the category's ancestors (nearest first) so a post filed under a
 * sub-category still resolves to its section. The ancestors come from the stored ltree path when
 * it agrees with parent_id, else from the parent chain; prime() resolves a whole list at once. Returns null when the post has no category or none binds it — the caller
 * then falls back to the flat default.
 *
 * `key` = ContentSection.key (stable, English, locale-invariant — the same identifier pages use);
 * `type` = the section's content type mapped to the permalink vocabulary (page → pages, else posts).
 *
 * Memoized per-site by primary-category id for the request (negative-cached) so bulk RouteSync of a
 * category's entries costs one section lookup, not one per entry. Registered scoped
 * (CmsServiceProvider) so the memo is flushed between Octane operations and never leaks across
 * requests.
 *
 * This is the single seam for section membership: if a stored `content_section_id` FK later lands,
 * only this class changes — PathBuilder and the config stay put.
 */
final class SectionResolver
{
    /** Hard stop for a parent walk over data a loop could have corrupted — far beyond any real tree. */
    private const int WALK_LIMIT = 32;

    /** @var array<string, array{key: string, type: string}|null> memo per "{siteId}:{primaryTaxonomyId}" */
    private array $memo = [];

    public function __construct(
        private readonly CurrentSite $current,
        private readonly ContentSections $sections,
    ) {}

    /**
     * The (key, type) of the section a post belongs to, or null when none can be derived.
     *
     * @return array{key: string, type: string}|null
     */
    public function forEntry(Entry $entry): ?array
    {
        $primary = $entry->primaryCategory();

        if (! $primary instanceof Taxonomy) {
            return null;
        }

        return $this->forCategory($primary);
    }

    /**
     * The (key, type) of the section a primary category files content under — the same
     * derivation as forEntry, exposed for content that has no Entry row yet (an unsaved post
     * whose picked category already decides which section, and so which workflow, it will join).
     *
     * @return array{key: string, type: string}|null
     */
    public function forCategory(Taxonomy $primary): ?array
    {
        if (! $this->current->hasId()) {
            return $this->resolveMany([$primary->id])[$primary->id] ?? null;
        }

        $key = $this->current->id().':'.$primary->id;

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->resolveMany([$primary->id])[$primary->id] ?? null;
        }

        return $this->memo[$key];
    }

    /**
     * Every section that files posts on the current site, as (key, type) — for a writer that must
     * know whether any post path depends on categories at all before re-syncing posts.
     *
     * @return list<array{key: string, type: string}>
     */
    public function postSections(): array
    {
        return array_map(static fn (string $key): array => ['key' => $key, 'type' => 'posts'], $this->sections->postSectionKeys());
    }

    /**
     * The categories among $taxonomyIds a section is bound to — the root a section files its posts
     * under, which must not disappear from under it.
     *
     * @param  list<int>  $taxonomyIds
     * @return list<int>
     */
    public function boundAmong(array $taxonomyIds): array
    {
        return $this->sections->boundAmong($taxonomyIds);
    }

    /**
     * Drop the memo so the next lookup reflects categories moved earlier in the SAME request — a
     * move changes which section a category's posts resolve to.
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Resolve the section of every primary category among $entries in a few queries for the whole
     * set (forEntry() then answers from the memo): a list showing posts from thirty sub-categories
     * costs what one costs. A no-op outside a site context (there is no memo to fill).
     *
     * @param  iterable<Entry>  $entries
     */
    public function prime(iterable $entries): void
    {
        if (! $this->current->hasId()) {
            return;
        }

        $site = $this->current->id();
        $todo = [];
        foreach ($entries as $entry) {
            $primary = $entry->primaryCategory();
            if ($primary instanceof Taxonomy && ! array_key_exists($site.':'.$primary->id, $this->memo)) {
                $todo[$primary->id] = $primary->id;
            }
        }

        foreach ($this->resolveMany(array_values($todo)) as $id => $section) {
            $this->memo[$site.':'.$id] = $section;
        }
    }

    /**
     * Narrow a query of entries (`entries`) to those sitting on one of the given stages of their own
     * section — the SQL form of "forEntry()['key'] is S and workflow_state is one of S's stages", so
     * a capped list is capped AFTER the filter. A post's section is the nearest category on its
     * primary category's ltree path that a section is built on (the first by display order when
     * several are), as resolveMany() reads it. A post whose primary category has no usable path
     * (older rows) is kept, for the caller's own forEntry() check to decide.
     *
     * @param  Builder<Entry>  $entries
     * @param  array<string, list<string>>  $stages  section key => stage keys
     */
    public function whereOnSectionStage(Builder $entries, array $stages): void
    {
        $bindings = [];
        foreach ($stages as $section => $keys) {
            foreach ($keys as $key) {
                $bindings[] = (string) $section;
                $bindings[] = $key;
            }
        }
        if ($bindings === []) {
            $entries->whereRaw('false');

            return;
        }

        $site = $this->current->id();
        /** @var literal-string $pairs one "(section, stage)" placeholder pair per binding pair */
        $pairs = implode(', ', array_fill(0, intdiv(count($bindings), 2), '(?, ?)'));
        // A path is usable when it is all ids and ends with the category's parent and the category
        // itself (TaxonomyPaths), exactly as resolveMany() trusts it.
        $usable = "coalesce(ltree2text(cat.path) ~ '^[0-9]+(\\.[0-9]+)*$' and ltree2text(subpath(cat.path, -1)) = cat.id::text"
            .' and case when nlevel(cat.path) = 1 then cat.parent_id is null else ltree2text(subpath(cat.path, -2, 1)) = cat.parent_id::text end, false)';
        $ids = 'string_to_array(ltree2text(cat.path), \'.\')::bigint[]';
        $section = "case when {$usable} then (select cs.key from content_sections as cs where cs.site_id = ? and cs.taxonomy_id = any({$ids})"
            ." order by array_position({$ids}, cs.taxonomy_id) desc, cs.sort_order, cs.id limit 1) end";

        $entries->whereExists(fn (QueryBuilder $primary) => $primary->selectRaw('1')
            ->from('entry_taxonomies as et')
            ->join('taxonomies as cat', fn (JoinClause $join) => $join->on('cat.id', '=', 'et.taxonomy_id')->on('cat.site_id', '=', 'et.site_id'))
            ->where('et.site_id', $site)
            ->whereColumn('et.entry_id', 'entries.id')
            ->where('et.position', 0)
            ->whereRaw("(not {$usable} or ({$section}, entries.workflow_state) in ({$pairs}))", [$site, ...$bindings]));
    }

    /**
     * The section of each category in $ids, nearest category first: those a section is built on
     * directly in one query; for the rest, their ancestors read from the stored ltree path (one
     * query for every category, read fresh so a path rewritten earlier in this transaction counts),
     * then the sections built on those ancestors (one more). A category whose path does not match
     * its parent_id (not written yet, or rewritten after it was read) walks its parent chain,
     * one query per level for all such categories together; a visited-set guards against a loop.
     *
     * @param  list<int>  $ids
     * @return array<int, array{key: string, type: string}|null>
     */
    private function resolveMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        $direct = $this->sections->boundToEach($ids);
        $rest = [];
        foreach ($ids as $id) {
            if (isset($direct[$id])) {
                $out[$id] = $this->shape($direct[$id]);
            } else {
                $rest[] = $id;
            }
        }
        if ($rest === []) {
            return $out;
        }

        /** @var array<int, list<int>> $chains category id => its ancestors, nearest first */
        $chains = [];
        $walk = [];
        foreach (Taxonomy::query()->whereKey($rest)->get(['id', 'parent_id', 'path']) as $row) {
            $labels = is_string($row->path) && preg_match('/^\d+(\.\d+)*$/', $row->path) === 1
                ? array_map('intval', explode('.', $row->path)) : [];
            $own = array_pop($labels);
            $parent = $labels === [] ? null : $labels[array_key_last($labels)];
            $parentId = $row->parent_id === null ? null : (int) $row->parent_id;
            if ($own === (int) $row->id && $parent === $parentId) {
                $chains[(int) $row->id] = array_reverse($labels);
            } elseif ($parentId !== null) {
                $walk[(int) $row->id] = $parentId;
            } else {
                $chains[(int) $row->id] = [];
            }
        }
        foreach ($this->walkUp($walk) as $id => $chain) {
            $chains[$id] = $chain;
        }

        $bound = $chains === [] ? [] : $this->sections->boundToEach(array_values(array_unique(array_merge([], ...array_values($chains)))));
        foreach ($rest as $id) {
            $out[$id] = null;
            foreach ($chains[$id] ?? [] as $ancestor) {
                if (isset($bound[$ancestor])) {
                    $out[$id] = $this->shape($bound[$ancestor]);
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The parent chains of several categories at once (category id => its first parent), nearest
     * first, reading one level of every chain per query.
     *
     * @param  array<int, int>  $parents
     * @return array<int, list<int>>
     */
    private function walkUp(array $parents): array
    {
        $chains = [];
        $tips = [];
        foreach ($parents as $id => $parent) {
            $chains[$id] = [$parent];
            $tips[$id] = $parent;
        }

        for ($level = 0; $tips !== [] && $level < self::WALK_LIMIT; $level++) {
            $up = Taxonomy::query()->whereKey(array_values(array_unique($tips)))->pluck('parent_id', 'id')->all();
            foreach ($tips as $id => $tip) {
                $next = isset($up[$tip]) ? Cast::int($up[$tip]) : null;
                if ($next === null || in_array($next, $chains[$id], true) || $next === $id) {
                    unset($tips[$id]);

                    continue;
                }
                $chains[$id][] = $next;
                $tips[$id] = $next;
            }
        }

        return $chains;
    }

    /**
     * @param  array{key: string, entry_type: string|null}  $section
     * @return array{key: string, type: string}
     */
    private function shape(array $section): array
    {
        return ['key' => $section['key'], 'type' => $section['entry_type'] === 'page' ? 'pages' : 'posts'];
    }
}
