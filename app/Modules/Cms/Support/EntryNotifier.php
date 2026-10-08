<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Support\Notify;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tells the right people when an entry moves (design.md §15.4): reviewers or publishers when
 * something waits for them, the author when their entry goes live or is sent back — with the
 * reviewer's note — and an assignee when work is handed to them. Never the person who made the
 * move. In-app notices only; each person's preferences decide. Runs inside the caller's tenant
 * transaction, so a move that rolls back takes its notices with it.
 *
 * A pending change to a live entry moves through the same stages with the same kinds of notice.
 * Passing it as $change adds the `change` fact (the sentence then speaks of a change to the
 * post, which stays online), takes the title the change gives the post, and addresses "the
 * author" as the person who last wrote the change rather than the post's author.
 */
final class EntryNotifier
{
    /**
     * Waiting for review: everyone on the site holding $permission (the one who can act on it) whose
     * category limits reach the post — and, for a change that re-files it, its new category too:
     * the same people the review queue shows it to (CategoryScopes).
     */
    public function awaitingReview(Entry $entry, string $locale, User $actor, string $permission, ?EntryDraft $change = null): void
    {
        $categories = [$entry->type === 'post' ? $entry->primaryCategory()?->id : null];
        $target = $change !== null ? ($change->content['shared']['primary_category_id'] ?? null) : null;
        if (is_int($target)) {
            $categories[] = $target;
        }

        Notify::holdersOf(
            $permission, 'workflow.pending_review', $this->facts($entry, $locale, $actor, $change), $actor,
            fn (Builder $members) => app(CategoryScopes::class)->whereMayWriteIn($members, ...$categories),
        );
    }

    public function published(Entry $entry, string $locale, User $actor, ?EntryDraft $change = null): void
    {
        Notify::user($this->author($entry, $change), 'workflow.published', $this->facts($entry, $locale, $actor, $change), $actor);
    }

    public function returned(Entry $entry, string $locale, User $actor, ?string $note, ?EntryDraft $change = null): void
    {
        Notify::user($this->author($entry, $change), 'workflow.returned', [...$this->facts($entry, $locale, $actor, $change), 'note' => $note ?? ''], $actor);
    }

    public function assigned(Entry $entry, string $locale, User $actor, int $assigneeId, ?EntryDraft $change = null): void
    {
        Notify::user($assigneeId, 'workflow.assigned', $this->facts($entry, $locale, $actor, $change), $actor);
    }

    private function author(Entry $entry, ?EntryDraft $change): ?int
    {
        if ($change === null) {
            return $entry->author_id;
        }

        return $change->updated_by ?? $change->created_by ?? $entry->author_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(Entry $entry, string $locale, User $actor, ?EntryDraft $change): array
    {
        $changed = $change !== null ? ($change->content['locales'][$locale]['title'] ?? null) : null;
        $translation = is_string($changed) ? null : $entry->translationFor($locale);
        $title = is_string($changed) ? $changed : ($translation !== null ? $translation->title : '');

        return [
            'entry' => $entry->ulid,
            'locale' => $locale,
            'title' => $title,
            'actor' => $actor->name,
        ] + ($change !== null ? ['change' => true] : []);
    }
}
