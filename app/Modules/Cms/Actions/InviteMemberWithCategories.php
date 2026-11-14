<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\CategoryScopeRejectedException;
use App\Modules\Cms\Models\InvitationCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Taxonomy\CategoryLimitGuard;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\IssuedInvitation;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Invite an address to the current site (InviteMember) together with the categories the new member
 * may write in, on each axis — post categories and product categories (CategoryScopes) — the one
 * writer of invitation_category_scopes besides DeleteTaxonomy. An empty list means every category
 * of that axis. The limits take effect when the invitation is accepted
 * (CopyInvitationCategoryScopes), in the same transaction as the roles.
 *
 * Inviting with limits passes the same gate as setting them on a member later
 * (SetMemberCategoryScopes): the site's `rbac.category_scopes` feature, someone who could set those
 * limits by hand (CategoryLimitGuard::mayChangeLimitsOf — `users.manage` and a level above every role
 * the invitation grants), only this site's categories of the axis, and a limited inviter hands out
 * only categories inside their own limits of that axis. Roles that are never limited (level ≥
 * EXEMPT_LEVEL) take no limits. And as there, a limited inviter never hands out "every category" of
 * an axis they are limited on: inviting someone who writes posts (or manages products), they must
 * choose categories of it. Each axis is judged on its own.
 *
 *  - handle(): a new invitation, or the open one refreshed — the limits of each axis given become
 *    exactly the given set; an axis not given (null) keeps what the open invitation carries (a new
 *    one: none), held to the sender's own limits like a resend.
 *  - resend(): the invitation mailed again as it is — its limits are kept, untouched.
 *
 * Everything runs in one transaction: a refused limit sends no invitation. Runs inside the tenant
 * context.
 */
