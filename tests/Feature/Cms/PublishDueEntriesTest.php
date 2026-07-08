<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
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
