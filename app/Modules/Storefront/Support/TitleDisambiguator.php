<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the Google titles of one site unique: a page or category that sits under a parent and shares
 * its name with another published page or category of the same language is searched under
 * "Name · Parent". Only a child pays for the check, and it is one small read (the two lists of
 * names joined in a single query); a top-level page or category costs nothing.
 */
final class TitleDisambiguator
{
    /** Between the page's name and its parent's in the title. */
    public const string SEPARATOR = ' · ';

    /** The category vocabularies that have public list pages (a tag has none). */
    private const array LISTED_VOCABULARIES = ['post_cat', 'product_cat'];

    /**
     * The title to search under: the name alone, or the name with its parent's when another published
     * page or category of this language has the same name.
     *
     * @param  int|null  $entryId  the page itself (left out of the comparison)
     * @param  int|null  $taxonomyId  the category itself (left out of the comparison)
     */
    public function title(string $name, ?string $parentName, string $locale, ?int $entryId, ?int $taxonomyId): string
    {
        $parentName = $parentName !== null ? trim($parentName) : '';
        if ($parentName === '' || trim($name) === '' || ! $this->isTaken($name, $locale, $entryId, $taxonomyId)) {
            return $name;
        }

        return $name.self::SEPARATOR.$parentName;
    }

    private function isTaken(string $name, string $locale, ?int $entryId, ?int $taxonomyId): bool
    {
        // Resolved per call: the controller lives on across requests, the current site does not.
        $site = app(CurrentSite::class)->id();

        // Starts from the site's own published pages and categories (leading site_id indexes), then reaches
        // the translations by their parent key; posts, tags and other kinds never enter the comparison.
        $categories = DB::table('taxonomies')
            ->join('taxonomy_translations', 'taxonomy_translations.taxonomy_id', '=', 'taxonomies.id')
            ->selectRaw('1')
            ->where('taxonomies.site_id', $site)
            ->whereIn('taxonomies.vocabulary', self::LISTED_VOCABULARIES)
            ->where('taxonomy_translations.site_id', $site)
            ->where('taxonomy_translations.locale', $locale)
            ->whereRaw('lower(taxonomy_translations.name) = lower(?)', [$name])
            ->when($taxonomyId !== null, fn (Builder $query) => $query->where('taxonomies.id', '<>', $taxonomyId));

        $pages = DB::table('entries')
            ->join('entry_translations', 'entry_translations.entry_id', '=', 'entries.id')
            ->selectRaw('1')
            ->where('entries.site_id', $site)
            ->where('entries.type', 'page')
            ->where('entries.status', 'published')
            ->whereNull('entries.deleted_at')
            ->where('entry_translations.site_id', $site)
            ->where('entry_translations.locale', $locale)
            ->where('entry_translations.status', 'published')
            ->whereRaw('lower(entry_translations.title) = lower(?)', [$name])
            ->when($entryId !== null, fn (Builder $query) => $query->where('entries.id', '<>', $entryId));

        return $categories->unionAll($pages)->exists();
    }
}
