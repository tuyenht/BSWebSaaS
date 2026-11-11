<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\TaxonomyTranslation;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
        $categories = TaxonomyTranslation::query()
            ->toBase()
            ->selectRaw('1')
            ->where('locale', $locale)
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($taxonomyId !== null, fn (QueryBuilder $query) => $query->where('taxonomy_id', '<>', $taxonomyId));

        $pages = EntryTranslation::query()
            ->toBase()
            ->selectRaw('1')
            ->where('entry_translations.locale', $locale)
            ->where('entry_translations.status', 'published')
            ->whereRaw('lower(entry_translations.title) = lower(?)', [$name])
            ->whereExists(fn (QueryBuilder $entry) => $entry->selectRaw('1')
                ->from('entries')
                ->whereColumn('entries.id', 'entry_translations.entry_id')
                ->where('entries.type', 'page')
                ->where('entries.status', 'published')
                ->whereNull('entries.deleted_at')
                ->when($entryId !== null, fn (QueryBuilder $query) => $query->where('entries.id', '<>', $entryId)));

        return $categories->unionAll($pages)->exists();
    }
}
