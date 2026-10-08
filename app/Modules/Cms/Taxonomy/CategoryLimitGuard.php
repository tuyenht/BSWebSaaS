<?php

declare(strict_types=1);

namespace App\Modules\Cms\Taxonomy;

use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Keeps a member limited to some post categories (CategoryScopes) from changing the category tree
 * outside those limits — otherwise moving another category under one of theirs would hand them
 * every post filed there. For such a member, every category action stays inside the limits, the
 * source AND the destination:
 *
 *  - create: only under a category inside them (a new top-level category is outside);
 *  - rename / templates: only a category inside them;
 *  - move or reorder: only a category inside them, onto a parent inside them, and never one of the
 *    categories the limits name (that would move the limit itself);
 *  - delete or merge: only a category inside them, never one the limits name, merged only into a
 *    category inside them.
 *
 * The person is the one signed in, as for the audit trail; with nobody signed in (the console,
 * provisioning) there is nobody to limit. Other vocabularies (product categories, facet terms) are
 * not limited. Refusals are TaxonomyChangeRejectedException('outside_category_limits'). Call inside
 * the action's tree lock, on the rows as reloaded there.
 *
 * A category change can also change OTHER members' limits: a move takes a branch into or out of
 * their reach, a merge moves their limit onto the target. One rule decides both (mayChangeLimitsOf):
 * only someone who could set those limits by hand may cause it.
 */
final readonly class CategoryLimitGuard
{
    private const string VOCABULARY = 'post_cat';

    public function __construct(
        private CategoryScopes $scopes,
        private PlatformAccess $platform,
        private RoleHierarchyGuard $levels,
    ) {}

    /**
     * Refuse a move that takes a branch into or out of the reach of a limited member, unless the
     * signed-in person could set that member's limits by hand.
     *
     * @param  list<int>  $above  the ids above the moved category now
     * @param  list<int>  $newAbove  the new parent and the ids above it
     *
     * @throws TaxonomyChangeRejectedException changes_member_limits
     */
    public function reach(array $above, array $newAbove): void
    {
        if (! $this->mayChangeLimitsOf($this->scopes->membersReachChangedBy($above, $newAbove))) {
            throw new TaxonomyChangeRejectedException('changes_member_limits');
        }
    }

    /**
     * Whether the signed-in person may change these members' limits as a side effect of changing
     * categories: they hold `users.manage` and a role level strictly above every member concerned
     * (a platform super_admin may). Members who are never limited (level ≥ EXEMPT_LEVEL) do not
     * count — their rows are ignored anyway. With nobody signed in, nobody vouches for the change.
     *
     * @param  list<array{id: int, name: string, level: int}>  $members
     */
    public function mayChangeLimitsOf(array $members): bool
    {
        $members = array_filter($members, static fn (array $member): bool => $member['level'] < CategoryScopes::EXEMPT_LEVEL);
        if ($members === []) {
            return true;
        }

        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }
        if ($this->platform->isSuperAdmin($actor)) {
            return true;
        }
        if (! Gate::forUser($actor)->allows('users.manage')) {
            return false;
        }

        $own = $this->levels->maxLevel($actor);
        foreach ($members as $member) {
            if ($member['level'] >= $own) {
                return false;
            }
        }

        return true;
    }

    /** @throws TaxonomyChangeRejectedException */
    public function create(string $vocabulary, ?Taxonomy $parent): void
    {
        $member = $this->limited($vocabulary);
        if ($member !== null && ($parent === null || ! $this->scopes->coversId($member, $parent->id))) {
            $this->refuse();
        }
    }

    /** @throws TaxonomyChangeRejectedException */
    public function change(Taxonomy $taxonomy): void
    {
        $member = $this->limited($taxonomy->vocabulary);
        if ($member !== null && ! $this->scopes->coversId($member, $taxonomy->id)) {
            $this->refuse();
        }
    }

    /** @throws TaxonomyChangeRejectedException */
    public function move(Taxonomy $taxonomy, ?Taxonomy $parent): void
    {
        $member = $this->limited($taxonomy->vocabulary);
        if ($member === null) {
            return;
        }

        if (! $this->insideAndNotNamed($member, $taxonomy) || $parent === null || ! $this->scopes->coversId($member, $parent->id)) {
            $this->refuse();
        }
    }

    /** @throws TaxonomyChangeRejectedException */
    public function delete(Taxonomy $taxonomy, ?int $targetId): void
    {
        $member = $this->limited($taxonomy->vocabulary);
        if ($member === null) {
            return;
        }

        if (! $this->insideAndNotNamed($member, $taxonomy) || ($targetId !== null && ! $this->scopes->coversId($member, $targetId))) {
            $this->refuse();
        }
    }

    private function insideAndNotNamed(User $member, Taxonomy $taxonomy): bool
    {
        return $this->scopes->coversId($member, $taxonomy->id)
            && ! in_array($taxonomy->id, $this->scopes->rootIds($member) ?? [], true);
    }

    /** The signed-in member when they are limited and the vocabulary is the limited one; else null. */
    private function limited(string $vocabulary): ?User
    {
        $user = Auth::user();

        return $vocabulary === self::VOCABULARY && $user instanceof User && $this->scopes->isLimited($user) ? $user : null;
    }

    /** @throws TaxonomyChangeRejectedException */
    private function refuse(): never
    {
        throw new TaxonomyChangeRejectedException('outside_category_limits');
    }
}
