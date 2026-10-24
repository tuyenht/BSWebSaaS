<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryCategoryWriter;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\Slugger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Duplicates an entry as a fresh DRAFT (design §3a "Nhân bản"). The copy is a single node —
 * parent_id is cleared so it never lands under the original's parent — with every translation
 * copied (title suffixed " (bản sao)", slug suffixed to stay unique per locale) and the same
 * categories. No route is created: a draft has no public URL until PublishEntry runs. Requires
 * the `create` ability, the original's category inside the actor's category limits (a limited
 * member copies no page), and a source that is not in the Trash (trashed content stays there until
 * it is restored — a copy would bring it back as a live draft); the whole copy runs in one
 * transaction so a half-copied entry can never surface. Block image usage (mediables) is the caller's concern — reconcile the returned copy
 * exactly as the editor does on save (EntryBlockMediaReconciler). Returns the new draft for a
 * redirect-to-edit.
 */
final class DuplicateEntry
{
    public function handle(Entry $entry, User $actor): Entry
    {
        if (! Gate::forUser($actor)->allows('create', Entry::class)) {
            throw new AuthorizationException(__('entries.err_create_forbidden'));
        }

        if ($entry->trashed()) {
            throw new AuthorizationException(__('entries.err_duplicate_trashed'));
        }

        // The copy is filed where the original is: a member limited to some categories copies only
        // what they could have written there themselves.
        $response = Gate::forUser($actor)->inspect('fileUnder', [Entry::class, $entry->type, $entry->type === 'post' ? $entry->primaryCategory()?->id : null]);
        if ($response->denied()) {
            throw new AuthorizationException($response->message());
        }

        /** @var Entry $copy */
        $copy = DB::transaction(function () use ($entry, $actor): Entry {
            $copy = Entry::query()->create([
                'type' => $entry->type,
                'status' => 'draft',
                'author_id' => $actor->id,
                'parent_id' => null,
                'template_override' => $entry->template_override,
                'cover_media_id' => $entry->cover_media_id,
                'meta' => $entry->meta,
            ]);

            foreach ($entry->translations()->get() as $translation) {
                $copy->translations()->create([
                    'locale' => $translation->locale,
                    // The copy is marked in the copied translation's own language.
                    'title' => __('entries.copy_title', ['title' => $translation->title], $translation->locale),
                    'slug' => $this->uniqueSlug($translation->slug, $translation->locale),
                    'status' => 'draft',
                    'body' => $translation->body,
                    'excerpt' => $translation->excerpt,
                    'seo_title' => $translation->seo_title,
                    'seo_description' => $translation->seo_description,
                    'og_image_id' => $translation->og_image_id,
                ]);
            }

            // Same categories (order = pivot position; index 0 drives a post's URL prefix) and the
            // same facet terms at their own positions. A limited member's copy keeps only the
            // categories inside their limits: adding one outside them is not theirs to do, even by
            // copying (the primary one was checked above).
            $categoryIds = [];
            $scopes = app(CategoryScopes::class);
            foreach ($entry->taxonomies()->get() as $taxonomy) {
                $pivot = $taxonomy->getRelation('pivot');
                $position = $pivot instanceof Pivot ? Cast::int($pivot->getAttribute('position')) : 0;
                if ($position >= Entry::FACET_POSITION_BASE) {
                    $copy->taxonomies()->attach($taxonomy->id, ['position' => $position, 'site_id' => $copy->site_id]);
                } elseif ($categoryIds === [] || $scopes->coversId($actor, $taxonomy->id)) {
                    $categoryIds[] = $taxonomy->id;
                }
            }
            if ($categoryIds !== []) {
                app(EntryCategoryWriter::class)->assign($copy, $categoryIds, $actor);
            }

            return $copy;
        });

        return $copy;
    }

    /** Appends a suffix until the slug is free for this locale (site-scoped by RLS). */
    private function uniqueSlug(string $slug, string $locale): string
    {
        // The marker reads in the translation's language (any script, through Slugger); "copy" when
        // the word leaves nothing usable.
        $marker = Slugger::make(__('entries.copy_slug', [], $locale), $locale) ?: 'copy';
        $base = $slug === '' ? $marker : $slug.'-'.$marker;

        return Slugger::free(
            $base,
            fn (string $candidate): bool => EntryTranslation::query()->where('locale', $locale)->where('slug', $candidate)->exists(),
        );
    }
}
