<?php

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Support\Cast;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The posts and pages list read in SQL: the status group, the section, the search, every filter and
 * every order run in the database, and only the rows of one page come back.
 *
 * - Every query keeps the explicit site_id of the BelongsToSite scope and runs in the request's
 *   tenancy transaction (RLS as app_user), as every other read of the screen does.
 * - The type and the soft-delete test are written as literals, so the partial indexes of the list
 *   (`WHERE deleted_at IS NULL AND type = 'post'`) match a generic plan too: PgBouncer reuses
 *   prepared statements, and a bound type could not prove the index predicate.
 * - Counts are bounded: they stop at CAP + 1 rows, so "more than 10,000" costs at most 10,001 rows,
 *   whatever the size of the website.
 * - Orders: see ListGroup. A run's sort value may be NULL (an edit or publish date): newest first
 *   puts those rows last, oldest first puts them first, and a page cursor walks the NULL rows as a
 *   run of their own, so none is skipped or repeated.
 */
final class EntryListQuery
{
    /** Counts stop past this many rows ("hơn 10.000"); numbered pages reach this far into a list. */
    public const int CAP = 10_000;

    /** What a row of the list reads (no meta, no body). */
    public const array COLUMNS = ['id', 'ulid', 'site_id', 'type', 'status', 'parent_id', 'template_override', 'author_id',
        'published_at', 'position', 'created_at', 'updated_at', 'deleted_at', 'view_count'];

    /** The status order (A to Z): live first, then waiting, then not shown. */
    private const array RANK = ['published', 'scheduled', 'pending', 'draft', 'private', 'archived'];

    /** The collation names sort by (Vietnamese alphabet, case and accents in their places). */
    private const string NAME_COLLATION = '"vi-VN-x-icu"';

    /** @var 'page'|'post' */
    private readonly string $type;

    /** @var array<string, int>|null the authors' ids by ulid, once read (see knowAuthors()) */
    private ?array $authorIds = null;

    /** While facets() builds its statement: the section's posts are read from its `sec` set. */
    private bool $sectionSet = false;

    /** @param  Closure(Builder<Entry>): void  $select  adds the row columns to a query */
    public function __construct(
        private readonly EntryListFilter $filter,
        private readonly int $siteId,
        private readonly Closure $select,
    ) {
        $this->type = $filter->type === 'post' ? 'post' : 'page';
    }

    /**
     * The status group, the section and the search: what the facets count over.
     *
     * @return Builder<Entry>
     */
    public function base(): Builder
    {
        $query = $this->scoped();
        $this->whereTab($query, $this->filter->tab);
        $this->whereSearch($query);

        return $query;
    }

    /**
     * base() and every filter: the rows the list shows.
     *
     * @return Builder<Entry>
     */
    public function filtered(): Builder
    {
        $query = $this->base();
        $this->whereFilters($query);

        return $query;
    }

    /**
     * The type and the section, live rows (the tab counts start here).
     *
     * @return Builder<Entry>
     */
    public function scoped(): Builder
    {
        $query = Entry::query()->whereRaw("entries.type = '{$this->type}'");
        $taxonomyId = $this->filter->sectionTaxonomyId;
        if ($taxonomyId !== null && $this->sectionSet) {
            $query->whereIn('entries.id', fn (QueryBuilder $set) => $set->select('sec.entry_id')->from('sec'));
        } elseif ($taxonomyId !== null) {
            // the section's posts: those whose PRIMARY category (pivot position 0) is the section's
            $query->whereExists(fn (QueryBuilder $primary) => $primary->selectRaw('1')->from('entry_taxonomies')
                ->where('entry_taxonomies.site_id', $this->siteId)
                ->whereColumn('entry_taxonomies.entry_id', 'entries.id')
                ->where('entry_taxonomies.taxonomy_id', $taxonomyId)
                ->where('entry_taxonomies.position', 0));
        }

        return $query;
    }

    /** @param  Builder<Entry>  $query */
    private function whereTab(Builder $query, string $tab): void
    {
        if ($tab === 'trash') {
            $query->onlyTrashed();
        } elseif (in_array($tab, EntryListFilter::STATUS_TABS, true)) {
            $query->where('entries.status', $tab);
        }
    }

