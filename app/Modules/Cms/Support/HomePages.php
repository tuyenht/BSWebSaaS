<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Shared\Support\Cast;

/**
 * The website's Home page: a page whose address is the root «/», that is a page with the home slug
 * (or an empty one) in some language. It never goes to the Trash: the website would have no Home
 * page until someone restored it, so a member picks another page as the Home page first.
 */
final class HomePages
{
    /** Is this entry the website's Home page? (one query; a post never is) */
    public static function is(Entry $entry): bool
    {
        return $entry->type === 'page' && EntryTranslation::query()
            ->where('entry_id', $entry->id)
            ->whereIn('slug', [PathBuilder::HOME_SLUG, ''])
            ->exists();
    }

    /**
     * Which of these rows is the Home page, in one query.
     *
     * @param  list<string>  $ulids
     * @return array<int, true> entry id => true
     */
    public static function among(array $ulids): array
    {
        if ($ulids === []) {
            return [];
        }
        $ids = EntryTranslation::query()
            ->whereIn('slug', [PathBuilder::HOME_SLUG, ''])
            ->whereIn('entry_id', Entry::withTrashed()->select('id')->where('type', 'page')->whereIn('ulid', $ulids))
            ->distinct()
            ->pluck('entry_id');

        $homes = [];
        foreach ($ids as $id) {
            $homes[Cast::int($id)] = true;
        }

        return $homes;
    }
}
