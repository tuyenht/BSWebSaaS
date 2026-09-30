<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Support\EntryContentWriter;
use App\Modules\Cms\Support\EntryDraftApplier;
use App\Modules\Cms\Support\EntryNotifier;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Saves an edit of live content into the entry's one pending change instead of onto the website
 * (design.md §8): only an approval (workflow on) or a publisher applying it (workflow off) changes
 * what visitors see. Guarded like an edit (EntryPolicy::update).
 *
 * The change holds whole languages: the first save of a language copies what is live and lays the
 * edit over it, so applying later writes exactly what the editor showed. Fields every language
 * shares (template, cover, meta, primary category) are held once. Live rows are never written and
 * no revision is recorded — nothing reached visitors.
 *
 * A change that was sent for review is what the reviewer reads, so it cannot be edited until it is
 * returned (change_locked). The entry row is locked before the change is read, so two saves, or a
 * save and an approval, queue instead of both reading the old change.
 */
final readonly class SaveEntryDraft
{
    public function __construct(
        private EntryWorkflowResolver $workflows,
        private EntryDraftApplier $applier,
        private EntryContentWriter $writer,
        private EntryNotifier $notifier,
        private SiteLocale $locales,
    ) {}

    /**
     * Whether a save by $actor must go into the pending change rather than onto the website: the
     * entry is live, and an approval workflow governs it, or it already has a pending change (which
     * would otherwise overwrite the save when applied), or $actor may not publish (only the
     * publish right or an approval changes what visitors see).
     */
    public function holdsBack(Entry $entry, User $actor): bool
    {
        if ($entry->status !== 'published' || $entry->trashed()) {
            return false;
        }

        return $this->workflows->forEntry($entry) !== null
            || EntryDraft::query()->where('entry_id', $entry->id)->exists()
            || ! Gate::forUser($actor)->allows('publish', $entry);
    }

    /**
     * Save one language's text (EntryDraft::LOCALE_FIELDS) and the shared fields
     * (EntryDraft::SHARED_FIELDS) into the change; a field left out keeps the change's value, or
     * the live one. Unknown keys are ignored.
     *
     * @param  array<string, mixed>  $text
     * @param  array<string, mixed>  $shared
     *
     * @throws AuthorizationException the actor may not edit this entry
     * @throws EntryDraftRejectedException locale_disabled · not_live · change_locked · content_invalid · category_governed
     */
    public function handle(Entry $entry, User $actor, string $locale, array $text, array $shared = []): EntryDraft
    {
        if (! Gate::forUser($actor)->allows('update', $entry)) {
            throw new AuthorizationException(__('entries.err_update_forbidden'));
        }

        if (! $this->locales->isEnabled($locale)) {
            throw new EntryDraftRejectedException('locale_disabled');
        }

        /** @var array{0: EntryDraft, 1: bool} $saved */
        $saved = DB::transaction(function () use ($entry, $actor, $locale, $text, $shared): array {
            // Lock first, then read: a change read before the lock could be sent for review, or
            // applied, by the caller ahead in the queue and still be written over here.
            Entry::withTrashed()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $entry->refresh();

            if ($entry->status !== 'published' || $entry->trashed()) {
                throw new EntryDraftRejectedException('not_live');
            }

            $workflow = $this->workflows->forEntry($entry);
            $change = EntryDraft::query()->where('entry_id', $entry->id)->first();

            if ($change !== null && ! $this->workflows->changeIsOpen($workflow, $change)) {
                throw new EntryDraftRejectedException('change_locked');
            }

            $content = $change !== null ? $change->content : ['v' => RecordEntryRevision::FORMAT, 'locales' => []];
            $sharedNow = array_replace(
                $content['shared'] ?? $this->liveShared($entry),
                array_intersect_key($shared, array_flip(EntryDraft::SHARED_FIELDS)),
            );
            $textNow = array_replace(
                $content['locales'][$locale] ?? $this->liveText($entry, $locale),
                array_intersect_key($text, array_flip(EntryDraft::LOCALE_FIELDS)),
            );

            $sharedNow['primary_category_id'] = $this->category($entry, $sharedNow['primary_category_id'] ?? null);
            $readable = $this->writer->read(['v' => RecordEntryRevision::FORMAT] + $sharedNow + $textNow)
                ?? throw new EntryDraftRejectedException('content_invalid');
            // Stored as the editor stores a body: each block's type and props, nothing else.
            $textNow['body'] = ['blocks' => $readable['blocks']];

            $content['v'] = RecordEntryRevision::FORMAT;
            $content['shared'] = $sharedNow;
            $content['locales'][$locale] = $textNow;

            $created = $change === null;
            $change ??= new EntryDraft(['entry_id' => $entry->id, 'created_by' => $actor->id]);
            $change->fill(['content' => $content, 'updated_by' => $actor->id]);

            if ($workflow === null) {
                // No workflow (never, or switched off mid-review): an edited change has not been
                // sent anywhere, so a workflow switched on later takes it from its first stage.
                $change->workflow_state = null;
                $change->assigned_to = null;
            }

            $change->save();

            return [$change, $created && $workflow === null];
        });

        [$change, $tellPublishers] = $saved;

        // Without a workflow there is no "send for review" step: the publishers learn of a change
        // when it first appears, and the one who applies it tells its author.
        if ($tellPublishers) {
            $this->notifier->awaitingReview($entry, $locale, $actor, 'entries.publish', $change);
        }

        return $change;
    }

    /**
     * The change's primary category: none for a page; for a post, one of the site's post
     * categories that keeps it under its approval workflow, or none while the live post has none.
     *
     * @throws EntryDraftRejectedException content_invalid · category_governed
     */
    private function category(Entry $entry, mixed $categoryId): ?int
    {
        if ($entry->type !== 'post') {
            return null;
        }

        if (! is_int($categoryId) || $categoryId <= 0) {
            // A post the website shows under no category may stay so; it may not lose the one it has.
            if ($categoryId === null && $entry->primaryCategory() === null) {
                return null;
            }

            throw new EntryDraftRejectedException('content_invalid');
        }

        if ($entry->primaryCategory()?->id !== $categoryId) {
            $this->applier->assertCategory($entry, $categoryId);
        }

        return $categoryId;
    }

    /**
     * What visitors see of the shared fields today.
     *
     * @return array<string, mixed>
     */
    private function liveShared(Entry $entry): array
    {
        $meta = $entry->getAttribute('meta');
        $cover = $entry->getAttribute('cover_media_id');
        $template = $entry->getAttribute('template_override');

        return [
            'template_override' => is_string($template) ? $template : null,
            'cover_media_id' => is_numeric($cover) ? (int) $cover : null,
            'meta' => is_array($meta) ? $meta : [],
            'primary_category_id' => $entry->type === 'post' ? $entry->primaryCategory()?->id : null,
        ];
    }

    /**
     * What visitors see of one language today; a language the entry does not have yet starts empty.
     *
     * @return array<string, mixed>
     */
    private function liveText(Entry $entry, string $locale): array
    {
        $translation = $entry->translationFor($locale);

        if ($translation === null) {
            return [
                'title' => '', 'slug' => '', 'body' => ['blocks' => []], 'excerpt' => null,
                'seo_title' => null, 'seo_description' => null, 'og_image_id' => null,
            ];
        }

        return array_intersect_key(RecordEntryRevision::snapshot($entry, $translation), array_flip(EntryDraft::LOCALE_FIELDS));
    }
}