    /**
     * The search words in a title or slug of any language, as the list has always matched them
     * (case-insensitive, anywhere in the text). No index can serve it under RLS (ILIKE is not
     * leakproof), so the list walks its rows in the order shown and stops once the page is full:
     * `offset 0` keeps the test a probe per row (Postgres would otherwise turn it into a join that
     * reads every title of the website first, whatever the page needs).
     *
     * @param  Builder<Entry>  $query
     */
    private function whereSearch(Builder $query): void
    {
        $term = trim($this->filter->search);
        if ($term === '') {
            return;
        }

        $query->whereExists(fn (QueryBuilder $text) => $text->selectRaw('1')->from('entry_translations as st')
            ->where('st.site_id', $this->siteId)
            ->whereColumn('st.entry_id', 'entries.id')
            ->where(fn (QueryBuilder $either) => $either->where('st.title', 'ilike', "%{$term}%")->orWhere('st.slug', 'ilike', "%{$term}%"))
            ->offset(0));
    }

    /**
     * OR within each group, AND across groups.
     *
     * @param  Builder<Entry>  $query
     */
    private function whereFilters(Builder $query): void
    {
        $f = $this->filter;

        if ($f->trans !== []) {
            $this->whereTransStates($query, $f->trans);
        }

        if ($f->from !== null || $f->until !== null) {
            // by publish date: a draft has none, so a time span lists only published or scheduled posts
            $query->whereNotNull('entries.published_at');
            if ($f->from !== null) {
                $query->where('entries.published_at', '>=', $f->from);
            }
            if ($f->until !== null) {
                $query->where('entries.published_at', '<', $f->until);
            }
        }

        if ($f->langs !== []) {
            $query->whereExists(fn (QueryBuilder $text) => $text->selectRaw('1')->from('entry_translations as lt')
                ->where('lt.site_id', $this->siteId)
                ->whereColumn('lt.entry_id', 'entries.id')
                ->whereIn('lt.locale', $f->langs));
        }

        if ($f->creators !== []) {
            if ($this->authorIds !== null) {
                // one author is an equality the author index walks in order; a ulid nobody wrote with matches nothing
                $ids = array_values(array_intersect_key($this->authorIds, array_flip($f->creators)));
                $ids === [] ? $query->whereRaw('false') : $query->whereIn('entries.author_id', $ids);
            } else {
                $query->whereIn('entries.author_id', fn (QueryBuilder $people) => $people->select('users.id')->from('users')->whereIn('users.ulid', $f->creators));
            }
        }

        if ($f->templates !== []) {
            $named = array_values(array_diff($f->templates, [EntryListFilter::TEMPLATE_NONE]));
            $none = in_array(EntryListFilter::TEMPLATE_NONE, $f->templates, true);
            $query->where(function (Builder $template) use ($named, $none): void {
                if ($named !== []) {
                    $template->whereIn('entries.template_override', $named);
                }
                if ($none) {
                    $template->orWhereNull('entries.template_override');
                }
            });
        }
    }

    /**
     * A record's translation state among the site's languages: every one (full), some (partial),
     * only one or none (none) — counted in the database, per row.
     *
     * @param  Builder<Entry>  $query
     * @param  list<string>  $states
     */
    private function whereTransStates(Builder $query, array $states): void
    {
        $n = count($this->filter->locales);
        $allowed = [];
        foreach ($states as $state) {
            $allowed = [...$allowed, ...match ($state) {
                'full' => [$n],
                'none' => [0, 1],
                default => $n > 2 ? range(2, $n - 1) : [],
            }];
        }
        if ($allowed === []) {
            $query->whereRaw('false');

            return;
        }

        $have = '(select count(*) from entry_translations tr where tr.site_id = ? and tr.entry_id = entries.id and tr.locale = any(?::text[]))';
        $query->whereRaw("least({$have}, ?) = any(?::int[])", [$this->siteId, self::pgArray($this->filter->locales), $n, self::pgArray(array_unique($allowed))]);
    }

