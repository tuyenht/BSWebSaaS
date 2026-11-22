<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The Google title and description of one language of a post that is not live yet, written from
 * «Hộp duyệt» while it is being reviewed: the same columns the editor writes, under the same rule
 * (EntryPolicy::update: own or any, inside the member's category limits) and the same row lock every
 * entry write takes first.
 *
 * Only a post that is not on the website: edits to live content go into its pending change (SaveEntryDraft)
 * and are reviewed before visitors see them, which the editor handles. A post approved for later goes
 * live as it was approved, so nothing is written to it either («Hủy hẹn giờ» first). And never over a
 * value someone else saved after the reader read it: the caller passes the fingerprint of what it
 * showed (print()), and a different one is refused, so the editor's own «someone else saved» question
 * and this one never write over each other unseen.
 *
 * The values arrive validated and as one line each (the admin form's job, as in the editor); an empty
 * one is stored as null, as the editor stores it. Runs inside the caller's tenant context.
 */
final readonly class SaveEntrySeo
{
    /** Refusals: the post is live, approved for later, has no text in this language, or changed meanwhile. */
    public const string LIVE = 'live';

    public const string APPROVED_LATER = 'approved_later';

    public const string NO_TRANSLATION = 'no_translation';

    public const string CHANGED = 'changed';

    /** What the reader showed of a translation's Google fields, to tell whether they changed since. */
    public static function print(?EntryTranslation $translation): string
    {
        return md5(($translation->seo_title ?? '')."\0".($translation->seo_description ?? ''));
    }

    /**
     * @return string|null why nothing was written (one of the constants above), or null when it was
     *
     * @throws AuthorizationException the actor may not edit this post
     */
    public function handle(Entry $entry, User $actor, string $locale, string $title, string $description, string $seen): ?string
    {
        return DB::transaction(function () use ($entry, $actor, $locale, $title, $description, $seen): ?string {
            $locked = Entry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if (! Gate::forUser($actor)->allows('update', $locked)) {
                throw new AuthorizationException(__('entries.err_update_forbidden'));
            }
            if ($locked->approvedForLater()) {
                return self::APPROVED_LATER;
            }
            if ($locked->status === 'published') {
                return self::LIVE;
            }

            $translation = EntryTranslation::query()->where('entry_id', $locked->id)->where('locale', $locale)->first();
            if (! $translation instanceof EntryTranslation) {
                return self::NO_TRANSLATION;
            }
            if (self::print($translation) !== $seen) {
                return self::CHANGED;
            }

            $translation->fill([
                'seo_title' => $title !== '' ? $title : null,
                'seo_description' => $description !== '' ? $description : null,
            ])->save();

            return null;
        });
    }
}
