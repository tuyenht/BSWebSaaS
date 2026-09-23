<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Support\Notify;

/**
 * Tells the right people when an entry moves (design.md §15.4): reviewers or publishers when
 * something waits for them, the author when their entry goes live or is sent back — with the
 * reviewer's note — and an assignee when work is handed to them. Never the person who made the
 * move. In-app notices only; each person's preferences decide. Runs inside the caller's tenant
 * transaction, so a move that rolls back takes its notices with it.
 */
final class EntryNotifier
{
    /** Waiting for review: everyone on the site holding $permission (the one who can act on it). */
    public function awaitingReview(Entry $entry, string $locale, User $actor, string $permission): void
    {
        Notify::holdersOf($permission, 'workflow.pending_review', $this->facts($entry, $locale, $actor), $actor);
    }

    public function published(Entry $entry, string $locale, User $actor): void
    {
        Notify::user($entry->author_id, 'workflow.published', $this->facts($entry, $locale, $actor), $actor);
    }

    public function returned(Entry $entry, string $locale, User $actor, ?string $note): void
    {
        Notify::user($entry->author_id, 'workflow.returned', [...$this->facts($entry, $locale, $actor), 'note' => $note ?? ''], $actor);
    }

    public function assigned(Entry $entry, string $locale, User $actor, int $assigneeId): void
    {
        Notify::user($assigneeId, 'workflow.assigned', $this->facts($entry, $locale, $actor), $actor);
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(Entry $entry, string $locale, User $actor): array
    {
        $translation = $entry->translationFor($locale);

        return [
            'entry' => $entry->ulid,
            'locale' => $locale,
            'title' => $translation !== null ? $translation->title : '',
            'actor' => $actor->name,
        ];
    }
}
