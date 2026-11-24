<?php

use App\Modules\Rbac\Actions\PurgeTrashedRoles;
use App\Modules\Rbac\Models\Role;
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

// Empty each site's menu trash of items removed more than 30 days ago (MenuRules::TRASH_DAYS),
// each with its whole branch. Runs on the `scheduler` container (docker compose schedule:work).
Schedule::command('menus:purge-trash')->daily()->withoutOverlapping();

// Empty each site's product Trash of products removed more than 30 days ago (ProductTrash::DAYS).
Schedule::command('products:purge-trash')->daily()->withoutOverlapping();

// Empty each site's post and page Trash of entries removed more than 30 days ago (config
// cms.trash_retention_days, EntryTrash).
Schedule::command('entries:purge-trash')->daily()->withoutOverlapping();

// Empty each site's redirect Trash of redirects removed more than 30 days ago (Redirect::TRASH_DAYS).
Schedule::command('redirects:purge-trash')->daily()->withoutOverlapping();

// Empty each site's «Khách liên hệ» Trash of leads moved there more than 30 days ago (LeadTrash::DAYS),
// with their search words and the bell's notices of them.
Schedule::command('leads:purge-trash')->daily()->withoutOverlapping();

// Delete each site's leads older than its «Thời hạn lưu giữ liên hệ» (LeadRetention: 12/24/36 months,
// 24 when never set, none for «Không tự xóa»; a shortened period waits 7 days) and its spam sent and
// last changed over 30 days ago, with their search words and the bell's notices of them.
Schedule::command('leads:purge-old')->daily()->withoutOverlapping();

// Delete the files of leads that are gone (every way a lead is deleted leaves its file rows with no
// lead): the object first, then the row (SweepLeadFiles). Hourly, so an erased visitor's CV does not
// outlive the erase by more than an hour.
Schedule::command('forms:sweep-files')->hourly()->withoutOverlapping();

// Delete the lead-file objects no row names (a request killed after writing a file and before
// committing leaves one, which neither the sweeper nor an erase can reach), once over an hour old
// (ReconcileLeadFiles). Daily, off the hour so it never runs beside the hourly sweeper.
Schedule::command('forms:reconcile-files')->dailyAt('03:30')->withoutOverlapping();

// Empty each site's role trash of custom roles removed more than 30 days ago (Role::TRASH_DAYS).
// --days=0 empties it now (before rolling back the migration that added the trash).
Artisan::command('roles:purge-trash {--days='.Role::TRASH_DAYS.' : how many days a role stays in the trash}', function (PurgeTrashedRoles $purge): int {
    $raw = $this->option('days');
    if (! (is_int($raw) && $raw >= 0) && ! (is_string($raw) && preg_match('/^\d{1,5}$/', $raw) === 1)) {
        $this->error('--days must be a whole number of days, 0 or more.');

        return 1;
    }
    $days = (int) $raw;
    ['purged' => $purged, 'held' => $held] = $purge->handle($days);
    $this->info("Purged {$purged} role(s) left in the trash over {$days} day(s).");
    if ($held > 0) {
        $this->warn("Kept {$held} role(s) in the trash: someone still holds them (role ids in the log).");
    }

    return 0;
})->purpose('Delete for good the custom roles left in the trash longer than the retention window');
Schedule::command('roles:purge-trash')->daily()->withoutOverlapping();

// Re-count every category's posts and products and correct a drifted total (logged per drift).
// 20:15 UTC is 03:15 in Vietnam: the sites are quiet, and the purges (00:00 UTC) and the audit
// month check (03:00 UTC) run hours apart from it.
Schedule::command('cms:reconcile-category-counts')->dailyAt('20:15')->timezone('UTC')->withoutOverlapping();

