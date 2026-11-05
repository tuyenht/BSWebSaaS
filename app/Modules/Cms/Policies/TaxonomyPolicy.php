<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Taxonomy\CategoryLimitGuard;
use App\Modules\Identity\Models\User;

/**
 * Defense layer #2 for categories: one coarse permission, `taxonomies.manage`, team-scoped by the
 * spatie guard (= current site), plus the member's category limits (CategoryScopes) for post
 * categories. Every category write path (Cms\Actions\Taxonomies) asks `manage` for the person
 * signed in, and the Chuyên mục screen asks through this policy too; super_admin passes through
 * Gate::before.
 *
 * The per-category abilities answer what the screen may offer on a row. The write path holds the
 * same line with a reason the screen can explain (CategoryLimitGuard, outside_category_limits):
 *  - update (names, templates, picture): only a category inside the member's limits;
 *  - move and delete (which merge is): inside them and never a category the limits name, which
 *    would move or remove the limit itself.
 * Other vocabularies (product categories, facet terms) are not limited.
 */
final readonly class TaxonomyPolicy
{
    public function __construct(private CategoryScopes $scopes) {}

    /** Any change to the site's categories. */
    public function manage(User $user): bool
    {
        return $user->can('taxonomies.manage');
    }

    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Taxonomy $taxonomy): bool
    {
        return $this->manage($user) && $this->inside($user, $taxonomy);
    }

    public function move(User $user, Taxonomy $taxonomy): bool
    {
        return $this->update($user, $taxonomy) && ! $this->named($user, $taxonomy);
    }

    public function delete(User $user, Taxonomy $taxonomy): bool
    {
        return $this->move($user, $taxonomy);
    }

    /** Is the category inside the member's limits (always, for a member who is not limited)? */
    private function inside(User $user, Taxonomy $taxonomy): bool
    {
        return $taxonomy->vocabulary !== CategoryLimitGuard::VOCABULARY || $this->scopes->coversId($user, $taxonomy->id);
    }

    /** Is the category one the member's limits name? */
    private function named(User $user, Taxonomy $taxonomy): bool
    {
        return $taxonomy->vocabulary === CategoryLimitGuard::VOCABULARY
            && in_array($taxonomy->id, $this->scopes->rootIds($user) ?? [], true);
    }
}
