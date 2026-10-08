<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Which categories a member may write in on the current site (member_category_scopes). The single
 * read side of category-scoped write rights: policies, workflow checks and lists ask here.
 *
 *  - No row for the member = every category (not limited).
 *  - A row covers its category and every category below it (ltree `<@`, with the parent chain as
 *    the fallback for a category whose path is not written yet).
 *  - Exempt, whatever rows exist: a platform super_admin and anyone holding a role of level
 *    EXEMPT_LEVEL or more on the site (owner 90, admin 70). Rows left on a member later promoted
 *    are ignored, never trusted.
 *  - Enforced whether or not the site's `rbac.category_scopes` feature is on: the feature only
 *    gates changing the limits (fail-closed when a plan lapses).
 *  - "No primary category" (a page, a post not yet filed) is NOT covered for a limited member.
 *
 * One indexed query per member per request reads the rows with every category they reach; the
 * exemption is looked up only for a member who has rows. Memoized per "site:user"; registered
 * scoped (CmsServiceProvider) so nothing outlives an Octane request. Runs inside the tenant context.
 */
final class CategoryScopes
{
    /** Members holding a role at or above this level on the site are never limited. */
    public const int EXEMPT_LEVEL = 70;

    /** @var array<string, array{roots: list<int>, reach: array<int, true>}> */
    private array $rows = [];

    /** @var array<string, bool> */
    private array $exempt = [];

    /** @var array<int, array<int, true>> per site: category id => true for each category without a path */
    private array $pathless = [];

    public function __construct(
        private readonly CurrentSite $site,
        private readonly RoleHierarchyGuard $levels,
        private readonly PlatformAccess $platform,
        private readonly SiteLocale $locales,
    ) {}

    /** Is the member never limited on this site, rows or not (super_admin, or level ≥ EXEMPT_LEVEL)? */
    public function isExempt(User $user): bool
    {
        return $this->exempt[$this->key($user)] ??= $this->platform->isSuperAdmin($user)
            || $this->levels->maxLevel($user) >= self::EXEMPT_LEVEL;
    }

    /** Is the member limited to some categories on this site? */
    public function isLimited(User $user): bool
    {
        return $this->read($user)['roots'] !== [] && ! $this->isExempt($user);
    }

    /**
     * The categories the member's rows name (without their descendants), or null when not limited.
     *
     * @return list<int>|null
     */
    public function rootIds(User $user): ?array
    {
        return $this->isLimited($user) ? $this->read($user)['roots'] : null;
    }

    /**
     * Every category the member may write in — the scoped ones and all below them — or null when
     * not limited (every category).
     *
     * @return list<int>|null
     */
    public function allowedTaxonomyIds(User $user): ?array
    {
        return $this->isLimited($user) ? array_keys($this->read($user)['reach']) : null;
    }

    /** May the member write content whose primary category is $primary? */
    public function covers(User $user, ?Taxonomy $primary): bool
    {
        return $this->coversId($user, $primary?->id);
    }

    /**
     * May the member write this entry? A post: when its primary category is covered. A page (no
     * category at all): only a member who is not limited.
     */
    public function coversEntry(User $user, Entry $entry): bool
    {
        if (! $this->isLimited($user)) {
            return true;
        }

        return $entry->type === 'post' && $this->covers($user, $entry->primaryCategory());
    }

    /**
     * The sentence a limited member sees when a write falls outside their limits, naming the
     * categories they may write in (in the site's default language).
     */
    public function refusal(User $user): string
    {
        $roots = $this->rootIds($user) ?? [];
        $locale = $this->locales->default();
        $names = [];
        foreach (TaxonomyTranslation::query()->whereIn('taxonomy_id', $roots)->orderBy('taxonomy_id')->get(['taxonomy_id', 'locale', 'name']) as $translation) {
            if (! isset($names[$translation->taxonomy_id]) || $translation->locale === $locale) {
                $names[$translation->taxonomy_id] = $translation->name;
            }
        }

        return __('entries.category_scope_only', ['categories' => implode(', ', $names)]);
    }

    /**
     * Narrow a query of users (`users`) to those who may write in every one of $categoryIds on this
     * site — the SQL form of coversId() for lists of people (approvers, who hears of a post): no
     * limits here, a role at or above EXEMPT_LEVEL, or limits reaching each category (a null id —
     * content without a category — reaches no limited member). A platform super_admin is matched
     * only as far as their roles here reach, like every other member list.
     *
     * @param  Builder<User>  $members
     */
    public function whereMayWriteIn(Builder $members, ?int ...$categoryIds): void
    {
        $site = $this->site->id();
        $userKey = $members->getModel()->getQualifiedKeyName();
        $reachable = ! in_array(null, $categoryIds, true);

        $members->where(function (Builder $who) use ($site, $userKey, $categoryIds, $reachable): void {
            $who->whereNotExists(fn (QueryBuilder $limit) => $limit->selectRaw('1')
                ->from('member_category_scopes')
                ->where('member_category_scopes.site_id', $site)
                ->whereColumn('member_category_scopes.user_id', $userKey))
                ->orWhereHas('roles', fn (Builder $role) => $role->where('level', '>=', self::EXEMPT_LEVEL));

            if ($reachable && $categoryIds !== []) {
                $who->orWhere(function (Builder $limited) use ($site, $userKey, $categoryIds): void {
                    foreach ($categoryIds as $categoryId) {
                        $limited->whereExists(fn (QueryBuilder $limit) => $limit->selectRaw('1')
                            ->from('member_category_scopes as mcs')
                            ->join('taxonomies as root', fn (JoinClause $join) => $join
                                ->on('root.id', '=', 'mcs.taxonomy_id')->on('root.site_id', '=', 'mcs.site_id'))
                            ->join('taxonomies as cat', 'cat.site_id', '=', 'mcs.site_id')
                            ->where('mcs.site_id', $site)
                            ->whereColumn('mcs.user_id', $userKey)
                            ->where('cat.id', $categoryId)
                            ->where(fn (QueryBuilder $reach) => $reach->whereColumn('cat.id', 'root.id')->orWhereRaw('cat.path <@ root.path')));
                    }
                });
            }
        });
    }

