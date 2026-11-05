<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Shared\Contracts\PageLinks;
use Illuminate\Database\Eloquent\Builder;

/**
 * PageLinks from the stored routes: the same reachability test the main menu applies to an entry
 * (published, its translation in this locale published; a trashed entry is out through its soft
 * delete scope) and the locale prefix from PathBuilder, the one chokepoint for it. The routes and
 * entries queries are site-scoped (BelongsToSite + RLS), so another site's ulid resolves to nothing.
 */
final readonly class EntryPageLinks implements PageLinks
{
    public function __construct(private PathBuilder $paths) {}

    public function paths(array $ulids, string $locale): array
    {
        if ($ulids === []) {
            return [];
        }

        $routes = SiteRoute::query()
            ->join('entries', 'entries.id', '=', 'routes.entry_id')
            ->where('routes.locale', $locale)
            ->whereIn('entries.ulid', $ulids)
            ->whereHas('entry', fn (Builder $entry) => $entry
                ->where('status', 'published')
                ->whereHas('translations', fn (Builder $translation) => $translation
                    ->where('locale', $locale)
                    ->where('status', 'published')))
            ->get(['routes.path', 'entries.ulid']);

        $found = [];
        foreach ($routes as $route) {
            $ulid = $route->getAttribute('ulid');
            if (is_string($ulid)) {
                $found[$ulid] = $this->paths->publicUrl($route->path, $locale);
            }
        }

        return $found;
    }
}
