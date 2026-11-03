<?php

use App\Modules\Shared\Support\Cast;
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

// Customers' own domains expire and get pointed elsewhere: mark the failing ones, stop serving a
// domain that has been failing for 30 days, drop the ones never pointed here (design.md §28).
Schedule::command('domains:recheck')->daily()->withoutOverlapping();

// The names still waiting to be pointed or proved are checked again every 15 minutes, as the
// Domains screen tells their owner. In the background so a slow resolver never holds back the
// tasks after it; the lock lapses after 20 minutes should the run die (each run stops taking new
// names after tenancy.custom_domains.recheck_budget_seconds).
Schedule::command('domains:recheck-waiting')->everyFifteenMinutes()->withoutOverlapping(20)->runInBackground();

// Error notices: the morning summary of the counting day that just closed, at a local time set
// explicitly (the app clock stays UTC). Mails only when there is something to say.
Schedule::command('monitoring:summary')
    ->dailyAt(Cast::string(config('monitoring.summary_at'), '08:00'))
    ->timezone(Cast::string(config('monitoring.timezone'), 'Asia/Ho_Chi_Minh'))
    ->withoutOverlapping();

// The scheduler's heartbeat: a data-free ping an outside watcher expects every 5 minutes. Only
// when an address is configured.
Schedule::command('monitoring:heartbeat')
    ->everyFiveMinutes()
    ->when(fn (): bool => filled(config('monitoring.heartbeat_url')));
