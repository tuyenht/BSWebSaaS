<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Taxonomies\Concerns;

use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Shared\Support\Cast;

/**
 * Shared steps of the actions that change a site's categories. Callers run inside a transaction and
 * the tenant context.
 */
trait EditsTaxonomyTree
{
    /**
     * Serialise category edits per site: two editors moving or deleting categories at once must not
     * interleave their depth, loop and sibling-order checks.
     */
    private function lockTree(int $siteId): void
    {
        TaxonomyTree::lock($siteId);
    }

    /**
     * The category as it is now, read inside the lock — and only if it is on this site: RLS and the
     * site scope hide another site's rows, so a category from elsewhere is refused with $reason.
     *
     * @throws TaxonomyChangeRejectedException
     */
    private function reload(Taxonomy $taxonomy, string $reason): Taxonomy
    {
        return Taxonomy::query()->whereKey($taxonomy->id)->first() ?? throw new TaxonomyChangeRejectedException($reason);
    }

    /**
     * The children of $parentId in one vocabulary (the top-level categories when null), in order.
     *
     * @return list<int>
     */
    private function siblings(string $vocabulary, ?int $parentId, ?int $except = null): array
    {
        $ids = Taxonomy::query()
            ->where('vocabulary', $vocabulary)
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->all();

        return array_values(array_map(static fn (mixed $id): int => Cast::int($id), $ids));
    }

    /**
     * Put these categories under $parentId, numbered 0..n in this order, so positions never keep
     * gaps or duplicates. Only rows that actually change are written.
     *
     * @param  list<int>  $order
     */
    private function renumber(?int $parentId, array $order): void
    {
        $current = Taxonomy::query()->whereKey($order)->get(['id', 'parent_id', 'position'])->keyBy('id');

        foreach ($order as $position => $id) {
            $row = $current->get($id);
            if ($row instanceof Taxonomy && ($row->parent_id !== $parentId || $row->position !== $position)) {
                Taxonomy::query()->whereKey($id)->update(['parent_id' => $parentId, 'position' => $position]);
            }
        }
    }

    /**
     * The per-language name and slug to save: each language one the site writes in, each name
     * valid, each slug valid — a slug not given keeps the current one, or is made from the name for
     * a language the category is not written in yet. A current slug is kept as it is even when older
     * than today's slug rules, so renaming a category never forces its address to change.
     *
     * @param  array<string, TaxonomyTranslation>  $existing  locale → current translation
     * @param  list<string>  $enabled  the languages the site writes in
     * @return array<string, array{name: string, slug: string}>
     *
     * @throws TaxonomyChangeRejectedException
     */
    private function translations(TaxonomyData $data, array $existing, array $enabled): array
    {
        $clean = [];

        foreach (array_unique([...array_keys($data->names), ...array_keys($data->slugs)]) as $locale) {
            if (! in_array($locale, $enabled, true)) {
                throw new TaxonomyChangeRejectedException('locale_invalid');
            }
            $current = $existing[$locale] ?? null;

            $name = TaxonomyRules::name($data->names[$locale] ?? $current->name ?? '')
                ?? throw new TaxonomyChangeRejectedException('name_invalid');

            $given = trim($data->slugs[$locale] ?? '');
            if ($current !== null && ($given === '' || $given === $current->slug)) {
                $slug = $current->slug;
            } else {
                $slug = TaxonomyRules::slug($given !== '' ? $given : TaxonomyRules::slugFrom($name))
                    ?? throw new TaxonomyChangeRejectedException('slug_invalid');
            }

            $clean[$locale] = ['name' => $name, 'slug' => $slug];
        }

        return $clean;
    }

    /**
     * A theme layout name, null for the theme's default.
     *
     * @throws TaxonomyChangeRejectedException
     */
    private function template(?string $template): ?string
    {
        $template = trim((string) $template);
        if ($template === '') {
            return null;
        }

        return TaxonomyRules::template($template) ? $template : throw new TaxonomyChangeRejectedException('template_invalid');
    }

    /**
     * Save the names and slugs and give each language its category page at `/{slug}` — moved with a
     * 301 when the slug changed. Another category using the slug in that language, or anything else
     * already at the path, is refused as slug_taken. Returns the languages whose slug changed.
     *
     * @param  array<string, array{name: string, slug: string}>  $translations
     * @return array<string, string> locale → new slug
     *
     * @throws TaxonomyChangeRejectedException
     */
    private function saveTranslations(Taxonomy $taxonomy, array $translations, RouteSync $routes): array
    {
        $existing = $taxonomy->translations()->get(['id', 'taxonomy_id', 'locale', 'slug'])->keyBy('locale');
        $changed = [];

        foreach ($translations as $locale => $translation) {
            $old = $existing->get($locale);
            $slugChanged = ! $old instanceof TaxonomyTranslation || $old->slug !== $translation['slug'];

            // Two categories sharing a slug in one language would give their items the same prefix.
            $taken = $slugChanged && TaxonomyTranslation::query()
                ->where('locale', $locale)
                ->where('slug', $translation['slug'])
                ->where('taxonomy_id', '!=', $taxonomy->id)
                ->exists();
            if ($taken) {
                throw new TaxonomyChangeRejectedException('slug_taken');
            }

            $taxonomy->translations()->updateOrCreate(['locale' => $locale], $translation);

            try {
                $routes->syncTaxonomy($taxonomy, $locale, $translation['slug']);
            } catch (RouteConflictException) {
                throw new TaxonomyChangeRejectedException('slug_taken');
            }

            if ($slugChanged) {
                $changed[$locale] = $translation['slug'];
            }
        }

        return $changed;
    }
}
