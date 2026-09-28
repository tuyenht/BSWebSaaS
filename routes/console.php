<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Empty each site's media Trash of masters older than the retention window (config
// media.trash_retention_days). Runs on the `scheduler` container (docker compose schedule:work).
Schedule::command('media:purge-trash')->daily()->withoutOverlapping();

// Publish scheduled entries the moment their slot arrives (status=scheduled, published_at<=now).
// Runs on the `scheduler` container (docker compose schedule:work).
Schedule::command('entries:publish-due')->everyMinute()->withoutOverlapping();

// Drain each site's Valkey-buffered storefront views into entries.view_count.
Schedule::command('entries:flush-views')->everyFiveMinutes()->withoutOverlapping();

// Retention: in-app notifications are kept 90 days (site_notifications).
Schedule::command('notifications:prune')->daily()->withoutOverlapping();

// Retention: revision history keeps the newest cms.revisions.keep per entry and language, and
// removes older ones past cms.revisions.days (entry_revisions).
Schedule::command('cms:prune-revisions')->daily()->withoutOverlapping();
