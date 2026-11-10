<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Where the consent sentence of a lead form points: the site's published privacy-policy page, in
 * the visitor's language when that translation is published, else in the site's default language.
 * The page is found by its default-language slug (config cms.privacy_page_slugs); a site without
 * one gets null and the sentence shows no link. The public URL goes through PathBuilder (the stored
 * route when there is one, the composed path otherwise), so the language prefix is always right.
 *
 * Three small queries (the page, its two translations, their routes), run only for a form that has
 * a consent box. Stateless, so it is safe to resolve from the container under Octane.
 */
final readonly class PrivacyPolicyLink
{
    public function __construct(
        private SiteLocale $locales,
        private PathBuilder $paths,
    ) {}

    public function url(string $locale): ?string
    {
        $slugs = array_values(array_filter(Cast::arr(config('cms.privacy_page_slugs')), is_string(...)));
        if ($slugs === []) {
            return null;
        }

        $default = $this->locales->default();
        $locales = array_values(array_unique([$locale, $default]));

        $page = Entry::query()
            ->select(['id', 'type', 'parent_id'])
            ->where('type', 'page')
            ->where('status', 'published')
            ->whereHas('translations', fn (Builder $query) => $query
                ->where('locale', $default)
                ->where('status', 'published')
                ->whereIn('slug', $slugs))
            ->with([
                'translations' => fn (Relation $query) => $query
                    ->select(['id', 'entry_id', 'locale', 'slug'])
                    ->whereIn('locale', $locales)
                    ->where('status', 'published'),
                'routes' => fn (Relation $query) => $query
                    ->select(['id', 'entry_id', 'locale', 'path'])
                    ->whereIn('locale', $locales),
            ])
            ->orderBy('id')
            ->first();

        if (! $page instanceof Entry) {
            return null;
        }

        foreach ($locales as $candidate) {
            $translation = $page->translations->firstWhere('locale', $candidate);
            if (! $translation instanceof EntryTranslation) {
                continue;
            }
            $route = $page->routes->firstWhere('locale', $candidate);
            $path = $route instanceof SiteRoute
                ? $route->path
                : $this->paths->forEntry($page, $candidate, trim($translation->slug, '/'));

            return $this->paths->publicUrl($path, $candidate);
        }

        return null;
    }
}
