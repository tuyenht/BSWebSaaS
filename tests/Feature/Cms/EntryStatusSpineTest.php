<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * P1 data spine (Pages-admin, design §3a). entries_status_check now admits the design's two
 * extra page states (`scheduled`, `private`), entries carry a denormalized view_count, and
 * EntryStatus is the canonical set. The design's states must persist, and the two new
 * non-public states must stay non-public — RouteResolver serves only `published`.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('persists the two new page states without a CHECK violation', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        foreach (['scheduled', 'private'] as $status) {
            $entry = Entry::query()->create(['type' => 'page', 'status' => $status]);
            expect($entry->fresh()->status)->toBe($status);
        }
    });
});

it('defaults view_count to 0 and stores an increment as an int', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        expect($entry->fresh()->view_count)->toBe(0);

        $entry->update(['view_count' => 1280]);
        expect($entry->fresh()->view_count)->toBe(1280);
    });
});

it('maps every canonical status and marks only published public', function (): void {
    expect(EntryStatus::from('scheduled'))->toBe(EntryStatus::Scheduled)
        ->and(EntryStatus::from('private'))->toBe(EntryStatus::Private)
        ->and(EntryStatus::Published->isPublic())->toBeTrue()
        ->and(EntryStatus::Scheduled->isPublic())->toBeFalse()
        ->and(EntryStatus::Private->isPublic())->toBeFalse()
        ->and(EntryStatus::Draft->isPublic())->toBeFalse();
});

it('keeps a private page non-public even with a live route', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        $page->translations()->create([
            'locale' => 'vi', 'title' => 'Nội bộ', 'slug' => 'noi-bo', 'status' => 'draft', 'body' => ['blocks' => []],
        ]);
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

        // Deliberately make the live page private — its route row still exists.
        $page->update(['status' => 'private']);
    });

    $this->get('http://a.test/noi-bo')->assertNotFound();
});
