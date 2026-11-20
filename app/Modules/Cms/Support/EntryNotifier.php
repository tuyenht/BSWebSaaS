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
     * Waiting for review at a workflow stage: everyone on the site holding $permission (the one the
     * stage's next move needs — approving is not editing, so no edit right is asked) whose
     * category limits reach the post — and, for a change that re-files it, its new category too:
     * the same people the review queue shows it to (CategoryScopes).
     */
    public function awaitingReview(Entry $entry, string $locale, User $actor, string $permission, ?EntryDraft $change = null): void
    {
        $this->tellWaiting($entry, $locale, $actor, $permission, $change, mustEdit: false);
    }

    /**
     * Waiting for a publisher, where no workflow governs the entry: the holders of entries.publish
     * who could act on it — publishing or applying needs the right to edit THIS entry
     * (EntryPolicy::publish): edit.any, or edit.own while being its author — inside their
     * category limits. A publisher who could not put it online is not told.
     */
    public function awaitingPublisher(Entry $entry, string $locale, User $actor, ?EntryDraft $change = null): void
    {
        $this->tellWaiting($entry, $locale, $actor, 'entries.publish', $change, mustEdit: true);
    }

    private function tellWaiting(Entry $entry, string $locale, User $actor, string $permission, ?EntryDraft $change, bool $mustEdit): void
    {
        $categories = [$entry->type === 'post' ? $entry->primaryCategory()?->id : null];
        $target = $change !== null ? ($change->content['shared']['primary_category_id'] ?? null) : null;
        if (is_int($target)) {
            $categories[] = $target;
        }
        $authorId = $entry->author_id;

        Notify::holdersOf(
            $permission, 'workflow.pending_review', $this->facts($entry, $locale, $actor, $change), $actor,
            function (Builder $members) use ($categories, $mustEdit, $authorId): void {
                app(CategoryScopes::class)->whereMayWriteIn($members, ...$categories);
                if ($mustEdit) {
                    $members->where(fn (Builder $editor) => $editor->holdingPermission('entries.edit.any')
                        ->when($authorId !== null, fn (Builder $any) => $any->orWhere(fn (Builder $own) => $own
                            ->holdingPermission('entries.edit.own')->whereKey($authorId))));
                }
            },
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

    /**
     * Approved for a later time («Hẹn giờ đăng» in the review inbox): the author learns when it goes
     * live. $time is already written in the website's clock ("12/10/2026 · 08:00").
     */
    public function scheduled(Entry $entry, string $locale, User $actor, string $time): void
    {
        Notify::user($entry->author_id, 'workflow.scheduled', [...$this->facts($entry, $locale, $actor, null), 'sentence' => 'approved', 'time' => $time], $actor);
    }

    /**
     * «Hủy hẹn giờ» on a post approved for later: the author learns it is back at the stage $step (its
     * label) to be edited and approved again, or, with $step null (the flow was switched off since), back
     * to a draft.
     */
    public function unscheduled(Entry $entry, string $locale, User $actor, ?string $step): void
    {
        $facts = $this->facts($entry, $locale, $actor, null);
        Notify::user($entry->author_id, 'workflow.scheduled', $step !== null
            ? [...$facts, 'sentence' => 'cancelled', 'step' => $step]
            : [...$facts, 'sentence' => 'cancelled_draft'], $actor);
    }

    /** A post approved for later went live at its time: its author learns it (nobody made this move). */
    public function publishedOnTime(Entry $entry, string $locale): void
    {
        Notify::user($entry->author_id, 'workflow.scheduled', [...$this->plainFacts($entry, $locale), 'sentence' => 'published']);
    }

    /**
     * The time came and an approved post could not go live ($why: `right` — the approver may no
     * longer publish it; `flow` — the post's approval flow changed since): the author and the approver
     * both learn it is waiting for review again. Nobody made this move, so nobody is left out.
     */
    public function scheduleFailed(Entry $entry, string $locale, ?int $approverId, string $why): void
    {
        $data = [...$this->plainFacts($entry, $locale), 'sentence' => 'failed_'.$why];

        foreach (array_unique(array_filter([$entry->author_id, $approverId], is_int(...))) as $userId) {
            Notify::user($userId, 'workflow.scheduled', $data);
        }
    }

    /** @return array{entry: string, locale: string, title: string} */
    private function plainFacts(Entry $entry, string $locale): array
    {
        $translation = $entry->translationFor($locale);

        return ['entry' => (string) $entry->ulid, 'locale' => $locale, 'title' => $translation !== null ? $translation->title : ''];
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
