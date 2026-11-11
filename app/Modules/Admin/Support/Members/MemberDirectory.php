<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Members;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The members of the current site, read page by page for the members screen. `users` is global
 * identity (D6, no RLS), so every read here is held to the site's own membership — a role in its
 * team (model_has_roles.site_id) — and never reaches another tenant's accounts.
 *
 * Sorted by name, then id. Paging is a keyset cursor on (name, id) addressed by the boundary
 * member's ulid, so the page never counts rows it skips. The search ignores accents and case in
 * the name (the needle and the column are folded by the same letter table) and matches the email
 * as typed, case-insensitively; `%` and `_` only match themselves. A site's membership is small next
 * to the users table, so the membership test leads and the fold runs on those rows alone.
 */
final class MemberDirectory
{
    /** @var list<int> */
    public const array PER_PAGE = [10, 20, 50];

    public const int SEARCH_MAX = 100;

    public function __construct(private readonly CurrentSite $site) {}

    /**
     * One page of members, with the cursors of the pages either side (null: no page that way).
     *
     * @param  list<string>  $roles  role names to keep (any of them); [] keeps every member
     * @return array{rows: Collection<int, User>, older: ?string, newer: ?string}
     */
    public function page(string $search, array $roles, int $per, string $after, string $before): array
    {
        $query = $this->filtered($search, $roles)
            ->select(['users.id', 'users.ulid', 'users.name', 'users.email'])
            ->with('roles');

        $cursor = $after !== '' ? $this->cursorOf($after) : ($before !== '' ? $this->cursorOf($before) : null);
        $backwards = $after === '' && $before !== '' && $cursor !== null;

        if ($cursor !== null) {
            $query->whereRaw('(users.name, users.id) '.($backwards ? '<' : '>').' (?, ?)', [$cursor['name'], $cursor['id']]);
        }
        $direction = $backwards ? 'desc' : 'asc';
        $rows = $query->orderBy('users.name', $direction)->orderBy('users.id', $direction)->limit($per + 1)->get();

        $more = $rows->count() > $per;
        $rows = $rows->take($per);
        if ($backwards) {
            $rows = $rows->reverse();
        }
        $rows = $rows->values();

        $first = $rows->first();
        $last = $rows->last();
        $hasNext = $backwards ? true : $more;
        $hasPrev = $backwards ? $more : $cursor !== null;

        return [
            'rows' => $rows,
            'older' => $hasNext && $last instanceof User ? $last->ulid : null,
            'newer' => $hasPrev && $first instanceof User ? $first->ulid : null,
        ];
    }

    /**
     * How many members match the search and the roles (the "N trong M" line).
     *
     * @param  list<string>  $roles
     */
    public function matchCount(string $search, array $roles): int
    {
        return $this->filtered($search, $roles)->count();
    }

    /**
     * The site's member total and, per role name, how many members hold it — one statement.
     *
     * @return array{total: int, roles: array<string, int>}
     */
    public function counts(): array
    {
        $rows = DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.site_id', $this->site->id())
            ->where('mhr.model_type', User::class)
            ->groupByRaw('grouping sets ((r.name), ())')
            ->get([DB::raw('r.name'), DB::raw('count(distinct mhr.model_id) as n')]);

        $total = 0;
        $byRole = [];
        foreach ($rows as $row) {
            $n = Cast::int($row->n);
            if ($row->name === null) {
                $total = $n;
            } else {
                $byRole[Cast::string($row->name)] = $n;
            }
        }

        return ['total' => $total, 'roles' => $byRole];
    }

    public static function searches(string $search): bool
    {
        return preg_match('/[\p{L}\p{Nd}@._-]/u', $search) === 1;
    }

    /**
     * @param  list<string>  $roles
     * @return Builder<User>
     */
    private function filtered(string $search, array $roles): Builder
    {
        $siteId = $this->site->id();
        $query = User::query()->whereExists(fn (QueryBuilder $m): QueryBuilder => $m->selectRaw('1')
            ->from('model_has_roles')
            ->whereColumn('model_has_roles.model_id', 'users.id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.site_id', $siteId));

        if ($roles !== []) {
            $query->whereExists(fn (QueryBuilder $m): QueryBuilder => $m->selectRaw('1')
                ->from('model_has_roles as fr')
                ->join('roles as frr', 'frr.id', '=', 'fr.role_id')
                ->whereColumn('fr.model_id', 'users.id')
                ->where('fr.model_type', User::class)
                ->where('fr.site_id', $siteId)
                ->whereIn('frr.name', $roles));
        }

        $term = trim(mb_substr(trim($search), 0, self::SEARCH_MAX));
        if ($term !== '' && mb_check_encoding($term, 'UTF-8') && self::searches($term)) {
            $folded = VietnameseFold::like(VietnameseFold::text($term));
            $literal = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';
            $query->where(fn (Builder $either): Builder => $either
                ->whereRaw(VietnameseFold::sql('users.name').' like ?', [$folded])
                ->orWhereRaw('lower(users.email) like ?', [$literal]));
        }

        return $query;
    }

    /**
     * The (name, id) of a member of this site addressed by ulid: the page boundary. Null when the
     * ulid is not one of the site's members (a stale or forged cursor reads the first page).
     *
     * @return array{name: string, id: int}|null
     */
    private function cursorOf(string $ulid): ?array
    {
        $row = $this->filtered('', [])->where('users.ulid', $ulid)->first(['users.id', 'users.name']);

        return $row instanceof User ? ['name' => $row->name, 'id' => (int) $row->id] : null;
    }
}
