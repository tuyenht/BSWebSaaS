<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
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

    public function __construct(
        private readonly CurrentSite $site,
        private readonly RoleHierarchyGuard $levels,
        private readonly PlatformAccess $platform,
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
        return array_intersect(TaxonomyTree::ancestorsOf($primaryId), $read['roots']) !== [];
    }

    /** Forget what was read, so a change written in this request is seen by the next check. */
    public function forget(): void
    {
        $this->rows = [];
        $this->exempt = [];
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