    /**
     * The runs of an order (see ListGroup), first to last.
     *
     * @param  'asc'|'desc'  $dir
     * @param  list<array{id: int, ulid: string, name: string, count: int}>  $authors
     * @return list<ListGroup>
     */
    public function groups(string $key, string $dir, array $authors = []): array
    {
        // a run of a status or an author reads its most recently edited rows first when the runs go A to Z
        $inner = $dir === 'asc' ? 'desc' : 'asc';
        $edited = fn (string $runKey, Closure $where): ListGroup => new ListGroup($runKey, $where, 'entries.updated_at', 'entries.updated_at', 'timestamptz', true, $inner);

        $groups = match ($key) {
            'pub' => [new ListGroup('', null, 'entries.published_at', 'entries.published_at', 'timestamptz', true, $dir)],
            'views' => [new ListGroup('', null, 'entries.view_count', 'entries.view_count', 'bigint', false, $dir)],
            'order' => [new ListGroup('', null, 'entries.position', 'entries.position', 'integer', false, $dir)],
            'status' => array_map(fn (string $status): ListGroup => $edited($status, fn (Builder $q) => $q->where('entries.status', $status)), self::RANK),
            'creator' => $this->authorGroups($authors, $edited),
            // a row with no title in the default language is listed after the titled ones, either way
            'name' => [
                new ListGroup('titled', null, 'nt.title collate '.self::NAME_COLLATION, 'nt.title', 'text', false, $dir, joinTitle: true, tie: 'nt.entry_id'),
                new ListGroup('untitled', fn (Builder $q) => $q->whereNotExists(fn (QueryBuilder $title) => $title->selectRaw('1')->from('entry_translations as nx')
                    ->where('nx.site_id', $this->siteId)->whereColumn('nx.entry_id', 'entries.id')->where('nx.locale', $this->filter->defaultLocale)),
                    null, null, 'text', false, $dir, rare: true),
            ],
            default => [new ListGroup('', null, 'entries.updated_at', 'entries.updated_at', 'timestamptz', true, $dir)],
        };

        // grouped orders list their runs A to Z; the other direction reads them backwards
        return in_array($key, ['status', 'creator'], true) && $dir === 'desc' ? array_reverse($groups) : $groups;
    }

    /**
     * The top of the page tree, by position: pages with no parent, then (either way) pages whose
     * parent is in the Trash (they stand at the top, never under a parent that is not shown).
     *
     * @param  'asc'|'desc'  $dir
     * @return list<ListGroup>
     */
    public function treeTopGroups(string $dir): array
    {
        return [
            new ListGroup('root', fn (Builder $q) => $q->whereNull('entries.parent_id'), 'entries.position', 'entries.position', 'integer', false, $dir),
            new ListGroup('orphan', fn (Builder $q) => $this->whereOrphan($q), 'entries.position', 'entries.position', 'integer', false, $dir, rare: true),
        ];
    }

    /** @param  Builder<Entry>  $query */
    private function whereOrphan(Builder $query): void
    {
        $query->whereNotNull('entries.parent_id')->whereNotExists(fn (QueryBuilder $parent) => $parent->selectRaw('1')->from('entries as pe')
            ->where('pe.site_id', $this->siteId)->whereColumn('pe.id', 'entries.parent_id')->whereNull('pe.deleted_at'));
    }

    /**
     * How many top rows the page tree has (bounded).
     */
    public function treeTopCount(): int
    {
        $tops = $this->base();
        $tops->where(fn (Builder $top) => $top->whereNull('entries.parent_id')->orWhere(fn (Builder $orphan) => $this->whereOrphan($orphan)));

        return $this->count($tops);
    }

    /**
     * @param  list<array{id: int, ulid: string, name: string, count: int}>  $authors
     * @param  Closure(string, Closure): ListGroup  $edited
     * @return list<ListGroup>
     */
    private function authorGroups(array $authors, Closure $edited): array
    {
        usort($authors, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['ulid']] <=> [mb_strtolower($b['name']), $b['ulid']]);

        $groups = [$edited('', fn (Builder $q) => $q->whereNull('entries.author_id'))]; // no author sorts first, as an empty name
        foreach ($authors as $author) {
            $id = $author['id'];
            $groups[] = $edited($author['ulid'], fn (Builder $q) => $q->where('entries.author_id', $id));
        }

