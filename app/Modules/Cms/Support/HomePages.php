<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The website's Home page: a page whose address is the root «/», that is a page with the home slug
 * (or an empty one) in some language. It never goes to the Trash: the website would have no Home
 * page until someone restored it, so a member picks another page as the Home page first.
 */
final class HomePages
{
    /** The relation remember() sets on each page: its translations with a Home slug (none: not the Home page). */
    private const string KNOWN = 'homePageSlugs';

    /**
     * Is this entry the website's Home page? A post never is. Answered from memory when remember() read
     * it for a batch of pages moments before, else one query.
     */
    public static function is(Entry $entry): bool
    {
        if ($entry->type !== 'page') {
            return false;
        }
        if ($entry->relationLoaded(self::KNOWN)) {
            $homeSlugs = $entry->getRelation(self::KNOWN);

            return $homeSlugs instanceof EloquentCollection && $homeSlugs->isNotEmpty();
        }

        return EntryTranslation::query()
            ->where('entry_id', $entry->id)
            ->whereIn('slug', [PathBuilder::HOME_SLUG, ''])
            ->exists();
    }

    /**
     * Read, in one query, which of these pages is the Home page, and keep the answer on each row so
     * is() gives it without a query: a branch of pages moved to the Trash together costs one read, not
     * one per page. Call it inside the transaction that moves them, right before the move.
     *
     * @param  list<Entry>  $entries
     */
    public static function remember(array $entries): void
    {
        $pages = array_values(array_filter($entries, static fn (Entry $entry): bool => $entry->type === 'page'));
        if ($pages === []) {
            return;
        }
        $homes = EntryTranslation::query()
            ->whereIn('entry_id', array_map(static fn (Entry $page): int => $page->id, $pages))
            ->whereIn('slug', [PathBuilder::HOME_SLUG, ''])
            ->get(['id', 'entry_id', 'slug'])
            ->groupBy('entry_id');
        foreach ($pages as $page) {
            $page->setRelation(self::KNOWN, $homes->get($page->id) ?? new EloquentCollection);
        }
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