final readonly class InviteMemberWithCategories
{
    public function __construct(
        private InviteMember $invite,
        private CategoryScopes $scopes,
        private CategoryLimitGuard $limits,
        private Entitlement $entitlement,
        private CurrentSite $site,
    ) {}

    /**
     * @param  list<Role>  $roles
     * @param  list<int|string>  $taxonomyIds  the post categories the new member may write in (ids as a form sends them); [] = every one
     * @param  list<int|string>|null  $productTaxonomyIds  the product categories they may edit products in; [] = every one; null = left as the open invitation has them
     *
     * @throws AuthorizationException
     * @throws CategoryScopeRejectedException category_scopes_disabled · target_exempt · taxonomy_invalid · outside_own_scope
     * @throws InvitationRejectedException
     */
    public function handle(User $actor, string $email, array $roles, ?string $message, array $taxonomyIds, ?array $productTaxonomyIds = null): IssuedInvitation
    {
        $wanted = [CategoryScopes::POSTS => self::normalise($taxonomyIds)];
        if ($productTaxonomyIds !== null) {
            $wanted[CategoryScopes::PRODUCTS] = self::normalise($productTaxonomyIds);
        }
        $this->assertMayLimit($actor, $email, $roles, $wanted);

        $siteId = $this->site->id();

        return DB::transaction(function () use ($actor, $email, $roles, $message, $wanted, $siteId): IssuedInvitation {
            // The category tree first, as every limits writer does: a category being deleted right
            // now is refused here cleanly, never left to the foreign key.
            TaxonomyTree::lock($siteId);
            foreach ($wanted as $axis => $ids) {
                $this->assertCategories($ids, $axis);
            }

            $issued = $this->invite->handle($actor, $email, $roles, $message);
            $invitation = $issued->invitation;
            foreach (CategoryScopes::AXES as $axis) {
                if (isset($wanted[$axis])) {
                    $this->sync($actor, $invitation, $roles, $wanted[$axis], $axis);
                } else {
                    // Kept as they are (none on a new invitation): only a limited sender is held to
                    // their own limits, as on a resend.
                    $this->assertWithinOwnScope($actor, $roles, $invitation->wasRecentlyCreated ? [] : $this->rowsOf($invitation->id, $axis), $axis);
                }
            }

            return $issued;
        });
    }

    /**
     * Mail $invitation again with $roles, keeping the limits it carries.
     *
     * @param  list<Role>  $roles
     *
     * @throws AuthorizationException
     * @throws CategoryScopeRejectedException outside_own_scope
     * @throws InvitationRejectedException invalid (no longer open) · and InviteMember's
     */
    public function resend(User $actor, Invitation $invitation, array $roles): IssuedInvitation
    {
        return DB::transaction(function () use ($actor, $invitation, $roles): IssuedInvitation {
            TaxonomyTree::lock($this->site->id());
            // Kept limits are not set anew (no feature or level check): only a limited sender is held
            // to their own limits, axis by axis, so a resend is never their way around them.
            foreach (CategoryScopes::AXES as $axis) {
                $this->assertWithinOwnScope($actor, $roles, $this->rowsOf($invitation->id, $axis), $axis);
            }

            $issued = $this->invite->handle($actor, $invitation->email, $roles, $invitation->message);
            // Withdrawn or accepted meanwhile: InviteMember found no open invitation and made a new
            // one, without the limits this one carries. Nothing is sent.
            if ($issued->invitation->id !== $invitation->id) {
                throw new InvitationRejectedException('invalid');
            }

            return $issued;
        });
    }

    /**
     * Ids from a form arrive as strings: normalised once, here at the boundary (unique, sorted).
     *
     * @param  list<int|string>  $taxonomyIds
     * @return list<int>
     */
    private static function normalise(array $taxonomyIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => Cast::int($id), $taxonomyIds)));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<Role>  $roles
     * @param  array<string, list<int>>  $wanted  the ids per axis given
     *
     * @throws AuthorizationException
     * @throws CategoryScopeRejectedException
     */
    private function assertMayLimit(User $actor, string $email, array $roles, array $wanted): void
    {
        if (array_filter($wanted) !== []) {
            if (! $this->entitlement->featureEnabled('rbac.category_scopes')) {
                throw new CategoryScopeRejectedException('category_scopes_disabled');
            }
            $level = self::topLevel($roles);
            if ($level >= CategoryScopes::EXEMPT_LEVEL) {
                throw new CategoryScopeRejectedException('target_exempt');
            }
            if (! $this->mayLimit($actor, $email, $roles)) {
                throw new AuthorizationException('Category limits are set only by someone who could set them on the member.');
            }
        }
        foreach ($wanted as $axis => $ids) {
            $this->assertWithinOwnScope($actor, $roles, $ids, $axis);
        }
    }

    /**
     * A limited actor hands out only categories inside their own limits of $axis — and never "every
     * category" of it to someone the roles let work on it (write posts, manage products).
     *
     * @param  list<Role>  $roles
     * @param  list<int>  $ids
     *
     * @throws CategoryScopeRejectedException
     */
    private function assertWithinOwnScope(User $actor, array $roles, array $ids, string $axis): void
    {
        if (! $this->scopes->isLimited($actor, $axis) || self::topLevel($roles) >= CategoryScopes::EXEMPT_LEVEL) {
            return;
        }
        if ($ids === []) {
            if ($axis === CategoryScopes::PRODUCTS ? self::managesProducts($roles) : self::writesPosts($roles)) {
                throw new CategoryScopeRejectedException('outside_own_scope');
            }

            return;
        }
        foreach ($ids as $id) {
            if (! $this->scopes->coversId($actor, $id, $axis)) {
                throw new CategoryScopeRejectedException('outside_own_scope');
            }
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
     * Make the invitation's limits of $axis exactly $ids (sorted); audited when they change. The
     * other axis is left as it is. Lifting the limits of an open invitation (refreshing it with every
     * category) widens what its member will do, so it takes the same person as setting them.
     *
     * @param  list<Role>  $roles
     * @param  list<int>  $ids
     *
     * @throws AuthorizationException
     */
    private function sync(User $actor, Invitation $invitation, array $roles, array $ids, string $axis): void
    {
        // a new invitation has no limits yet: nothing to read
        $before = $invitation->wasRecentlyCreated ? [] : $this->rowsOf($invitation->id, $axis);
        if ($before === $ids) {
            return;
        }
        if ($ids === [] && ! $this->mayLimit($actor, $invitation->email, $roles)) {
            throw new AuthorizationException('Category limits are lifted only by someone who could set them on the member.');
        }

        $this->rowsOn($invitation->id, $axis)->whereNotIn('taxonomy_id', $ids)->delete();
        foreach (array_diff($ids, $before) as $id) {
            InvitationCategoryScope::query()->create(['invitation_id' => $invitation->id, 'taxonomy_id' => $id]);
        }

        Audit::record('cms.category_scopes.invited', [
            'auditable_type' => Invitation::class,
            'auditable_id' => $invitation->id,
            'meta' => ['email' => $invitation->email, 'before' => $before, 'after' => $ids, 'vocabulary' => $axis],
        ]);
    }

    /** @return list<int> the categories of $axis the invitation limits its member to, sorted */
    private function rowsOf(int $invitationId, string $axis): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => Cast::int($id),
            $this->rowsOn($invitationId, $axis)->orderBy('taxonomy_id')->pluck('taxonomy_id')->all(),
        ));
    }

    /**
     * The invitation's rows whose category is of $axis.
     *
     * @return Builder<InvitationCategoryScope>
     */
    private function rowsOn(int $invitationId, string $axis): Builder
    {
        return InvitationCategoryScope::query()
            ->where('site_id', $this->site->id())
            ->where('invitation_id', $invitationId)
            ->whereIn('taxonomy_id', Taxonomy::query()->select('id')->where('vocabulary', $axis));
    }

    /**
     * Could $actor — the person signed in — set limits on the member these roles make: the test of
     * setting them by hand (CategoryLimitGuard::mayChangeLimitsOf).
     *
     * @param  list<Role>  $roles
     */
    private function mayLimit(User $actor, string $email, array $roles): bool
    {
        return $actor->is(auth()->user())
            && $this->limits->mayChangeLimitsOf([['id' => 0, 'name' => $email, 'level' => self::topLevel($roles)]]);
    }

    /** @param  list<Role>  $roles */
    private static function topLevel(array $roles): int
    {
        $level = 0;
        foreach ($roles as $role) {
            $level = max($level, Cast::int($role->level));
        }

        return $level;
    }

    /**
     * Whether $roles let their holder write posts: any post permission beyond viewing them.
     *
     * @param  list<Role>  $roles
     */
    public static function writesPosts(array $roles): bool
    {
        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                $name = Cast::string($permission->name);
                // the permissions a category limit holds: writing posts, and changing the categories
                // themselves (CategoryLimitGuard keeps a limited member inside their own branch)
                if ((str_starts_with($name, 'entries.') && $name !== 'entries.view') || $name === 'taxonomies.manage') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether $roles let their holder change products: managing the catalog, or changing the
     * categories themselves (CategoryLimitGuard keeps a limited member inside their own branch).
     *
     * @param  list<Role>  $roles
     */
    public static function managesProducts(array $roles): bool
    {
        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                $name = Cast::string($permission->name);
                if ($name === 'catalog.manage' || $name === 'taxonomies.manage') {
                    return true;
                }
            }
        }

        return false;
    }
}
