<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;

/**
 * Defense layer #2 for the reusable block library. Permissions are team-scoped by the spatie guard
 * (= current site); super_admin passes through Gate::before.
 *
 *  - Seeing the library (viewAny, view): whoever manages blocks, and whoever may place one, so the
 *    block picker works for them: the menu screen (`menus.manage`) and the page editor (writing a
 *    page: `entries.create`, `entries.edit.own`, `entries.edit.any`). An author or contributor may
 *    therefore list the blocks and place one in their own page; placing a block does not change it.
 *  - Every change to a block (create, update, publish, duplicate, delete): `blocks.manage`, and only
 *    for a member not limited to some categories (CategoryScopes): one change shows on every page and
 *    menu that uses the block, including pages outside those limits. A limited member still sees and
 *    places blocks like any placer.
 *
 * Every action in Cms\Actions\ContentBlocks asks this policy first (ContentBlockPolicyChokepointTest).
 */
final class ContentBlockPolicy
{
    /** The permissions that let someone place a block somewhere, and so see the library. */
    private const array PLACERS = ['menus.manage', 'entries.create', 'entries.edit.own', 'entries.edit.any'];

    public function __construct(private CategoryScopes $scopes) {}

    /** Any change to the website's blocks. */
    public function manage(User $user): bool
    {
        return $user->can('blocks.manage') && ! $this->scopes->isLimited($user);
    }

    public function viewAny(User $user): bool
    {
        if ($this->manage($user)) {
            return true;
        }
        foreach (self::PLACERS as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, ContentBlock $block): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, ContentBlock $block): bool
    {
        return $this->manage($user);
    }

    public function publish(User $user, ContentBlock $block): bool
    {
        return $this->manage($user);
    }

    public function duplicate(User $user, ContentBlock $block): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, ContentBlock $block): bool
    {
        return $this->manage($user);
    }
}
