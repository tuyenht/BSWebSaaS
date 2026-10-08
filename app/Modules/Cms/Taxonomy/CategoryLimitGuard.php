<?php

declare(strict_types=1);

namespace App\Modules\Cms\Taxonomy;

use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;

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
 */
final readonly class CategoryLimitGuard
{
    private const string VOCABULARY = 'post_cat';

    public function __construct(private CategoryScopes $scopes) {}

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
