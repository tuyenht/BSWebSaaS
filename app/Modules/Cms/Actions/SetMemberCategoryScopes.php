<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\CategoryScopeRejectedException;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Set the categories a member of this site may write in — the one user-facing writer of
 * member_category_scopes. An empty list means every category, and is only ever set here, by
 * someone choosing it. The given list replaces the member's whole set, atomically.
 *
 * Who may: someone holding `users.manage`, for a member strictly below their own role level and
 * never themselves (a platform super_admin may set anyone's). Setting limits needs the site's
 * `rbac.category_scopes` feature; clearing them does not, so a site whose plan lapsed can still
 * lift limits that keep applying. A limited actor hands out only categories inside their own limits, so never "every
 * category". Members who are never limited (role level ≥ CategoryScopes::EXEMPT_LEVEL) cannot be
 * given limits; clearing leftover rows of one is allowed. Only post categories of this site count.
 * Every change is audited. Runs inside the tenant context.
 */
final readonly class SetMemberCategoryScopes
{
    public function __construct(
        private CategoryScopes $scopes,
        private RoleHierarchyGuard $levels,
        private PlatformAccess $platform,
        private Entitlement $entitlement,
        private CurrentSite $site,
    ) {}

    /**
     * @param  list<int>  $taxonomyIds  the categories the member may write in; [] = every category
     *
     * @throws AuthorizationException
     * @throws CategoryScopeRejectedException
     */
    public function handle(User $actor, User $target, array $taxonomyIds): void
    {
        app(SupportSession::class)->forbid();   // membership never changes on someone's behalf

        if (! Gate::forUser($actor)->allows('users.manage')) {
            throw new AuthorizationException('Managing members needs users.manage.');
        }
        if ($actor->id === $target->id) {
            throw new AuthorizationException('Nobody sets their own category limits.');
        }
        if ($target->roles()->doesntExist()) {
            throw new CategoryScopeRejectedException('not_member');
        }
        if (! $this->platform->isSuperAdmin($actor) && $this->levels->maxLevel($target) >= $this->levels->maxLevel($actor)) {
            throw new AuthorizationException('Category limits are set only for members below your own level.');
        }

        $ids = array_values(array_unique($taxonomyIds));
        // Clearing (back to every category) stays possible when the plan no longer includes the feature.
        if ($ids !== [] && ! $this->entitlement->featureEnabled('rbac.category_scopes')) {
            throw new CategoryScopeRejectedException('category_scopes_disabled');
        }
        if ($ids !== [] && $this->scopes->isExempt($target)) {
            throw new CategoryScopeRejectedException('target_exempt');
        }
        $this->assertCategories($ids);
        $this->assertWithinOwnScope($actor, $ids);

        $siteId = $this->site->id();
        DB::transaction(function () use ($target, $ids, $siteId): void {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['category_scopes:'.$siteId.':'.$target->id]);

            $before = array_map(
                static fn (mixed $id): int => Cast::int($id),
                MemberCategoryScope::query()->where('site_id', $siteId)->where('user_id', $target->id)->orderBy('taxonomy_id')->pluck('taxonomy_id')->all(),
            );

            MemberCategoryScope::query()
                ->where('site_id', $siteId)
                ->where('user_id', $target->id)
                ->whereNotIn('taxonomy_id', $ids)
                ->delete();
            foreach (array_diff($ids, $before) as $id) {
                MemberCategoryScope::query()->create(['user_id' => $target->id, 'taxonomy_id' => $id]);
            }
            $this->scopes->forget();

            $after = $ids;
            sort($after);
            if ($after !== $before) {
                Audit::record('cms.category_scopes.changed', [
                    'auditable_type' => User::class,
                    'auditable_id' => $target->id,
                    'meta' => ['before' => $before, 'after' => $after],
                ]);
            }
        });
    }

    /**
     * @param  list<int>  $ids
     *
     * @throws CategoryScopeRejectedException
     */
    private function assertCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // The site scope (and RLS) hide other sites' categories, so they are refused like unknown ids.
        $found = Taxonomy::query()->whereKey($ids)->where('vocabulary', 'post_cat')->count();
        if ($found !== count($ids)) {
            throw new CategoryScopeRejectedException('taxonomy_invalid');
        }
    }

    /**
     * @param  list<int>  $ids
     *
     * @throws CategoryScopeRejectedException
     */
    private function assertWithinOwnScope(User $actor, array $ids): void
    {
        if (! $this->scopes->isLimited($actor)) {
            return;
        }

        if ($ids === []) {
            throw new CategoryScopeRejectedException('outside_own_scope');
        }
        foreach ($ids as $id) {
            if (! $this->scopes->coversId($actor, $id)) {
                throw new CategoryScopeRejectedException('outside_own_scope');
            }
        }
    }
}
