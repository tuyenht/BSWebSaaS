<?php

namespace App\Modules\Cms\Policies;

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;

/**
 * Defense layer #2 (RBAC) for content entries — design.md §15.1/§15.4. Maps the
 * entries.* permission registry to abilities, with own/any scoping: `.own` requires
 * authorship (author_id === user), `.any` does not. A platform super_admin
 * short-circuits every gate via RbacServiceProvider's Gate::before, so it is not
 * re-checked here. Per-site authority is enforced upstream by the spatie team
 * context (team = site_id), so these checks are already scoped to the current site.
 */
final class EntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('entries.view');
    }

    public function view(User $user): bool
    {
        return $user->can('entries.view');
    }

    public function create(User $user): bool
    {
        return $user->can('entries.create');
    }

    public function update(User $user, Entry $entry): bool
    {
        return $user->can('entries.edit.any')
            || ($user->can('entries.edit.own') && $this->owns($user, $entry));
    }

    public function delete(User $user, Entry $entry): bool
    {
        return $user->can('entries.delete.any')
            || ($user->can('entries.delete.own') && $this->owns($user, $entry));
    }

    /** Restore from Trash — same authority as delete (you can undo what you could remove). */
    public function restore(User $user, Entry $entry): bool
    {
        return $this->delete($user, $entry);
    }

    /** Permanently purge from Trash — same authority as delete; irreversible, so also FORCE-gated in UI. */
    public function forceDelete(User $user, Entry $entry): bool
    {
        return $this->delete($user, $entry);
    }

    /** Submit for review (workflow Tầng-1): the path for authors without publish rights. */
    public function submit(User $user): bool
    {
        return $user->can('entries.submit');
    }

    /** Publish straight through (workflow Tầng-1): only grants carrying entries.publish. */
    public function publish(User $user): bool
    {
        return $user->can('entries.publish');
    }

    private function owns(User $user, Entry $entry): bool
    {
        return $entry->author_id !== null && $entry->author_id === $user->id;
    }
}
