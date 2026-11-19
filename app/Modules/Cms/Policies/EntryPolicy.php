<?php

namespace App\Modules\Cms\Policies;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryReviewReach;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Defense layer #2 (RBAC) for content entries — design.md §15.1/§15.4. Maps the
 * entries.* permission registry to abilities, with own/any scoping: `.own` requires
 * authorship (author_id === user), `.any` does not. A platform super_admin
 * short-circuits every gate via RbacServiceProvider's Gate::before, so it is not
 * re-checked here. Per-site authority is enforced upstream by the spatie team
 * context (team = site_id), so these checks are already scoped to the current site.
 *
 * Category limits (CategoryScopes) come on top of every write: a member limited to some categories
 * may change only posts whose PRIMARY category lies inside them, and no page (a page has no
 * category). They hold whether or not the site's plan still includes the feature. Viewing is never
 * limited. A new entry has no stored category yet, so `create` only opens the editor; where the
 * category is chosen, `fileUnder` judges it.
 */
final class EntryPolicy
{
    public function __construct(
        private readonly CategoryScopes $scopes,
        private readonly EntryReviewReach $reviewReach,
    ) {}

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

    /**
     * May the member file content of $type under $categoryId (a post's chosen primary category; a
     * page has none)? Asked where the category is set — a new entry's first save, a move to another
     * category, a copy — with the sentence a limited member sees when the answer is no.
     */
    public function fileUnder(User $user, string $type, ?int $categoryId): Response
    {
        $inside = $type === 'post'
            ? $this->scopes->coversId($user, $categoryId)
            : ! $this->scopes->isLimited($user);

        return $inside ? Response::allow() : Response::deny($this->scopes->refusal($user));
    }

    public function update(User $user, Entry $entry): bool
    {
        return ($user->can('entries.edit.any')
            || ($user->can('entries.edit.own') && $this->owns($user, $entry)))
            && $this->scopes->coversEntry($user, $entry);
    }

    /**
     * «Xem trước»: the admin's preview of this entry as the website would draw it, whatever its status.
     * Whoever may open it in the editor (update); or a reviewer or approver, only while it waits at a
     * step they may act on — exactly what their review inbox shows them (EntryReviewReach), never a
     * colleague's unsent draft or an entry parked at another person's step. Never only on the
     * strength of seeing the list.
     */
    public function preview(User $user, Entry $entry): bool
    {
        return $user->can('entries.view')
            && ($this->update($user, $entry) || ($this->review($user) && $this->reviewReach->waitsFor($user, $entry)));
    }

    public function delete(User $user, Entry $entry): bool
    {
        return ($user->can('entries.delete.any')
            || ($user->can('entries.delete.own') && $this->owns($user, $entry)))
            && $this->scopes->coversEntry($user, $entry);
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

    /**
     * Send for review: the path for authors without publish rights. Sending is part of working
     * on THIS entry, so it needs entries.submit AND the right to edit it — edit.any, or edit.own
     * while being its author (an entry with no author: only edit.any). Without the second half an
     * author could park a colleague's half-written draft in the review queue. The same rule
     * guards every workflow move whose permission is entries.submit (EntryWorkflowResolver::mayApply).
     */
    public function submit(User $user, Entry $entry): bool
    {
        return $user->can('entries.submit') && $this->update($user, $entry);
    }

    /**
     * Publish straight through (workflow Tầng-1), and the other status moves of this entry (draft,
     * archived, schedule, applying its pending change): entries.publish AND the right to edit this
     * entry — edit.any, or edit.own on one's own entry — inside the member's category limits
     * (update). Putting content online or taking it off is part of working on it, so a publisher
     * cannot do it to an entry they could not change. An entry not saved yet is judged with its
     * future author set (the editor does). Approvers of a workflow do not come through here: their
     * moves are judged by the workflow engine and EntryWorkflowResolver::mayApply.
     */
    public function publish(User $user, Entry $entry): bool
    {
        return $user->can('entries.publish') && $this->update($user, $entry);
    }

    /**
     * Open the review inbox (workflow Tầng-2, screen 3). Deliberately NOT tied to `update`:
     * approving is not editing, and the editorial template exists to separate the two. It is
     * also not `entries.view` — that is the right to read the content list, which every viewer
     * has and which must not put someone in a queue of work they cannot action.
     */
    public function review(User $user): bool
    {
        return $user->can('entries.review') || $user->can('entries.approve');
    }

    private function owns(User $user, Entry $entry): bool
    {
        return $entry->author_id !== null && $entry->author_id === $user->id;
    }
}