// Re-count the items visitors see on each category page, per language (the search suggestion's
// «· N mục»), and correct a drifted total (logged per drift). 20:30 UTC is 03:30 in Vietnam: after
// the admin totals' re-count, before the product search words (20:45).
Schedule::command('cms:reconcile-category-public-counts')->dailyAt('20:30')->timezone('UTC')->withoutOverlapping();

// Check the search words of the 500 products each site edited last, and of the next 10,000 by id after
// where the last night stopped (so every product is checked in turn), against their names and SKU, and
// write again the ones that differ (one warning per site that had any: a write path missed them).
// 20:45 UTC is 03:45 in Vietnam, after the category re-count.
Schedule::command('catalog:search-words --recent=500 --rolling=10000')->dailyAt('20:45')->timezone('UTC')->withoutOverlapping();

// Check the storefront search words of the next 10,000 posts/pages, products and categories of each
// site after where the previous night stopped, and write again the ones that differ (one warning per
// site and kind that had any). 21:00 UTC is 04:00 in Vietnam, after the admin product words.
Schedule::command('search:words --rolling=10000')->dailyAt('21:00')->timezone('UTC')->withoutOverlapping();

// Check which pages show which form (form_placements, kept by every page save) against the page
// bodies of every site, and write again the ones that differ (a form made after a page named its
// key, a missed save). 21:15 UTC is 04:15 in Vietnam, after the storefront search words.
Schedule::command('forms:placements')->dailyAt('21:15')->timezone('UTC')->withoutOverlapping();

// Queue again the delete of a form left in `deleting` with no run queued for 30 minutes (a lost job,
// or one stuck behind a long queue, which the new run takes over):
// it is hidden on every screen, so nobody could ask again, and its leads are personal data.
Schedule::command('forms:resume-deletes')->hourly()->withoutOverlapping();

// Publish scheduled entries the moment their slot arrives (status=scheduled, published_at<=now).
// Runs on the `scheduler` container (docker compose schedule:work).
Schedule::command('entries:publish-due')->everyMinute()->withoutOverlapping();

// Drain each site's Valkey-buffered storefront views into entries.view_count.
Schedule::command('entries:flush-views')->everyFiveMinutes()->withoutOverlapping();

// Turn each tapped site's Valkey-buffered contact button taps into its daily tallies (contact_click_daily).
Schedule::command('contact:flush-clicks')->everyFiveMinutes()->withoutOverlapping();

// Retention: contact button tallies are kept 90 days (contact_click_daily).
Schedule::command('contact:prune-clicks')->daily()->withoutOverlapping();

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

// The audit log is one table per month (UTC): make sure the running month and the next three
// exist. The command fails when fewer than two lie ahead, which the morning summary reports.
Schedule::command('audit:months')->dailyAt('03:00')->timezone('UTC')->withoutOverlapping();

// Retention of the audit log: on the 1st of each month, remove the months older than the running
// month and the 24 full months before it, with an audit row saying so.
Schedule::command('audit:prune')->monthlyOn(1, '03:30')->timezone('UTC')->withoutOverlapping();

// Error notices: the morning summary of the counting day that just closed, at a local time set
// explicitly (the app clock stays UTC). Mails only when there is something to say.
Schedule::command('monitoring:summary')
    ->dailyAt(Cast::string(config('monitoring.summary_at'), '08:00'))
    ->timezone(Cast::string(config('monitoring.timezone'), 'Asia/Ho_Chi_Minh'))
    ->withoutOverlapping();

// The Cloudflare Workers free plan allows 100,000 requests per UTC day for the whole account: mail
// the owner at 50 / 80 / 100 percent. Quiet when the mailer or the Cloudflare token is not set.
Schedule::command('monitoring:worker-quota')->hourly()->withoutOverlapping(10);

// The scheduler's heartbeat: a data-free ping an outside watcher expects every 5 minutes. Only
// when an address is configured.
Schedule::command('monitoring:heartbeat')
    ->everyFiveMinutes()
    ->when(fn (): bool => filled(config('monitoring.heartbeat_url')));
