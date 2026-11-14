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
use Illuminate\Support\Facades\DB;

/**
 * Invite an address to the current site (InviteMember) together with the post categories the new
 * member may write in — the one writer of invitation_category_scopes besides DeleteTaxonomy. An
 * empty list means every category. The limits take effect when the invitation is accepted
 * (CopyInvitationCategoryScopes), in the same transaction as the roles.
 *
 * Inviting with limits passes the same gate as setting them on a member later
 * (SetMemberCategoryScopes): the site's `rbac.category_scopes` feature, someone who could set those
 * limits by hand (CategoryLimitGuard::mayChangeLimitsOf — `users.manage` and a level above every role
 * the invitation grants), only this site's post categories, and a limited inviter hands out only
 * categories inside their own limits. Roles that are never limited (level ≥ EXEMPT_LEVEL) take no
 * limits. And as there, a limited inviter never hands out "every category": inviting someone who
 * writes posts, they must choose categories.
 *
 *  - handle(): a new invitation, or the open one refreshed — its limits become exactly the given set.
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
     * @param  list<int|string>  $taxonomyIds  the categories the new member may write in (ids as a form sends them); [] = every category
     *
     * @throws AuthorizationException
     * @throws CategoryScopeRejectedException category_scopes_disabled · target_exempt · taxonomy_invalid · outside_own_scope
     * @throws InvitationRejectedException
     */
    public function handle(User $actor, string $email, array $roles, ?string $message, array $taxonomyIds): IssuedInvitation
    {
        // Ids from a form arrive as strings: normalise them once, here at the boundary.
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => Cast::int($id), $taxonomyIds)));
        sort($ids);
        $this->assertMayLimit($actor, $email, $roles, $ids);

        $siteId = $this->site->id();

        return DB::transaction(function () use ($actor, $email, $roles, $message, $ids, $siteId): IssuedInvitation {
            // The category tree first, as every limits writer does: a category being deleted right
            // now is refused here cleanly, never left to the foreign key.
            TaxonomyTree::lock($siteId);
            $this->assertCategories($ids);

            $issued = $this->invite->handle($actor, $email, $roles, $message);
            $this->sync($actor, $issued->invitation, $roles, $ids);

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
            $ids = $this->rowsOf($invitation->id);
            // Kept limits are not set anew (no feature or level check): only a limited sender is held
            // to their own limits, so a resend is never their way around them.
            $this->assertWithinOwnScope($actor, $roles, $ids);

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
     * @param  list<Role>  $roles
     * @param  list<int>  $ids
     *
     * @throws AuthorizationException
     * @throws CategoryScopeRejectedException
     */
    private function assertMayLimit(User $actor, string $email, array $roles, array $ids): void
    {
        if ($ids !== []) {
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
        $this->assertWithinOwnScope($actor, $roles, $ids);
    }

    /**
     * A limited actor hands out only categories inside their own limits — and never "every category"
     * to someone the roles let write posts.
     *
     * @param  list<Role>  $roles
     * @param  list<int>  $ids
     *
     * @throws CategoryScopeRejectedException
     */
    private function assertWithinOwnScope(User $actor, array $roles, array $ids): void
    {
        if (! $this->scopes->isLimited($actor) || self::topLevel($roles) >= CategoryScopes::EXEMPT_LEVEL) {
            return;
        }
        if ($ids === []) {
            if (self::writesPosts($roles)) {
                throw new CategoryScopeRejectedException('outside_own_scope');
            }

            return;
        }
        foreach ($ids as $id) {
            if (! $this->scopes->coversId($actor, $id)) {
                throw new CategoryScopeRejectedException('outside_own_scope');
            }
        }
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
        $found = Taxonomy::query()->whereKey($ids)->where('vocabulary', CategoryLimitGuard::VOCABULARY)->count();
        if ($found !== count($ids)) {
            throw new CategoryScopeRejectedException('taxonomy_invalid');
        }
    }

    /**
     * Make the invitation's limits exactly $ids (sorted); audited when they change. Lifting the
     * limits of an open invitation (refreshing it with every category) widens what its member will
     * do, so it takes the same person as setting them.
     *
     * @param  list<Role>  $roles
     * @param  list<int>  $ids
     *
     * @throws AuthorizationException
     */
    private function sync(User $actor, Invitation $invitation, array $roles, array $ids): void
    {
        // a new invitation has no limits yet: nothing to read
        $before = $invitation->wasRecentlyCreated ? [] : $this->rowsOf($invitation->id);
        if ($before === $ids) {
            return;
        }
        if ($ids === [] && ! $this->mayLimit($actor, $invitation->email, $roles)) {
            throw new AuthorizationException('Category limits are lifted only by someone who could set them on the member.');
        }

        $siteId = $this->site->id();
        InvitationCategoryScope::query()
            ->where('site_id', $siteId)
            ->where('invitation_id', $invitation->id)
            ->whereNotIn('taxonomy_id', $ids)
            ->delete();
        foreach (array_diff($ids, $before) as $id) {
            InvitationCategoryScope::query()->create(['invitation_id' => $invitation->id, 'taxonomy_id' => $id]);
        }

        Audit::record('cms.category_scopes.invited', [
            'auditable_type' => Invitation::class,
            'auditable_id' => $invitation->id,
            'meta' => ['email' => $invitation->email, 'before' => $before, 'after' => $ids],
        ]);
    }

    /** @return list<int> the categories the invitation limits its member to, sorted */
    private function rowsOf(int $invitationId): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => Cast::int($id),
            InvitationCategoryScope::query()
                ->where('site_id', $this->site->id())
                ->where('invitation_id', $invitationId)
                ->orderBy('taxonomy_id')
                ->pluck('taxonomy_id')
                ->all(),
        ));
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
                if (str_starts_with($name, 'entries.') && $name !== 'entries.view') {
                    return true;
                }
            }
        }

        return false;
    }
}
