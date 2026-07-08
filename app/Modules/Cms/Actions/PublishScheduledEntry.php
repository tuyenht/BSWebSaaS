<?php

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;

/**
 * Publishes a scheduled entry when its time arrives — the counterpart to PublishEntry's Tầng-1
 * flow, but driven by the `entries:publish-due` scheduler. There is NO actor gate here: the entry
 * was already scheduled by an authorized user, and the scheduler is the authority. The entry and
 * every one of its translations go `published`, and each locale's route is synced (nested paths +
 * 301 history via RouteSync). published_at is left as the scheduled slot. Runs inside a site's
 * tenant context (the command enters it).
 */
final readonly class PublishScheduledEntry
{
    public function __construct(private RouteSync $routes) {}

    public function handle(Entry $entry): void
    {
        $entry->update(['status' => 'published']);

        foreach ($entry->translations as $translation) {
            $translation->update(['status' => 'published']);
            $this->routes->sync($entry, $translation->locale, $translation->slug);
        }
    }
}
