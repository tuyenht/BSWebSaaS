<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * P8 — the entries:publish-due scheduler. A scheduled entry whose published_at has passed goes
 * live (status=published, translations published, routes synced) the next time the command runs;
 * one still in the future stays scheduled. The command sweeps per-site inside Tenancy::enter (RLS).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
});

it('publishes a scheduled entry whose slot has passed and syncs its route', function (): void {
    $tenancy = app(Tenancy::class);
    $entry = null;

    $tenancy->enter(1, function () use (&$entry): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => 'Khuyến mãi', 'slug' => 'khuyen-mai', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'KM']]]],
        ]);
    });

    $this->artisan('entries:publish-due')->assertSuccessful();

    $tenancy->enter(1, function () use ($entry): void {
        expect($entry->fresh()->status)->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $entry->id)->value('path'))->toBe('/khuyen-mai');
    });

    $this->get('http://a.test/khuyen-mai')->assertOk();
});

it('leaves a future-scheduled entry untouched', function (): void {
    $tenancy = app(Tenancy::class);
    $entry = null;

    $tenancy->enter(1, function () use (&$entry): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->addDay()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Tương lai', 'slug' => 'tuong-lai', 'status' => 'draft', 'body' => ['blocks' => []]]);
    });

    $this->artisan('entries:publish-due')->assertSuccessful();

    $tenancy->enter(1, function () use ($entry): void {
        expect($entry->fresh()->status)->toBe('scheduled');
    });
});

it('does not let one broken entry abort the sweep for the rest of that site or the sites after it', function (): void {
    $tenancy = app(Tenancy::class);
    $ids = [];

    $tenancy->enter(1, function () use (&$ids): void {
        // A live page already owns /lien-he…
        $live = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()->subDay()]);
        $live->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($live, 'vi', 'lien-he');

        // …and a scheduled page wants the very same path: RouteSync will refuse it.
        $clashing = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $clashing->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ 2', 'slug' => 'lien-he', 'status' => 'draft', 'body' => ['blocks' => []]]);

        // A healthy one due in the same site, sorted after it.
        $healthy = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $healthy->translations()->create(['locale' => 'vi', 'title' => 'Tin nội bộ', 'slug' => 'tin-noi-bo', 'status' => 'draft', 'body' => ['blocks' => []]]);

        $ids['clashing'] = $clashing->id;
        $ids['healthy'] = $healthy->id;
    });

    $tenancy->enter(2, function () use (&$ids): void {
        $other = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $other->translations()->create(['locale' => 'vi', 'title' => 'Bảng giá', 'slug' => 'bang-gia', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $ids['other_site'] = $other->id;
    });

    // The sweep reports the failure and carries on — the cron runs every minute, so an aborting
    // sweep would keep every later tenant permanently unpublished.
    $this->artisan('entries:publish-due')
        ->expectsOutputToContain('1 failed')
        ->assertSuccessful();

    $tenancy->enter(1, function () use ($ids): void {
        expect(Entry::query()->whereKey($ids['clashing'])->value('status'))->toBe('scheduled')
            ->and(Entry::query()->whereKey($ids['healthy'])->value('status'))->toBe('published');
    });

    $tenancy->enter(2, function () use ($ids): void {
        expect(Entry::query()->whereKey($ids['other_site'])->value('status'))->toBe('published');
    });
});
