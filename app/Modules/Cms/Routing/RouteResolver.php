<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use Closure;

/**
 * Resolves a request (locale, path) to a publishable target within the CURRENT site
 * (all queries auto-scoped by BelongsToSite + RLS): a published entry (page/post) OR a
 * category (taxonomy) list page. Returns null when there is no route or the target is
 * not publishable — the caller then looks for a 301 (slug history) and otherwise 404s.
 * Entry visibility: entry.status=published AND translation.status=published, and its end time
 * («Gỡ khỏi website lúc», expires_at) not yet come. entries:unpublish-due archives an item within a
 * minute of that time; until it does, the item already answers as not found here, checked on the row
 * in hand (no query). The website's Home page is the one exception: «/» never goes dark by itself.
 * Staff preview (StorefrontController::preview) does not come through here and still shows it.
 */
final class RouteResolver
{
    public function resolve(string $locale, string $path): ResolvedContent|ResolvedCategory|ResolvedProduct|null
    {
        $route = SiteRoute::query()
            ->where('locale', $locale)
            ->where('path', $path)
            ->first();

        if ($route === null) {
            return null;
        }

        if ($route->entry_id !== null) {
            return $this->resolveEntry($route->entry_id, $locale);
        }

        if ($route->taxonomy_id !== null) {
            return $this->resolveCategory($route->taxonomy_id, $locale);
        }

        // Product routes carry only a thin reference — Cms never loads Catalog\Product (keeps the
        // module graph acyclic). The storefront loads the product and enforces published visibility.
        if ($route->product_id !== null) {
            return new ResolvedProduct($route->product_id, $locale);
        }

        return null;
    }

    /**
     * A 301/302 mapping for a path that no longer resolves (the slug moved). Checked
     * only after resolve() misses, so live content always wins over its own history.
     */
    public function redirect(string $locale, string $path): ?Redirect
    {
        return Redirect::query()
            ->where('locale', $locale)
            ->where('from_path', $path)
            ->first();
    }

    /**
     * The first of $locales (in the order given) whose language holds $path: as a live address
     * (one resolve() can open) or, failing that, as a stored redirect. For a clean path the
     * default language missed, so a site whose default changed still sends the former default's
     * addresses on. One query per kind of row, and only on a miss. A product address counts only
     * when $productVisible says the product is published in that language (Cms never loads Catalog),
     * so a draft's address is never revealed by a redirect.
     *
     * @param  list<string>  $locales
     * @param  (Closure(ResolvedProduct, string): bool)|null  $productVisible
     */
    public function heldElsewhere(array $locales, string $path, bool $routes = true, ?Closure $productVisible = null): ?string
    {
        if ($locales === []) {
            return null;
        }

        if ($routes) {
            $held = SiteRoute::query()->whereIn('locale', $locales)->where('path', $path)->pluck('locale')->all();
            foreach ($locales as $locale) {
                if (! in_array($locale, $held, true)) {
                    continue;
                }
                $target = $this->resolve($locale, $path);
                if ($target instanceof ResolvedProduct ? ($productVisible !== null && $productVisible($target, $locale)) : $target !== null) {
                    return $locale;
                }
            }
        }

        // only a row the storefront would follow there counts: one it ignores must not send the
        // visitor to a language where the address then fails
        $rules = app(RedirectRules::class);
        $held = Redirect::query()->whereIn('locale', $locales)->where('from_path', $path)->get(['locale', 'from_path', 'to_path'])
            ->filter(fn (Redirect $row): bool => $rules->follows($row))->pluck('locale')->all();
        foreach ($locales as $locale) {
            if (in_array($locale, $held, true)) {
                return $locale;
            }
        }

        return null;
    }

    private function resolveEntry(int $entryId, string $locale): ?ResolvedContent
    {
        // Eager-load every PUBLISHED translation (not just the current locale) so the storefront
        // can build hreflang alternates without an extra query — net-zero vs the single-locale
        // load it replaces.
        $entry = Entry::query()
            ->whereKey($entryId)
            ->where('status', 'published')
            ->with('translations')
            ->first();

        if ($entry === null || $this->expired($entry)) {
            return null;
        }

        $translation = $entry->translations
            ->first(fn (EntryTranslation $candidate): bool => $candidate->locale === $locale && $candidate->status === 'published');

        if ($translation === null) {
            return null;
        }

        return new ResolvedContent($entry, $translation);
    }

    /** Its end time has come, and it is not the Home page (read from the translations in hand). */
    private function expired(Entry $entry): bool
    {
        if ($entry->expires_at === null || $entry->expires_at > now()) {
            return false;
        }

        return $entry->type !== 'page'
            || ! $entry->translations->contains(fn (EntryTranslation $translation): bool => in_array($translation->slug, [PathBuilder::HOME_SLUG, ''], true));
    }

    private function resolveCategory(int $taxonomyId, string $locale): ?ResolvedCategory
    {
        // Eager-load all translations (for hreflang alternates) — net-zero vs the lazy
        // translationFor() it replaces.
        $taxonomy = Taxonomy::query()->whereKey($taxonomyId)->with('translations')->first();

        if ($taxonomy === null) {
            return null;
        }

        $translation = $taxonomy->translationFor($locale);

        if ($translation === null) {
            return null;
        }

        return new ResolvedCategory($taxonomy, $translation);
    }
}
