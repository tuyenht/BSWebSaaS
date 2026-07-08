<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryViewCounter;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * P8 — the Valkey-buffered view counter. Storefront views are bumped in Valkey (no Postgres write
 * on a GET); entries:flush-views drains each site's buffer into entries.view_count. Best-effort +
 * fail-open: a Valkey outage never breaks a page and simply drops the count.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    // Clear any buffer a prior run left so the counts are deterministic.
    $counter = app(EntryViewCounter::class);
    $counter->drain(1);
    $counter->drain(2);
});

it('buffers views in Valkey and flushes them into view_count', function (): void {
    $tenancy = app(Tenancy::class);
    $counter = app(EntryViewCounter::class);
    $entry = null;

    $tenancy->enter(1, function () use (&$entry): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published']);
    });

    $counter->record(1, $entry->id);
    $counter->record(1, $entry->id);
    $counter->record(1, $entry->id);

    $tenancy->enter(1, function () use ($entry): void {
        expect($entry->fresh()->view_count)->toBe(0); // buffered only — not yet in Postgres
    });

    $this->artisan('entries:flush-views')->assertSuccessful();

    $tenancy->enter(1, function () use ($entry): void {
        expect($entry->fresh()->view_count)->toBe(3);
    });
});

it('counts a real storefront page view through to view_count', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();
    $entry = null;

    $tenancy->enter(1, function () use ($editor, &$entry): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => 'Xem thử', 'slug' => 'xem-thu', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'X']]]],
        ]);
        app(PublishEntry::class)->handle($entry->fresh(), 'vi', $editor->fresh());
    });

    $this->get('http://a.test/xem-thu')->assertOk(); // storefront view → buffered in Valkey

    $this->artisan('entries:flush-views')->assertSuccessful();

    $tenancy->enter(1, function () use ($entry): void {
        expect($entry->fresh()->view_count)->toBe(1);
    });
});
