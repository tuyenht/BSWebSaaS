<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Redirects;

use App\Modules\Admin\Support\Entries\ListCursor;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SafeLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What the «Chuyển hướng» screen reads: the current site's redirects in one of three groups (in
 * service, in service with a warning, in the trash), narrowed by language, source and kind and by a
 * search on either address, one page at a time from a keyset cursor (never OFFSET), with the counts
 * the groups and the filter choices show.
 *
 * Every query runs through the Redirect model, so the site scope and RLS pick the site's own rows.
 * A row on the page says where it came from (the post, page, product or category it leads to) and
 * whether it loops or is shadowed by a live address: each of those is one query for the whole page
 * (whereIn on the page's keys), never one per row.
 *
 * The cursor is a ListCursor note (encrypted, tied to the view), so no row id reaches the page.
 */
final class RedirectDirectory
{
    public const string TAB_LIVE = 'live';

    public const string TAB_WARN = 'warn';

    public const string TAB_TRASH = 'trash';

    /** @var list<string> */
    public const array TABS = [self::TAB_LIVE, self::TAB_WARN, self::TAB_TRASH];

    public const string SORT_NEW = 'new';

    public const string SORT_OLD = 'old';

    public const string SORT_AZ = 'az';

    /** @var list<string> */
    public const array SORTS = [self::SORT_NEW, self::SORT_OLD, self::SORT_AZ];

    public const int SEARCH_MAX = 100;

    /**
     * The rows of one page and the cursors around it.
     *
     * @param  array{locales: list<string>, sources: list<string>, codes: list<int>}  $filters
     * @return array{rows: list<array<string, mixed>>, older: string|null, newer: string|null, page: int}
     */
    public function page(string $tab, array $filters, string $search, string $sort, string $cursor, int $per): array
    {
        $fingerprint = $this->fingerprint($tab, $filters, $search, $sort, $per);
        $note = ListCursor::open($cursor, $fingerprint);
        $way = $note['w'] ?? 'after';
        $number = $note === null ? 1 : ($way === 'after' ? $note['n'] + 1 : max(1, $note['n'] - 1));

        $query = $this->filtered($tab, $filters, $search);
        if ($note !== null) {
            $this->beyond($query, $sort, $way, $note['v'], $note['id']);
        }
        $this->order($query, $sort, $way === 'before');

        $found = $query->limit($per + 1)
            ->get(['id', 'ulid', 'locale', 'from_path', 'to_path', 'status_code', 'source', 'entry_id', 'product_id', 'taxonomy_id', 'created_at', 'deleted_at'])
            ->all();
        $more = count($found) > $per;
        $found = array_slice($found, 0, $per);
        if ($way === 'before') {
            $found = array_reverse($found);
        }

        $older = $newer = null;
        if ($found !== []) {
            $first = $found[0];
            $last = $found[count($found) - 1];
            $hasOlder = $way === 'before' ? true : $more;
            $hasNewer = $number > 1;
            $older = $hasOlder ? ListCursor::make($fingerprint, 'rd', $this->sortValue($last, $sort), $last->id, 'after', $number) : null;
            $newer = $hasNewer ? ListCursor::make($fingerprint, 'rd', $this->sortValue($first, $sort), $first->id, 'before', $number) : null;
        }

        return ['rows' => $this->present($found), 'older' => $older, 'newer' => $newer, 'page' => $number];
    }

    /**
     * The counts the screen shows: each group's rows, and per language, source and kind the rows of
     * the in-service set (or of the trash, when that is the group shown). One grouped query plus one
     * for the warnings.
     *
     * @return array{tabs: array<string, int>, locales: array<string, int>, sources: array<string, int>, codes: array<int, int>}
     */
    public function counts(string $tab): array
    {
        $tabs = [self::TAB_LIVE => 0, self::TAB_WARN => 0, self::TAB_TRASH => 0];
        $locales = $sources = $codes = [];
        $inTrash = $tab === self::TAB_TRASH;

        $groups = Redirect::query()->withTrashed()
            ->selectRaw('locale, source, status_code, (deleted_at IS NOT NULL) AS trashed, count(*) AS n')
            ->groupByRaw('locale, source, status_code, (deleted_at IS NOT NULL)')
            ->toBase()->get();
        foreach ($groups as $g) {
            $trashed = Cast::bool($g->trashed);
            $n = Cast::int($g->n);
            $tabs[$trashed ? self::TAB_TRASH : self::TAB_LIVE] += $n;
            if ($trashed !== $inTrash) {
                continue;
            }
            $locale = Cast::string($g->locale);
            $source = Cast::string($g->source);
            $code = Cast::int($g->status_code);
            $locales[$locale] = ($locales[$locale] ?? 0) + $n;
            $sources[$source] = ($sources[$source] ?? 0) + $n;
            $codes[$code] = ($codes[$code] ?? 0) + $n;
        }

        $tabs[self::TAB_WARN] = $this->warned(Redirect::query())->count();

        return ['tabs' => $tabs, 'locales' => $locales, 'sources' => $sources, 'codes' => $codes];
    }

    /**
     * The rows the group, the filters and the search keep.
     *
     * @param  array{locales: list<string>, sources: list<string>, codes: list<int>}  $filters
     */
    public function matchCount(string $tab, array $filters, string $search): int
    {
        return $this->filtered($tab, $filters, $search)->count();
    }

    /**
     * @param  array{locales: list<string>, sources: list<string>, codes: list<int>}  $filters
     * @return Builder<Redirect>
     */
    private function filtered(string $tab, array $filters, string $search): Builder
    {
        $query = $tab === self::TAB_TRASH ? Redirect::onlyTrashed() : Redirect::query();
        if ($tab === self::TAB_WARN) {
            $this->warned($query);
        }
        if ($filters['locales'] !== []) {
            $query->whereIn('locale', $filters['locales']);
        }
        if ($filters['sources'] !== []) {
            $query->whereIn('source', $filters['sources']);
        }
        if ($filters['codes'] !== []) {
            $query->whereIn('status_code', $filters['codes']);
        }
        $words = self::searchText($search);
        if ($words !== '') {
            $like = '%'.addcslashes($words, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->whereRaw('lower(from_path) LIKE ?', [$like])->orWhereRaw('lower(to_path) LIKE ?', [$like]));
        }

        return $query;
    }

    /**
     * Rows in service that never take effect as meant: their old address is a live address of the
     * site (the content wins, so the redirect never runs), or they and another row send visitors
     * back and forth.
     *
     * @param  Builder<Redirect>  $query
     * @return Builder<Redirect>
     */
    private function warned(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereExists(fn (QueryBuilder $route) => $route->selectRaw('1')->from('routes')
                ->whereColumn('routes.site_id', 'redirects.site_id')
                ->whereColumn('routes.locale', 'redirects.locale')
                ->whereColumn('routes.path', 'redirects.from_path'))
                ->orWhereExists(fn (QueryBuilder $back) => $back->selectRaw('1')->from('redirects as back')
                    ->whereColumn('back.site_id', 'redirects.site_id')
                    ->whereColumn('back.locale', 'redirects.locale')
                    ->whereColumn('back.from_path', 'redirects.to_path')
                    ->whereColumn('back.to_path', 'redirects.from_path')
                    ->whereNull('back.deleted_at'));
        });
    }

    /**
     * @param  Builder<Redirect>  $query
     */
    private function order(Builder $query, string $sort, bool $reverse): void
    {
        $asc = $reverse ? 'desc' : 'asc';
        $desc = $reverse ? 'asc' : 'desc';
        match ($sort) {
            self::SORT_OLD => $query->orderBy('id', $asc),
            self::SORT_AZ => $query->orderBy('from_path', $asc)->orderBy('id', $asc),
            default => $query->orderBy('id', $desc),
        };
    }

    /**
     * The rows past the cursor's row in the list's order ('after') or before it ('before').
     *
     * @param  Builder<Redirect>  $query
     */
    private function beyond(Builder $query, string $sort, string $way, ?string $value, int $id): void
    {
        $forward = $way === 'after';
        match ($sort) {
            self::SORT_OLD => $query->where('id', $forward ? '>' : '<', $id),
            self::SORT_AZ => $query->where(function (Builder $q) use ($forward, $value, $id): void {
                $op = $forward ? '>' : '<';
                $q->where('from_path', $op, (string) $value)
                    ->orWhere(fn (Builder $tie) => $tie->where('from_path', (string) $value)->where('id', $op, $id));
            }),
            default => $query->where('id', $forward ? '<' : '>', $id),
        };
    }

    private function sortValue(Redirect $row, string $sort): ?string
    {
        return $sort === self::SORT_AZ ? $row->from_path : null;
    }

    /**
     * @param  array{locales: list<string>, sources: list<string>, codes: list<int>}  $filters
     */
    private function fingerprint(string $tab, array $filters, string $search, string $sort, int $per): string
    {
        return hash('xxh128', json_encode([$tab, $filters, self::searchText($search), $sort, $per], JSON_THROW_ON_ERROR));
    }

    /**
     * One row as the screen draws it (the edit dialog's note names what an automatic row came from).
     *
     * @return array<string, mixed>
     */
    public function describe(Redirect $row): array
    {
        return $this->present([$row])[0];
    }

    /** The search as compared: trimmed and lower-cased (paths are stored lower-case). */
    public static function searchText(string $search): string
    {
        return mb_strtolower(trim(mb_substr($search, 0, self::SEARCH_MAX)));
    }

    /**
     * The page's rows as the screen draws them, with what each came from and its warnings.
     *
     * @param  list<Redirect>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $targets = $this->targetNames($rows);
        $shadowed = $this->shadowed($rows);
        $loops = $this->loops($rows);

        $out = [];
        foreach ($rows as $row) {
            $kind = null;
            $name = null;
            foreach (['entry' => $row->entry_id, 'product' => $row->product_id, 'taxonomy' => $row->taxonomy_id] as $type => $id) {
                if ($id !== null && isset($targets[$type][$id])) {
                    [$kind, $name] = [$targets[$type][$id]['kind'], self::pick($targets[$type][$id]['names'], $row->locale)];
                    break;
                }
            }
            $key = $row->locale."\n".$row->from_path;
            $out[] = [
                'ulid' => $row->ulid,
                'locale' => $row->locale,
                'from' => $row->from_path,
                'to' => $row->to_path,
                // another website's address: its host, for «Đi tới website khác: …»
                'external_host' => SafeLink::isExternalHttps($row->to_path) ? parse_url($row->to_path, PHP_URL_HOST) : null,
                'code' => $row->status_code,
                'source' => $row->source,
                'target_kind' => $kind,
                'target_name' => $name,
                'created_at' => $row->created_at,
                'deleted_at' => $row->deleted_at,
                'page' => $row->deleted_at === null && isset($shadowed[$key]),
                'loop' => $row->deleted_at === null && isset($loops[$key]),
            ];
        }

        return $out;
    }

    /**
     * The names of the posts, pages, products and categories the rows lead to, per language.
     *
     * @param  list<Redirect>  $rows
     * @return array<string, array<int, array{kind: string, names: array<string, string>}>>
     */
    private function targetNames(array $rows): array
    {
        $ids = ['entry' => [], 'product' => [], 'taxonomy' => []];
        foreach ($rows as $row) {
            foreach (['entry' => $row->entry_id, 'product' => $row->product_id, 'taxonomy' => $row->taxonomy_id] as $type => $id) {
                if ($id !== null) {
                    $ids[$type][$id] = $id;
                }
            }
        }

        $out = ['entry' => [], 'product' => [], 'taxonomy' => []];
        if ($ids['entry'] !== []) {
            $types = Entry::query()->withTrashed()->whereIn('id', array_values($ids['entry']))->pluck('type', 'id')->all();
            foreach (EntryTranslation::query()->whereIn('entry_id', array_values($ids['entry']))->get(['entry_id', 'locale', 'title']) as $t) {
                $id = Cast::int($t->entry_id);
                $out['entry'][$id]['kind'] = Cast::string($types[$id] ?? 'post') === 'page' ? 'page' : 'post';
                $out['entry'][$id]['names'][Cast::string($t->locale)] = Cast::string($t->title);
            }
        }
        if ($ids['product'] !== []) {
            foreach (ProductTranslation::query()->whereIn('product_id', array_values($ids['product']))->get(['product_id', 'locale', 'name']) as $t) {
                $id = Cast::int($t->product_id);
                $out['product'][$id]['kind'] = 'product';
                $out['product'][$id]['names'][Cast::string($t->locale)] = Cast::string($t->name);
            }
        }
        if ($ids['taxonomy'] !== []) {
            foreach (TaxonomyTranslation::query()->whereIn('taxonomy_id', array_values($ids['taxonomy']))->get(['taxonomy_id', 'locale', 'name']) as $t) {
                $id = Cast::int($t->taxonomy_id);
                $out['taxonomy'][$id]['kind'] = 'taxonomy';
                $out['taxonomy'][$id]['names'][Cast::string($t->locale)] = Cast::string($t->name);
            }
        }

        return $out;
    }

    /**
     * The rows whose old address is a live address of the site in the same language.
     *
     * @param  list<Redirect>  $rows
     * @return array<string, true> keyed "locale\npath"
     */
    private function shadowed(array $rows): array
    {
        $froms = array_values(array_unique(array_map(static fn (Redirect $r): string => $r->from_path, $rows)));
        $out = [];
        foreach (SiteRoute::query()->whereIn('path', $froms)->get(['locale', 'path']) as $route) {
            $out[Cast::string($route->locale)."\n".Cast::string($route->path)] = true;
        }

        return $out;
    }

    /**
     * The rows that another row in service sends straight back (a → b and b → a).
     *
     * @param  list<Redirect>  $rows
     * @return array<string, true> keyed "locale\nfrom_path"
     */
    private function loops(array $rows): array
    {
        $tos = array_values(array_unique(array_map(static fn (Redirect $r): string => $r->to_path, $rows)));
        $back = [];
        foreach (Redirect::query()->whereIn('from_path', $tos)->get(['locale', 'from_path', 'to_path']) as $r) {
            $back[$r->locale."\n".$r->from_path] = $r->to_path;
        }

        $out = [];
        foreach ($rows as $row) {
            if (($back[$row->locale."\n".$row->to_path] ?? null) === $row->from_path) {
                $out[$row->locale."\n".$row->from_path] = true;
            }
        }

        return $out;
    }

    /**
     * The name in the row's language, else the first one there is.
     *
     * @param  array<string, string>  $names
     */
    private static function pick(array $names, string $locale): ?string
    {
        return $names[$locale] ?? ($names === [] ? null : reset($names));
    }
}
