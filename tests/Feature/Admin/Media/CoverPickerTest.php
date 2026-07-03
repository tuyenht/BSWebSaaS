<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * Increment ④ — cover/OG image picker in the content editor. The chosen master's storage_key is
 * stored in entry.meta['image_url'] (no entries migration — StorefrontController resolves it to an
 * absolute og:image and EntryGridComponent to a card thumbnail, both via MediaUrlResolver). The
 * picker is gated by media.view (re-checked in the action since livewire/update bypasses route
 * middleware) and only accepts a storage_key from THIS site's library (RLS-scoped) — never an
 * arbitrary/cross-site string from the wire payload.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Create a media in the CURRENT site context — the caller wraps Tenancy::enter (no nesting). */
function seedPickableMedia(array $attrs = []): Media
{
    return Media::query()->create(array_merge([
        'collection' => 'library',
        'slug' => 'anh',
        'hash' => str_repeat('a', 64),
        'storage_key' => 'a/library/anh-aaaaaaaaaaaa.png',
        'disk' => 'r2',
        'mime' => 'image/png',
        'size' => 1024,
    ], $attrs));
}

it('stores the picked cover as meta[image_url] = storage_key on save', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'Trang A')
            ->set('slug', 'trang-a')
            ->call('addBlock', 'richtext')
            ->call('selectCover', $media->storage_key)
            ->assertSet('coverImageKey', $media->storage_key)
            ->call('save')
            ->assertHasNoErrors();

        $entry = Entry::query()->where('type', 'page')->first();
        expect(data_get($entry?->meta, 'image_url'))->toBe($media->storage_key);
    });
});

it('loads an existing cover into the editor on edit', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia();

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => ['image_url' => $media->storage_key]]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'A', 'slug' => 'a', 'status' => 'draft', 'body' => ['blocks' => []]]);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $entry->ulid])
            ->assertSet('coverImageKey', $media->storage_key);
    });
});

it('clears the cover (removes meta[image_url]) on save', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia();

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => ['image_url' => $media->storage_key]]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'A', 'slug' => 'a', 'status' => 'draft', 'body' => ['blocks' => []]]);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $entry->ulid])
            ->call('clearCover')
            ->assertSet('coverImageKey', '')
            ->call('save')
            ->assertHasNoErrors();

        expect(data_get(Entry::query()->find($entry->id)?->meta, 'image_url'))->toBeNull();
    });
});

it('ignores a storage_key that is not in this site library (RLS / no injection)', function (): void {
    $editor = User::factory()->create();
    $foreign = app(Tenancy::class)->enter(2, fn (): Media => seedPickableMedia([
        'storage_key' => 'b/library/foreign-bbbbbbbbbbbb.png', 'hash' => str_repeat('b', 64),
    ]));

    app(Tenancy::class)->enter(1, function () use ($editor, $foreign): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('selectCover', $foreign->storage_key)   // belongs to site 2 — invisible under RLS
            ->assertSet('coverImageKey', '');               // rejected, nothing stored
    });
});

it('forbids picking a cover without media.view', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']); // no media.view
        $media = seedPickableMedia();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('selectCover', $media->storage_key)
            ->assertForbidden();
    });
});

it('shows only this site library in the picker (RLS)', function (): void {
    $editor = User::factory()->create();
    app(Tenancy::class)->enter(2, fn (): Media => seedPickableMedia([
        'storage_key' => 'b/library/site-b-cover.png', 'hash' => str_repeat('2', 64), 'original_filename' => 'site-b-cover.png',
    ]));

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        seedPickableMedia(['original_filename' => 'site-a-cover.png']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('openCoverPicker')
            ->assertSee('site-a-cover.png')
            ->assertDontSee('site-b-cover.png');
    });
});
