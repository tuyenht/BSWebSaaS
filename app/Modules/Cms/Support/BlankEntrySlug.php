<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Shared\Support\Slugger;

/**
 * The link a blank slug field gets: made from the title of that language the way a new item gets its
 * link (Slugger), then made free for the language. No other item's link and no other route (a
 * category, a product) may already hold the public path it would get, and a flat page is never given
 * `home`, which publishing turns into the site's root. '' when the title leaves nothing usable.
 */
final readonly class BlankEntrySlug
{
    public function __construct(private PathBuilder $paths) {}

    public function make(Entry $entry, string $locale, string $title): string
    {
        $path = $this->paths->forEntry($entry, $locale, 'x');
        $prefix = str_ends_with($path, 'x') ? substr($path, 0, -1) : '/';

        return Slugger::fromTitle(
            $title,
            $locale,
            fn (string $base): array => [
                ...Slugger::takenAmong(
                    EntryTranslation::query()->where('locale', $locale)->where('entry_id', '!=', $entry->id),
                    'slug',
                    $base,
                ),
                ...Slugger::takenAmong(
                    SiteRoute::query()
                        ->where('locale', $locale)
                        ->where(fn ($own) => $own->whereNull('entry_id')->orWhere('entry_id', '!=', $entry->id)),
                    'path',
                    $base,
                    $prefix,
                ),
                ...($prefix === '/' ? [PathBuilder::HOME_SLUG] : []),
            ],
        );
    }
}