    /**
     * Narrow a query of entries (`entries`) to those the member may write — the SQL form of
     * coversEntry() for queues of work. Nothing changes for a member who is not limited.
     *
     * @param  Builder<Entry>  $entries
     */
    public function whereCovered(Builder $entries, User $user): void
    {
        $allowed = $this->allowedTaxonomyIds($user);
        if ($allowed === null) {
            return;
        }

        $site = $this->site->id();
        $entryKey = $entries->getModel()->getQualifiedKeyName();
        $entries->where($entries->getModel()->qualifyColumn('type'), 'post')
            ->whereExists(fn (QueryBuilder $primary) => $primary->selectRaw('1')
                ->from('entry_taxonomies')
                ->where('entry_taxonomies.site_id', $site)
                ->whereColumn('entry_taxonomies.entry_id', $entryKey)
                ->where('entry_taxonomies.position', 0)
                ->whereIn('entry_taxonomies.taxonomy_id', $allowed));
    }

    /** covers() for a category known only by id (a picked category, a pending change's target). */
    public function coversId(User $user, ?int $primaryId): bool
    {
        if (! $this->isLimited($user)) {
            return true;
        }
        if ($primaryId === null) {
            return false;
        }

        $read = $this->read($user);
        if (isset($read['reach'][$primaryId])) {
            return true;
        }

        // A category whose ltree path is missing is not reached above: walk its parents instead.
        // Only such a category is walked — any other miss is a real "no", answered without a query.
        if (! isset($this->pathless()[$primaryId])) {
            return false;
        }

        return array_intersect(TaxonomyTree::ancestorsOf($primaryId), $read['roots']) !== [];
    }

    /**
     * The members limited to one of $taxonomyIds on this site, with the highest role level each
     * holds here (0 for none), by name.
     *
     * @param  list<int>  $taxonomyIds
     * @return list<array{id: int, name: string, level: int}>
     */
    public function membersLimitedTo(array $taxonomyIds): array
    {
        if ($taxonomyIds === []) {
            return [];
        }

        $site = $this->site->id();
        $members = User::query()
            ->whereExists(fn (QueryBuilder $limit) => $limit->selectRaw('1')
                ->from('member_category_scopes')
                ->where('member_category_scopes.site_id', $site)
                ->whereColumn('member_category_scopes.user_id', 'users.id')
                ->whereIn('member_category_scopes.taxonomy_id', $taxonomyIds))
            ->withMax('roles', 'level')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        $found = [];
        foreach ($members as $member) {
            $found[] = [
                'id' => $member->id,
                'name' => $member->name,
                'level' => Cast::int($member->getAttribute('roles_max_level')),
            ];
        }

        return $found;
    }

    /**
     * Serialise every change to the member's limits on this site (setting them, clearing them on a
     * role change) until the surrounding transaction ends. Call it inside a transaction, before
     * reading what the change depends on.
     */
    public function lockMember(User $user): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['category_scopes:'.$this->key($user)]);
    }

    /** Forget what was read, so a change written in this request is seen by the next check. */
    public function forget(): void
    {
        $this->rows = [];
        $this->exempt = [];
        $this->pathless = [];
    }

    /**
     * The site's categories whose ltree path is not written yet (normally none), read once per
     * request and only when a limited member's check misses.
     *
     * @return array<int, true>
     */
    private function pathless(): array
    {
        $site = $this->site->id();
        if (! isset($this->pathless[$site])) {
            $this->pathless[$site] = [];
            foreach (Taxonomy::query()->whereNull('path')->pluck('id') as $id) {
                $this->pathless[$site][Cast::int($id)] = true;
            }
        }

        return $this->pathless[$site];
    }

    /**
     * The member's scoped categories and every category they reach. A LEFT JOIN on both sides: a
     * row whose category has no path still counts (the member stays limited); it reaches only itself.
     *
     * @return array{roots: list<int>, reach: array<int, true>}
     */
    private function read(User $user): array
    {
        $key = $this->key($user);
        if (isset($this->rows[$key])) {
            return $this->rows[$key];
        }

        $site = $this->site->id();
        $found = DB::select(<<<'SQL'
            SELECT s.taxonomy_id AS root_id, d.id AS reached_id
            FROM member_category_scopes AS s
            LEFT JOIN taxonomies AS t ON t.site_id = s.site_id AND t.id = s.taxonomy_id
            LEFT JOIN taxonomies AS d ON d.site_id = s.site_id AND d.path <@ t.path
            WHERE s.site_id = ? AND s.user_id = ?
            SQL, [$site, $user->getKey()]);

        $roots = [];
        $reach = [];
        foreach ($found as $row) {
            $row = (array) $row;
            $root = Cast::int($row['root_id'] ?? null);
            $roots[$root] = true;
            $reach[$root] = true;
            if (($row['reached_id'] ?? null) !== null) {
                $reach[Cast::int($row['reached_id'])] = true;
            }
        }

        return $this->rows[$key] = ['roots' => array_keys($roots), 'reach' => $reach];
    }

    private function key(User $user): string
    {
        return $this->site->id().':'.Cast::int($user->getKey());
    }
}
