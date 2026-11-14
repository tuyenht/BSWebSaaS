<?php

declare(strict_types=1);

namespace App\Modules\Cms\Taxonomy;

use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps a member limited to some categories (CategoryScopes — post categories or product categories,
 * each an axis of its own) from changing the category tree outside those limits — otherwise moving
 * another category under one of theirs would hand them every post or product filed there. For such a
 * member, every category action on a category of an axis they are limited on stays inside their
 * limits of that axis, the source AND the destination:
 *
 *  - create: only under a category inside them (a new top-level category is outside);
 *  - rename / templates: only a category inside them;
 *  - move or reorder: only a category inside them, onto a parent inside them, and never one of the
 *    categories the limits name (that would move the limit itself);
 *  - delete or merge: only a category inside them, never one the limits name, merged only into a
 *    category inside them.
 *
 * A tree may mix vocabularies (product categories under a post category). A move or a delete takes
 * the whole branch with it, so every category of the branch is judged on its own axis: one of an axis
 * the member is limited on must be inside those limits, whatever the vocabulary of the category they
 * act on.
 *
 * The person is the one signed in, as for the audit trail; with nobody signed in (the console,
 * provisioning) there is nobody to limit. Other vocabularies (facet terms…) are not limited. Refusals
 * are TaxonomyChangeRejectedException('outside_category_limits'). Call inside the action's tree lock,
 * on the rows as reloaded there.
 *
 * A category change can also change OTHER members' limits: a move takes a branch into or out of
 * their reach, a merge moves their limit onto the target. One rule decides both (mayChangeLimitsOf):
 * only someone who could set those limits by hand may cause it.
 */
final readonly class CategoryLimitGuard
{
    /** The vocabulary of the post-category axis of member limits (CategoryScopes::POSTS). */
    public const string VOCABULARY = CategoryScopes::POSTS;

    public function __construct(
        private CategoryScopes $scopes,
        private MembershipLock $membership,
        private RoleHierarchyGuard $levels,
        private CurrentSite $site,
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
     * — the same test as setting them by hand (SetMemberCategoryScopes). Platform staff are judged by
     * the role they hold on this website like anyone else, never by the platform bypass, and never
     * from outside the website or on someone's behalf (MembershipLock). Members who are never limited
     * (level ≥ EXEMPT_LEVEL) do not count — their rows are ignored anyway. With nobody signed in, or
     * no website, nobody vouches for the change.
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
        if (! $actor instanceof User || ! $this->site->hasId()) {
            return false;
        }
        $siteId = $this->site->id();
        if ($this->membership->reason($actor, $siteId) !== null || ! $this->membership->permits($actor, $siteId, 'users.manage')) {
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
        if ($member !== null && ($parent === null || ! $this->scopes->coversId($member, $parent->id, $vocabulary))) {
            $this->refuse();
        }
    }

    /** @throws TaxonomyChangeRejectedException */
    public function change(Taxonomy $taxonomy): void
    {
        $member = $this->limited($taxonomy->vocabulary);
        if ($member !== null && ! $this->scopes->coversId($member, $taxonomy->id, $taxonomy->vocabulary)) {
            $this->refuse();
        }
    }

    /** @throws TaxonomyChangeRejectedException */
    public function move(Taxonomy $taxonomy, ?Taxonomy $parent): void
    {
        $this->branch($taxonomy->id);
        $member = $this->limited($taxonomy->vocabulary);
        if ($member === null) {
            return;
        }

        if (! $this->insideAndNotNamed($member, $taxonomy) || $parent === null || ! $this->scopes->coversId($member, $parent->id, $taxonomy->vocabulary)) {
            $this->refuse();
        }
    }

    /**
     * @param  bool  $withBranch  the sub-categories are deleted too (else they are lifted to the parent, staying where they were reached from)
     *
     * @throws TaxonomyChangeRejectedException
     */
    public function delete(Taxonomy $taxonomy, ?int $targetId, bool $withBranch = false): void
    {
        if ($withBranch) {
            $this->branch($taxonomy->id);
        }
        $member = $this->limited($taxonomy->vocabulary);
        if ($member === null) {
            return;
        }

        if (! $this->insideAndNotNamed($member, $taxonomy) || ($targetId !== null && ! $this->scopes->coversId($member, $targetId, $taxonomy->vocabulary))) {
            $this->refuse();
        }
    }

    /**
     * Every category of the branch under $rootId (itself included) that is of an axis the signed-in
     * member is limited on must be inside their limits of that axis. One read of the branch, only
     * for a member limited on some axis.
     *
     * @throws TaxonomyChangeRejectedException
     */
    private function branch(int $rootId): void
    {
        $user = Auth::user();
        if (! $user instanceof User || ! $this->scopes->isLimitedOnAnyAxis($user)) {
            return;
        }

        $ids = array_keys(TaxonomyTree::branchOf($rootId));
        foreach (CategoryScopes::AXES as $axis) {
            if (! $this->scopes->isLimited($user, $axis)) {
                continue;
            }
            $outside = array_diff(
                array_map(static fn (mixed $id): int => Cast::int($id), Taxonomy::query()->whereKey($ids)->where('vocabulary', $axis)->pluck('id')->all()),
                $this->scopes->allowedTaxonomyIds($user, $axis) ?? [],
            );
            if ($outside !== []) {
                $this->refuse();
            }
        }
    }

    private function insideAndNotNamed(User $member, Taxonomy $taxonomy): bool
    {
        return $this->scopes->coversId($member, $taxonomy->id, $taxonomy->vocabulary)
            && ! in_array($taxonomy->id, $this->scopes->rootIds($member, $taxonomy->vocabulary) ?? [], true);
    }

    /** The signed-in member when the vocabulary is an axis of member limits and they are limited on it; else null. */
    private function limited(string $vocabulary): ?User
    {
        $user = Auth::user();

        return CategoryScopes::isAxis($vocabulary) && $user instanceof User && $this->scopes->isLimited($user, $vocabulary) ? $user : null;
    }

    /** @throws TaxonomyChangeRejectedException */
    private function refuse(): never
    {
        throw new TaxonomyChangeRejectedException('outside_category_limits');
    }
}
