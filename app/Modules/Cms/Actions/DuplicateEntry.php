<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Duplicates an entry as a fresh DRAFT (design §3a "Nhân bản"). The copy is a single node —
 * parent_id is cleared so it never lands under the original's parent — with every translation
 * copied (title suffixed " (bản sao)", slug suffixed to stay unique per locale) and the same
 * categories. No route is created: a draft has no public URL until PublishEntry runs. Requires
 * the `create` ability, and a source that is not in the Trash (trashed content stays there until
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

            // Same categories (order = pivot position; index 0 drives a post's URL prefix).
            $categoryIds = [];
            foreach ($entry->taxonomies()->get() as $taxonomy) {
                $categoryIds[] = $taxonomy->id;
            }
            if ($categoryIds !== []) {
                $copy->assignCategories($categoryIds);
            }

            return $copy;
        });

        return $copy;
    }

    /** Appends a suffix until the slug is free for this locale (site-scoped by RLS). */
    private function uniqueSlug(string $slug, string $locale): string
    {
        // The marker reads in the translation's language; a script with no Latin form (ja, zh)
        // keeps the plain "copy".
        $marker = Str::slug(__('entries.copy_slug', [], $locale)) ?: 'copy';
        $base = $slug === '' ? $marker : $slug.'-'.$marker;
        $candidate = $base;
        $n = 1;

        while (EntryTranslation::query()->where('locale', $locale)->where('slug', $candidate)->exists()) {
            $n++;
            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }
}
