<?php

namespace App\Modules\Cms\Components;

use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Contracts\Component;

/**
 * A DATA-BOUND page component: pulls the latest published posts (from a different
 * object than the page itself) at render time. Demonstrates a homepage composed of
 * components sourced from many objects. Auto-scoped to the current site (RLS).
 */
final class LatestPostsComponent implements Component
{
    public function view(): string
    {
        return 'theme::sections.latest_posts';
    }

    /**
     * @param  array<string, mixed>  $props  supports: heading, limit, locale
     * @return array<string, mixed>
     */
    public function data(array $props): array
    {
        $limit = is_int($props['limit'] ?? null) ? $props['limit'] : 3;
        $locale = is_string($props['locale'] ?? null) ? $props['locale'] : 'vi';

        $posts = Entry::query()
            ->where('type', 'post')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(function (Entry $entry) use ($locale): array {
                $translation = $entry->translations()->where('locale', $locale)->first();

                if ($translation === null) {
                    return ['title' => '', 'slug' => ''];
                }

                return ['title' => $translation->title, 'slug' => $translation->slug];
            })
            ->all();

        return ['props' => $props, 'posts' => $posts];
    }
}
