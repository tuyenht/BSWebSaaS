<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\CategoryScopeRejectedException;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Set the categories a member of this site may write in, on one axis — posts (post categories) or
 * products (product categories) — the one user-facing writer of member_category_scopes. An empty
 * list means every category of that axis, and is only ever set here, by someone choosing it. The
 * given list replaces the member's set ON THAT AXIS, atomically; the other axis is left as it is
 * (a row's axis is the vocabulary of its category, CategoryScopes).
 *
 * Who may: someone holding `users.manage`, for a member strictly below their own role level and
 * never themselves; platform staff set nobody's (MembershipLock). Setting limits needs the site's
 * `rbac.category_scopes` feature; clearing them does not, so a site whose plan lapsed can still
 * lift limits that keep applying. A limited actor hands out only categories inside their own limits, so never "every
 * category" — judged on the same axis: a member limited on products only hands out any post limits.
 * Members who are never limited (role level ≥ CategoryScopes::EXEMPT_LEVEL) cannot be given limits;
 * clearing leftover rows of one is allowed. Only this site's categories of the axis count. Every
 * change is audited. Runs inside the tenant context.
 */
final readonly class SetMemberCategoryScopes
{
    public function __construct(
        private CategoryScopes $scopes,
        private RoleHierarchyGuard $levels,
        private Entitlement $entitlement,
        private CurrentSite $site,
    ) {}

    /**
     * @param  list<int|string>  $taxonomyIds  the categories of $axis the member may write in (ids as a form sends them); [] = every category of it
     * @param  string  $axis  CategoryScopes::POSTS or CategoryScopes::PRODUCTS
     *
     * @throws AuthorizationException
     * @throws CategoryScopeRejectedException
     */
    public function handle(User $actor, User $target, array $taxonomyIds, string $axis = CategoryScopes::POSTS): void
    {
        if (! CategoryScopes::isAxis($axis)) {
            throw new CategoryScopeRejectedException('taxonomy_invalid');
        }
        app(MembershipLock::class)->authorize($actor, $this->site->id(), 'users.manage');   // never on someone's behalf, from outside the site, or without users.manage there
        if ($actor->id === $target->id) {
            throw new AuthorizationException('Nobody sets their own category limits.');
        }

        // Ids from a form arrive as strings: normalise them once, here at the boundary.
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => Cast::int($id), $taxonomyIds)));
        // Clearing (back to every category) stays possible when the plan no longer includes the feature.
        if ($ids !== [] && ! $this->entitlement->featureEnabled('rbac.category_scopes')) {
            throw new CategoryScopeRejectedException('category_scopes_disabled');
        }
        $this->assertWithinOwnScope($actor, $ids, $axis);

        $siteId = $this->site->id();
        DB::transaction(function () use ($actor, $target, $ids, $siteId, $axis): void {
            // The category tree first (a category being deleted right now is refused cleanly, never
            // left to the foreign key), then the member's own limits lock, which a role change
            // takes too (ForgetMemberCategoryScopes). Everything the change depends on is read
            // after both: a promotion committed a moment ago is seen here, not missed.
            TaxonomyTree::lock($siteId);
            $this->scopes->lockMember($target);
            $this->scopes->forget();

            $this->assertTarget($actor, $target, $ids);
            $this->assertCategories($ids, $axis);

            $before = array_map(
                static fn (mixed $id): int => Cast::int($id),
                $this->rowsOn($axis, $siteId, $target)->orderBy('taxonomy_id')->pluck('taxonomy_id')->all(),
            );
            $after = $ids;
            sort($after);
            if ($after === $before) {
                return;   // nothing changes: nothing written, nothing audited
            }

            // Only this axis's rows: setting post limits never touches product limits, and back.
            $this->rowsOn($axis, $siteId, $target)->whereNotIn('taxonomy_id', $ids)->delete();
            foreach (array_diff($ids, $before) as $id) {
                MemberCategoryScope::query()->create(['user_id' => $target->id, 'taxonomy_id' => $id]);
            }
            $this->scopes->forget();

            Audit::record('cms.category_scopes.changed', [
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'meta' => ['before' => $before, 'after' => $after, 'vocabulary' => $axis],
            ]);
        });
    }

    /**
     * The target's rows whose category is of $axis.
     *
     * @return Builder<MemberCategoryScope>
     */
    private function rowsOn(string $axis, int $siteId, User $target): Builder
    {
        return MemberCategoryScope::query()
            ->where('site_id', $siteId)
            ->where('user_id', $target->id)
            ->whereIn('taxonomy_id', Taxonomy::query()->select('id')->where('vocabulary', $axis));
    }

    /**
     * The target as they are now, read under the lock: still a member here, strictly below the
     * actor, and — when limits are being set — not someone who is never limited.
     *
     * @param  list<int>  $ids
     *
     * @throws AuthorizationException
     * @throws CategoryScopeRejectedException
     */
    private function assertTarget(User $actor, User $target, array $ids): void
    {
        if ($target->roles()->doesntExist()) {
            throw new CategoryScopeRejectedException('not_member');
        }
        if ($this->levels->maxLevel($target) >= $this->levels->maxLevel($actor)) {
            throw new AuthorizationException('Category limits are set only for members below your own level.');
        }
        if ($ids !== [] && $this->scopes->isExempt($target)) {
            throw new CategoryScopeRejectedException('target_exempt');
        }
    }

    /**
     * @param  list<int>  $ids
     *
     * @throws CategoryScopeRejectedException
     */
    private function assertCategories(array $ids, string $axis): void
    {
        if ($ids === []) {
            return;
        }

        // The site scope (and RLS) hide other sites' categories, so they are refused like unknown ids.
        $found = Taxonomy::query()->whereKey($ids)->where('vocabulary', $axis)->count();
        if ($found !== count($ids)) {
            throw new CategoryScopeRejectedException('taxonomy_invalid');
        }
    }

    /**
     * @param  list<int>  $ids
     *
     * @throws CategoryScopeRejectedException
     */
    private function assertWithinOwnScope(User $actor, array $ids, string $axis): void
    {
        if (! $this->scopes->isLimited($actor, $axis)) {
            return;
        }

        if ($ids === []) {
            throw new CategoryScopeRejectedException('outside_own_scope');
        }
        foreach ($ids as $id) {
            if (! $this->scopes->coversId($actor, $id, $axis)) {
                throw new CategoryScopeRejectedException('outside_own_scope');
            }
        }
    }
}