        return $groups;
    }

    /**
     * One page of rows in the order of $groups: from $offset (numbered pages), or after $anchor
     * (a page cursor: the run, the sort value and the tie of the row the page follows).
     *
     * Each row carries `__g` (the index of its run) and `__sv` (its sort value as text), from which
     * the next cursor is made. Runs are read together, each in its own index order; a rare run is
     * read on its own, only when the runs before it did not fill the page.
     *
     * @param  list<ListGroup>  $groups
     * @param  array{g: int, v: string|null, id: int}|null  $anchor
     * @return EloquentCollection<int, Entry>
     */
    public function rows(array $groups, int $offset, int $limit, ?array $anchor = null): EloquentCollection
    {
        $start = 0;
        if ($anchor !== null) {
            if (! isset($groups[$anchor['g']])) {
                return new EloquentCollection;
            }
            $start = $anchor['g'];
            $offset = 0;
        }

        // consecutive ordinary runs make one segment; a rare run is a segment of its own
        $segments = [];
        foreach (array_slice(array_keys($groups), $start) as $gi) {
            $alone = $groups[$gi]->rare || $segments === [] || $groups[end($segments)[0]]->rare;
            $alone ? $segments[] = [$gi] : $segments[array_key_last($segments)][] = $gi;
        }

        $out = new EloquentCollection;
        foreach ($segments as $si => $segment) {
            [$got, $size] = $this->segmentRows($groups, $segment, $offset, $limit - $out->count(), $si === 0 ? $anchor : null);
            $out = $out->concat($got);
            if ($out->count() >= $limit) {
                break;
            }
            // the page goes on into the next segment: an offset past this one moves on by its size
            $offset = $got->isNotEmpty() || $offset === 0 ? 0 : max(0, $offset - ($size ?? $this->segmentCount($groups, $segment)));
        }

        return $out;
    }

    /**
     * The rows of a segment from $offset, or after $anchor, and the segment's size when it was
     * counted on the way.
     *
     * A page deep into several runs (the status or the author order) first counts each run (bounded,
     * from its index, in one statement), so only the run the page starts in skips rows; the runs
     * after it read just what the page still needs.
     *
     * @param  list<ListGroup>  $groups
     * @param  list<int>  $segment
     * @param  array{g: int, v: string|null, id: int}|null  $anchor
     * @return array{0: EloquentCollection<int, Entry>, 1: int|null}
     */
    private function segmentRows(array $groups, array $segment, int $offset, int $limit, ?array $anchor): array
    {
        $size = null;
        if ($anchor === null && $offset > 0 && count($segment) > 1) {
            $sizes = self::counts(array_combine(array_map(fn (int $gi): string => 'g'.$gi, $segment),
                array_map(fn (int $gi): array => self::boundedSql($this->runQuery($groups[$gi])), $segment)));
            $size = array_sum($sizes);
            $before = 0;
            $from = null;
            foreach ($segment as $i => $gi) {
                if ($before + $sizes['g'.$gi] > $offset) {
                    $from = $i;
                    break;
                }
                $before += $sizes['g'.$gi];
            }
            if ($from === null) {
                return [new EloquentCollection, $size];
            }
            $segment = array_slice($segment, $from);
            $offset -= $before;
        }

        $branches = [];
        foreach ($segment as $gi) {
            if ($anchor !== null && $gi === $anchor['g']) {
                foreach ($this->afterAnchor($groups[$gi], $anchor) as [$part, $condition]) {
                    $branches[] = [$gi, $part, $condition];
                }
            } else {
                $branches[] = [$gi, null, null];
            }
        }
        if ($branches === []) {
            return [new EloquentCollection, $size];
        }

        if (count($branches) === 1) {
            [$gi, $part, $condition] = $branches[0];

            return [$this->branch($groups, $gi, $part, $condition, null)->offset($offset)->limit($limit)->get(), $size];
        }

        // several runs: the first reads past the offset, each later one at most a page, each in its own
        // index order; the runs follow each other by their segment number
        $union = null;
        foreach ($branches as $n => [$gi, $part, $condition]) {
            $branch = $this->branch($groups, $gi, $part, $condition, $n)->limit(($n === 0 ? $offset : 0) + $limit);
            $union === null ? $union = $branch : $union->unionAll($branch);
        }

        return [$union->orderBy('__seg')->orderBy('__rn')->offset($offset)->limit($limit)->get(), $size];
    }

    /**
     * How many rows the runs of a segment hold (bounded).
     *
     * @param  list<ListGroup>  $groups
     * @param  list<int>  $segment
     */
    private function segmentCount(array $groups, array $segment): int
    {
        $total = 0;
        foreach ($segment as $gi) {
            $total += $this->count($this->runQuery($groups[$gi]));
        }

        return $total;
    }

    /**
     * The rows of one run, unordered.
     *
     * @return Builder<Entry>
     */
    private function runQuery(ListGroup $group): Builder
    {
        $query = $this->filtered();
        if ($group->where !== null) {
            ($group->where)($query);
        }
        if ($group->joinTitle) {
            $this->joinTitle($query);
        }

        return $query;
    }

    /** @param  Builder<Entry>  $query */
    private function joinTitle(Builder $query): void
    {
        $query->join('entry_translations as nt', fn (JoinClause $title) => $title->on('nt.entry_id', '=', 'entries.id')
            ->where('nt.site_id', '=', $this->siteId)
            ->where('nt.locale', '=', $this->filter->defaultLocale));
    }

    /**
     * The branches that continue a run after its anchor row: [part, condition]. A run whose value may
     * be NULL is read as its non-NULL rows and its NULL rows, each in index order.
     *
     * @param  array{g: int, v: string|null, id: int}  $anchor
     * @return list<array{0: 'value'|'null'|null, 1: array{0: literal-string, 1: list<mixed>}|null}>
     */
    private function afterAnchor(ListGroup $group, array $anchor): array
    {
        $op = $group->dir === 'desc' ? '<' : '>';
        $tie = [$group->tie.' '.$op.' ?', [$anchor['id']]];
        if ($group->order === null) {
            return [[null, $tie]];
        }

        $value = $anchor['v'];
        $row = ["({$group->order}, {$group->tie}) {$op} (?::{$group->cast}, ?)", [$value, $anchor['id']]];
        if (! $group->nullable) {
            return $value === null ? [] : [[null, $row]];
        }

        if ($value !== null) {
            // newest first: the rows with no value come after every dated one
            return $group->dir === 'desc' ? [['value', $row], ['null', null]] : [['value', $row]];
        }

        // oldest first: the rows with no value come before every dated one
        return $group->dir === 'asc' ? [['null', $tie], ['value', null]] : [['null', $tie]];
    }

    /**
     * @param  list<ListGroup>  $groups
     * @param  'value'|'null'|null  $part
     * @param  array{0: literal-string, 1: list<mixed>}|null  $condition
     * @return Builder<Entry>
     */
    private function branch(array $groups, int $gi, ?string $part, ?array $condition, ?int $segment): Builder
    {
        $group = $groups[$gi];
        $query = $this->runQuery($group);

        ($this->select)($query);
        $query->selectRaw(($group->value !== null ? "({$group->value})::text" : 'null::text').' as __sv')->selectRaw('?::int as __g', [$gi]);

        if ($part === 'value' && $group->value !== null) {
            $query->whereNotNull($group->value);
        } elseif ($part === 'null' && $group->value !== null) {
            $query->whereNull($group->value);
        }
        if ($condition !== null) {
            $query->whereRaw($condition[0], $condition[1]);
        }

        $order = $this->orderSql($group, $part);
        $query->orderByRaw($order);
        if ($segment !== null) {
            $query->selectRaw("?::int as __seg, row_number() over (order by {$order}) as __rn", [$segment]);
        }

        return $query;
    }

    /** @param  'value'|'null'|null  $part */
    /** @return literal-string */
    private function orderSql(ListGroup $group, ?string $part): string
    {
        if ($group->order === null || $part === 'null') {
            return "{$group->tie} {$group->dir}";
        }
        // spelled out: an index on "x NULLS FIRST" read backwards is "x DESC NULLS LAST"
        $nulls = $group->nullable ? ($group->dir === 'desc' ? ' nulls last' : ' nulls first') : '';

        return "{$group->order} {$group->dir}{$nulls}, {$group->tie} {$group->dir}";
    }

    /**
     * The rows of $query, counted up to CAP + 1.
     *
     * @param  Builder<Entry>  $query
     */
    public function count(Builder $query): int
    {
        [$sql, $bindings] = self::boundedSql($query);

        return Cast::int(((array) DB::selectOne("select {$sql} as n", $bindings))['n'] ?? 0);
    }

    /**
     * Values as one Postgres array literal, bound as a single parameter (`= any(?::int[])`): the
     * statement's text stays the same however many values there are.
     *
     * @param  array<int|string>  $values
     */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (int|string $v): string => '"'.addcslashes((string) $v, '"\\').'"', $values)).'}';
    }

    /**
     * A bounded count as a scalar subquery, to read several in one statement.
     *
     * @param  Builder<Entry>  $query
     * @return array{0: string, 1: list<mixed>}
     */
    public static function boundedSql(Builder $query): array
    {
        $rows = $query->toBase()->select(DB::raw('1'))->limit(self::CAP + 1);
        $rows->orders = null;

        return ['(select count(*) from ('.$rows->toSql().') as bounded)', $rows->getBindings()];
    }

    /**
     * Read several bounded counts in one statement.
     *
     * @param  array<string, array{0: string, 1: list<mixed>}>  $counts  alias => boundedSql()
     * @return array<string, int>
     */
    public static function counts(array $counts): array
    {
        if ($counts === []) {
            return [];
        }
        $select = [];
        $bindings = [];
        $aliases = array_keys($counts);
        foreach (array_values($counts) as $i => [$sql, $values]) {
            $select[] = "{$sql} as c{$i}";
            $bindings = [...$bindings, ...$values];
        }
        $row = (array) DB::selectOne('select '.implode(', ', $select), $bindings);

        $out = [];
        foreach ($aliases as $i => $alias) {
            $out[$alias] = Cast::int($row["c{$i}"] ?? 0);
        }

        return $out;
    }

    /**
     * The status group counts (bounded): every live row, each status, the Trash.
     *
     * @return array<string, array{0: string, 1: list<mixed>}>
     */
    public function tabCountSql(): array
    {
        $out = ['all' => self::boundedSql($this->scoped())];
        foreach (EntryListFilter::STATUS_TABS as $status) {
            $tab = $this->scoped();
            $this->whereTab($tab, $status);
            $out[$status] = self::boundedSql($tab);
        }
        $trash = $this->scoped();
        $this->whereTab($trash, 'trash');
        $out['trash'] = self::boundedSql($trash);

        return $out;
    }

    /**
     * The facet counts over base() — per language, translation state, author and template — read
     * in one walk of at most CAP + 1 of its rows, together with the status group counts when $tabs.
     *
     * A list of up to CAP rows gets every count exactly. Past that the walk stops: `n` is then
     * CAP + 1 and the facet counts are not known (`exact` false): a count per value of a list that
     * large would walk it whole, once per value. With $gate (no search), the list's size is read
     * first from its index, and a list past CAP is not walked at all; that size is read once, and
     * stands for its own group's count too.
     *
     * A section's posts are listed once, at the head of the statement (`sec`), and every count
     * reads that list: tested per count, the section's pivot rows would be read again for each
     * one, and on a large website each test became a parallel hash join of its own, whose shared
     * memory added up within the statement until Postgres ran out of it.
     *
     * With $people, the authors (authors()) come back in the same statement.
     *
     * @param  list<int>|null  $only  the rows already known to be the whole of base() (a search's one page)
     * @return array{n: int, exact: bool, counts: array<string, int>, lang: array<string, int>, trans: array<string, int>, author: array<int, int>, template: array<string, int>, people: list<array{id: int, ulid: string, name: string}>|null}
     */
    public function facets(bool $multilingual, bool $tabs = false, ?array $only = null, bool $gate = false, bool $people = false): array
    {
        $taxonomyId = $this->filter->sectionTaxonomyId;
        $this->sectionSet = $taxonomyId !== null;
        try {
            $base = $only === null ? $this->base() : Entry::query()->withTrashed()->whereIn('entries.id', $only === [] ? [0] : $only);
            $size = $gate ? self::boundedSql($this->base()) : null;
            $also = $tabs ? $this->tabCountSql() : [];
        } finally {
            $this->sectionSet = false;
        }

        $heads = [];
        $headBindings = [];
        if ($taxonomyId !== null) {
            $heads[] = 'sec as materialized (select entry_id from entry_taxonomies where site_id = ? and taxonomy_id = ? and position = 0)';
            $headBindings = [$this->siteId, $taxonomyId];
        }
        if ($size !== null) {
            $heads[] = "s as materialized (select {$size[0]} as c)";
            $headBindings = [...$headBindings, ...$size[1]];
            $base->whereRaw('(select s.c from s) <= '.self::CAP);
        }
        $rows = $base->toBase();
        $rows->columns = null;
        $rows->bindings['select'] = [];
        $rows->select(['entries.author_id', 'entries.template_override']);
        if ($multilingual) {
            $rows->selectRaw('array(select lt.locale from entry_translations lt where lt.site_id = ? and lt.entry_id = entries.id) as locs', [$this->siteId]);
        }
        $rows->orders = null;
        $rows->limit(self::CAP + 1);
        $heads[] = 'w as materialized ('.$rows->toSql().')';
        $headBindings = [...$headBindings, ...$rows->getBindings()];

        $n = count($this->filter->locales);
        $parts = ["select 'n'::text as k, null::text as v, count(*) as c from w",
            "select 'author', author_id::text, count(*) from w group by author_id",
            "select 'template', template_override, count(*) from w group by template_override"];
        $bindings = [];
        if ($multilingual) {
            $marks = implode(', ', array_fill(0, $n, '?'));
            $parts[] = "select 'lang', l, count(*) from w cross join lateral unnest(w.locs) as l group by l";
            $parts[] = "select 'trans', case when h >= {$n} then 'full' when h <= 1 then 'none' else 'partial' end, count(*) "
                ."from (select (select count(*) from unnest(w.locs) as x where x in ({$marks})) as h from w) as states group by 2";
            $bindings = [...$bindings, ...$this->filter->locales];
        }
        if ($size !== null) {
            $parts[] = "select 'size', null, (select s.c from s)";
        }
        if ($people) {
            [$ids, $values] = $this->authorIdSql();
            $parts[] = "select 'who', u.ulid::text || u.name, u.id from users u where u.id in ({$ids})";
            $bindings = [...$bindings, ...$values];
            $this->authorIds = [];
        }
        foreach ($also as $key => [$sql, $values]) {
            if ($size !== null && $sql === $size[0] && $values === $size[1]) {
                $parts[] = "select 'count', '{$key}', (select s.c from s)";

                continue;
            }
            $parts[] = "select 'count', '{$key}', {$sql}";
            $bindings = [...$bindings, ...$values];
        }

        $statement = 'with '.implode(', ', $heads).' '.implode(' union all ', $parts);
        $out = ['n' => 0, 'exact' => true, 'counts' => [], 'lang' => [], 'trans' => [], 'author' => [], 'template' => [], 'people' => $people ? [] : null];
        foreach (DB::select($statement, [...$headBindings, ...$bindings]) as $row) {
            $row = (array) $row;
            $k = Cast::string($row['k'] ?? '');
            $v = $row['v'] ?? null;
            $c = Cast::int($row['c'] ?? 0);
            match ($k) {
                'n' => $out['n'] = max($out['n'], $c),
                'size' => $out['n'] = max($out['n'], $c),
                'author' => $v === null ? null : $out['author'][Cast::int($v)] = $c,
                'template' => $out['template'][is_string($v) ? $v : EntryListFilter::TEMPLATE_NONE] = $c,
                'lang' => $out['lang'][Cast::string($v)] = $c,
                'trans' => $out['trans'][Cast::string($v)] = $c,
                'count' => $out['counts'][Cast::string($v)] = $c,
                // a ulid is 26 characters: the name follows it
                'who' => $out['people'][] = $this->knowAuthor($c, substr(Cast::string($v), 0, 26), substr(Cast::string($v), 26)),
                default => null,
            };
        }
        $out['exact'] = $out['n'] <= self::CAP;

        return $out;
    }

    /**
     * The people who wrote rows of this type here (live, or in the Trash on the Trash tab), by id.
     * Their ids stay on the server: the list names them by ulid. Once read, the Người tạo filter
     * matches their ids directly.
     *
     * @return list<array{id: int, ulid: string, name: string}>
     */
    public function authors(): array
    {
        [$ids, $bindings] = $this->authorIdSql();

        $out = [];
        $this->authorIds = [];
        foreach (DB::select("select u.id, u.ulid, u.name from users u where u.id in ({$ids})", $bindings) as $row) {
            $row = (array) $row;
            $out[] = $this->knowAuthor(Cast::int($row['id'] ?? 0), Cast::string($row['ulid'] ?? ''), Cast::string($row['name'] ?? ''));
        }

        return $out;
    }

    /** @return array{id: int, ulid: string, name: string} */
    private function knowAuthor(int $id, string $ulid, string $name): array
    {
        $ulid = trim($ulid);
        $this->authorIds ??= [];
        $this->authorIds[$ulid] = $id;

        return ['id' => $id, 'ulid' => $ulid, 'name' => $name];
    }

    /**
     * The distinct authors of the type (live, or in the Trash on the Trash tab). Live posts skip
     * through the author index one person at a time (a few probes, however many posts).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function authorIdSql(): array
    {
        $deleted = $this->filter->tab === 'trash' ? 'is not null' : 'is null';
        if ($this->type === 'post' && $this->filter->tab !== 'trash') {
            $live = "e.site_id = ? and e.type = 'post' and e.deleted_at is null";

            return [
                "with recursive a(id) as ((select e.author_id from entries e where {$live} and e.author_id is not null order by e.author_id limit 1) "
                ."union all select (select e.author_id from entries e where {$live} and e.author_id > a.id order by e.author_id limit 1) from a where a.id is not null) "
                .'select id from a where id is not null',
                [$this->siteId, $this->siteId],
            ];
        }

        return ["select distinct e.author_id from entries e where e.site_id = ? and e.type = '{$this->type}' and e.deleted_at {$deleted} and e.author_id is not null", [$this->siteId]];
    }

    /**
     * The template values the type uses here (live, or in the Trash on the Trash tab); null is
     * TEMPLATE_NONE.
     *
     * @return list<string>
     */
    public function templateValues(): array
    {
        $deleted = $this->filter->tab === 'trash' ? 'is not null' : 'is null';
        $out = [];
        foreach (DB::select("select distinct e.template_override as v from entries e where e.site_id = ? and e.type = '{$this->type}' and e.deleted_at {$deleted} order by 1 nulls first", [$this->siteId]) as $row) {
            $value = ((array) $row)['v'] ?? null;
            $out[] = is_string($value) ? $value : EntryListFilter::TEMPLATE_NONE;
        }

        return $out;
    }

    /**
     * Every live page below the given top pages (at most $cap of them), with their parent.
     *
     * @param  list<int>  $topIds
     * @return EloquentCollection<int, Entry>
     */
    public function descendants(array $topIds, int $cap): EloquentCollection
    {
        if ($topIds === []) {
            return new EloquentCollection;
        }
        $live = "c.site_id = ? and c.type = 'page' and c.deleted_at is null";
        $tree = "with recursive d(id, depth) as (select c.id, 1 from entries c where {$live} and c.parent_id = any(?::bigint[]) "
            ."union all select c.id, d.depth + 1 from entries c join d on c.parent_id = d.id where {$live} and d.depth < 32) "
            .'select id from d limit ?';

        $query = Entry::query()->whereRaw("entries.type = 'page'");
        ($this->select)($query);

        return $query->whereRaw("entries.id in ({$tree})", [$this->siteId, self::pgArray($topIds), $this->siteId, $cap])->get();
    }

    /**
     * The parents above each given page, as default-language titles from the top down ("A › B"),
     * keyed by the page's parent id.
     *
     * @param  list<int>  $parentIds
     * @return array<int, list<string>>
     */
    public function ancestorTitles(array $parentIds, string $untitled): array
    {
        if ($parentIds === []) {
            return [];
        }
        $sql = 'with recursive up(id, parent_id, depth) as (select p.id, p.parent_id, 0 from entries p where p.site_id = ? and p.id = any(?::bigint[]) '
            .'union all select p.id, p.parent_id, up.depth + 1 from entries p join up on p.id = up.parent_id where p.site_id = ? and up.depth < 32) '
            .'select distinct up.id, up.parent_id, t.title from up left join entry_translations t on t.entry_id = up.id and t.site_id = ? and t.locale = ?';

        $nodes = [];
        foreach (DB::select($sql, [$this->siteId, self::pgArray($parentIds), $this->siteId, $this->siteId, $this->filter->defaultLocale]) as $row) {
            $row = (array) $row;
            $parent = $row['parent_id'] ?? null;
            $title = $row['title'] ?? null;
            $nodes[Cast::int($row['id'] ?? 0)] = ['parent' => $parent === null ? null : Cast::int($parent), 'title' => is_string($title) && $title !== '' ? $title : $untitled];
        }

        $out = [];
        foreach ($parentIds as $id) {
            $path = [];
            for ($at = $id, $guard = 0; $at !== null && isset($nodes[$at]) && $guard < 32; $guard++) {
                array_unshift($path, $nodes[$at]['title']);
                $at = $nodes[$at]['parent'];
            }
            $out[$id] = $path;
        }

        return $out;
    }
}
