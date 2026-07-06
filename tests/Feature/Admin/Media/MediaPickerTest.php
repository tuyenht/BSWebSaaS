<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Media picker v2 (docs/design_handoff_media_picker, Phase 2) — the two-tab modal on the
 * WithMediaLibraryPicker trait. Covers the §2 invariants at birth (cross-site ids never
 * select/insert/move, every action re-gated), the library tab's server-side filters/sort/
 * pagination, folder CRUD, bulk ops, and the v1 contracts that must keep working
 * (admin-editor-insert-image payload, addGalleryImage order). Catalog\Editor is the host.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * Seed one library master in the CURRENT site context.
 *
 * @param  array<string, mixed>  $attrs
 */
function seedPickerMedia(string $suffix, array $attrs = []): Media
{
    return Media::query()->create(array_merge([
        'collection' => 'library',
        'slug' => "anh-{$suffix}",
        'hash' => hash('sha256', $suffix),
        'storage_key' => "s/library/anh-{$suffix}.jpg",
        'disk' => 'r2',
        'original_filename' => "anh-{$suffix}.jpg",
        'mime' => 'image/jpeg',
        'size' => 1024,
    ], $attrs));
}

/** Real 1×1 PNG bytes for the URL-source fetch fakes (finfo sniffs image/png). */
function pickerPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
}

/**
 * A picker-capable catalog manager for site 1 (media.view + any extra grants).
 *
 * @param  list<string>  $extra
 */
function pickerManager(array $extra = []): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user, $extra): void {
        grantRole($user, 'picker-manager', array_merge(['catalog.view', 'catalog.manage', 'media.view'], $extra));
    });

    return $user->fresh() ?? $user;
}

it('filters the grid by folder, search, format and used/unused — combined AND', function (): void {
    $user = pickerManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $folder = MediaFolder::query()->create(['name' => 'Sản phẩm', 'slug' => 'san-pham']);
        seedPickerMedia('jig', ['folder_id' => $folder->id, 'original_filename' => 'do-ga-phay.jpg']);
        seedPickerMedia('banner', ['original_filename' => 'banner-tet.png', 'mime' => 'image/png', 'storage_key' => 's/library/banner.png']);
        $used = seedPickerMedia('used', ['original_filename' => 'da-dung.jpg']);
        Mediable::query()->create(['media_id' => $used->id, 'mediable_type' => 'product', 'mediable_id' => 1]);

        $component = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery');

        // Folder narrows to its own file.
        $component->call('setPickerFolder', $folder->id);
        expect($component->instance()->mediaPickerState()['page']->pluck('original_filename')->all())
            ->toBe(['do-ga-phay.jpg']);

        // Search hits by name fragment, back on the whole library.
        $component->call('setPickerFolder', null)->set('pickerSearch', 'banner');
        expect($component->instance()->mediaPickerState()['page']->pluck('original_filename')->all())
            ->toBe(['banner-tet.png']);

        // Format narrows by extension label; used/unused follow the attachments.
        $component->set('pickerSearch', '')->set('pickerFormat', 'png');
        expect($component->instance()->mediaPickerState()['page']->pluck('mime')->unique()->all())
            ->toBe(['image/png']);

        $component->set('pickerFormat', 'all')->set('pickerType', 'used');
        expect($component->instance()->mediaPickerState()['page']->pluck('original_filename')->all())
            ->toBe(['da-dung.jpg']);

        $component->set('pickerType', 'unused');
        expect($component->instance()->mediaPickerState()['page']->pluck('original_filename')->all())
            ->not->toContain('da-dung.jpg');
    });
});

it('sorts by name and size and paginates server-side at 24 per page', function (): void {
    $user = pickerManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        foreach (range(1, 30) as $i) {
            seedPickerMedia("m{$i}", [
                'original_filename' => sprintf('anh-%02d.jpg', $i),
                'size' => $i * 100,
            ]);
        }

        $component = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery');

        $component->set('pickerSort', 'nameAsc');
        $state = $component->instance()->mediaPickerState();
        expect($state['page']->count())->toBe(24)
            ->and($state['page']->first()?->original_filename)->toBe('anh-01.jpg')
            ->and($state['page']->lastPage())->toBe(2);

        $component->call('pickerNext');
        expect($component->instance()->mediaPickerState()['page']->count())->toBe(6);

        // Way past the end snaps back to the real last page.
        $component->set('pickerPage', 99);
        expect($component->instance()->mediaPickerState()['page']->currentPage())->toBe(2);

        $component->set('pickerSort', 'sizeDesc')->set('pickerPage', 1);
        expect($component->instance()->mediaPickerState()['page']->first()?->size)->toBe(3000);
    });
});

it('editor mode single-selects and insert dispatches the image event (v1 contract)', function (): void {
    $user = pickerManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $a = seedPickerMedia('one')->id;
        $b = seedPickerMedia('two')->id;

        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'editor')
            ->call('togglePickerItem', $a)
            ->call('togglePickerItem', $b); // editor mode: replaces, never stacks

        expect($component->get('pickerSelected'))->toBe([$b]);

        $component->call('insertPicked')
            ->assertDispatched('admin-editor-insert-image')
            ->assertSet('showImagePicker', false);
    });
});

it('gallery mode inserts the picked items into the host in picked order', function (): void {
    $user = pickerManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $a = seedPickerMedia('ga')->id;
        $b = seedPickerMedia('gb')->id;

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('togglePickerItem', $b)
            ->call('togglePickerItem', $a)
            ->call('insertPicked')
            ->assertSet('imageMediaIds', [$b, $a])
            ->assertSet('showImagePicker', false);
    });
});

it('never selects, filters into, or moves into another site\'s data', function (): void {
    $user = pickerManager(['media.upload']);

    $foreignMedia = app(Tenancy::class)->enter(2, fn (): int => seedPickerMedia('foreign')->id);
    $foreignFolder = app(Tenancy::class)->enter(2, fn (): int => MediaFolder::query()->create(['name' => 'B', 'slug' => 'b'])->id);

    app(Tenancy::class)->enter(1, function () use ($user, $foreignMedia, $foreignFolder): void {
        $mine = seedPickerMedia('mine');

        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('togglePickerItem', $foreignMedia);
        expect($component->get('pickerSelected'))->toBe([]);

        // A foreign folder id in the filter falls back to "all" (never leaks, never errors)…
        $component->call('setPickerFolder', $foreignFolder);
        expect($component->get('pickerFolderId'))->toBeNull();

        // …and a foreign folder as a bulk-move target is a no-op write.
        $component->call('togglePickerItem', $mine->id)->call('pickerBulkMove', $foreignFolder);
        expect($mine->fresh()?->folder_id)->toBeNull();
    });
});

it('bulk delete soft-deletes the selection behind media.delete', function (): void {
    $user = pickerManager(['media.delete']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $a = seedPickerMedia('d1');
        $b = seedPickerMedia('d2');

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('togglePickerItem', $a->id)
            ->call('togglePickerItem', $b->id)
            ->call('pickerBulkDelete')
            ->assertSet('pickerSelected', []);

        expect(Media::query()->count())->toBe(0)
            ->and(Media::query()->withTrashed()->count())->toBe(2);
    });
});

it('403s bulk delete without media.delete and folder create without media.upload', function (): void {
    $user = pickerManager(); // media.view only

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $m = seedPickerMedia('nope');

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('togglePickerItem', $m->id)
            ->call('pickerBulkDelete')
            ->assertStatus(403);

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->set('pickerNewFolderName', 'Cấm')
            ->call('createPickerFolder')
            ->assertStatus(403);
    });
});

it('bulk move re-folders the selection and move-to-root clears it', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $folder = MediaFolder::query()->create(['name' => 'Banner', 'slug' => 'banner']);
        $m = seedPickerMedia('mv');

        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('togglePickerItem', $m->id)
            ->call('pickerBulkMove', $folder->id);
        expect($m->fresh()?->folder_id)->toBe($folder->id);

        $component->call('togglePickerItem', $m->id)->call('pickerBulkMove', null);
        expect($m->fresh()?->folder_id)->toBeNull();
    });
});

it('creates a folder behind media.upload, rejects a duplicate name, and selects the new folder', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->set('pickerNewFolderName', 'Sản phẩm')
            ->call('createPickerFolder');

        $folder = MediaFolder::query()->where('slug', 'san-pham')->first();
        expect($folder)->not->toBeNull()
            ->and($component->get('pickerFolderId'))->toBe($folder?->id);

        $component->set('pickerNewFolderOpen', true)
            ->set('pickerNewFolderName', 'SẢN PHẨM') // same slug, citext
            ->call('createPickerFolder')
            ->assertHasErrors('pickerNewFolderName');
    });
});

it('renders the modal with the library tab, folder sidebar and picker strings', function (): void {
    $user = pickerManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        MediaFolder::query()->create(['name' => 'Bài viết', 'slug' => 'bai-viet']);
        seedPickerMedia('render', ['original_filename' => 'thay-duoc.jpg']);

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->assertSee(__('media.picker_title'))
            ->assertSee(__('media.picker_tab_upload'))
            ->assertSee(__('media.picker_all_images'))
            ->assertSee('Bài viết')
            ->assertSee('thay-duoc.jpg');
    });
});

it('keeps the picker interaction on a flat query budget (no per-row N+1)', function (): void {
    $user = pickerManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $folder = MediaFolder::query()->create(['name' => 'Nhiều', 'slug' => 'nhieu']);
        foreach (range(1, 30) as $i) {
            seedPickerMedia("q{$i}", ['folder_id' => $i % 2 === 0 ? $folder->id : null]);
        }

        $component = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery');

        // One picker update = hydrate + gates + host render + the picker's flat set (page rows,
        // count, folders+counts, total, formats). An N+1 over the 24 visible rows would add 24+
        // queries and blow far past this ceiling.
        $queries = countQueries(fn () => $component->call('setPickerFolder', null));
        expect($queries)->toBeLessThanOrEqual(35);
    });
});

it('rejects a non-image URL shape before any fetch, and previews a valid one', function (): void {
    $user = pickerManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Storage::fake('r2');
        Http::preventStrayRequests();

        $component = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'editor');

        // A page URL (no image extension) is refused by the client-shape guard — no network hit.
        $component->set('pickerUrlInput', 'https://example.com/trang-san-pham')->call('previewPickerUrl')
            ->assertHasErrors('pickerUrlInput');
        Http::assertNothingSent();

        // A valid public image URL previews (the server fetched + sniffed it).
        Http::fake(['https://93.184.216.34/a.jpg' => Http::response(pickerPng(), 200)]);
        $component->set('pickerUrlInput', 'https://93.184.216.34/a.jpg')->call('previewPickerUrl')
            ->assertHasNoErrors()
            ->assertSet('pickerSource', 'url');
        expect($component->instance()->pickerUrlPreview)->not->toBeNull()
            ->and($component->instance()->hasUrlPick())->toBeTrue();
    });
});

it('imports a URL copy (editor, save-copy on) — a real master row + insert event', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Storage::fake('r2');
        Http::fake(['https://93.184.216.34/a.jpg' => Http::response(pickerPng(), 200)]);

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'editor')
            ->set('pickerUrlInput', 'https://93.184.216.34/a.jpg')->call('previewPickerUrl')
            ->assertSet('pickerUrlSaveCopy', true)
            ->call('insertPicked')
            ->assertDispatched('admin-editor-insert-image')
            ->assertSet('showImagePicker', false);

        expect(Media::query()->count())->toBe(1); // the copy became a library master
    });
});

it('hotlinks a URL without importing when save-copy is off (editor)', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Storage::fake('r2');
        Http::fake(['https://93.184.216.34/a.jpg' => Http::response(pickerPng(), 200)]);

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'editor')
            ->set('pickerUrlInput', 'https://93.184.216.34/a.jpg')->call('previewPickerUrl')
            ->set('pickerUrlSaveCopy', false)
            ->call('insertPicked')
            ->assertDispatched('admin-editor-insert-image', url: 'https://93.184.216.34/a.jpg');

        expect(Media::query()->count())->toBe(0); // nothing imported — pure hotlink
    });
});

it('always imports a URL image in gallery mode (a gallery row needs a master)', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Storage::fake('r2');
        Http::fake(['https://93.184.216.34/a.jpg' => Http::response(pickerPng(), 200)]);

        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->set('pickerUrlInput', 'https://93.184.216.34/a.jpg')->call('previewPickerUrl')
            ->call('insertPicked');

        expect(Media::query()->count())->toBe(1)
            ->and($component->get('imageMediaIds'))->toHaveCount(1);
    });
});

it('keeps inserted image links working after a folder is renamed (storage_key, not slug)', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function (): void {
        $folder = MediaFolder::query()->create(['name' => 'Sản phẩm', 'slug' => 'san-pham']);
        $media = seedPickerMedia('keep', ['folder_id' => $folder->id]);
        $before = app(MediaUrlResolver::class)->url($media->storage_key, 1200);

        // Rename + reslug the folder — the image URL is derived from storage_key, never the slug.
        $folder->update(['name' => 'Danh mục mới', 'slug' => 'danh-muc-moi']);

        $after = app(MediaUrlResolver::class)->url($media->fresh()->storage_key, 1200);
        expect($after)->toBe($before)
            ->and($media->fresh()->folder_id)->toBe($folder->id); // still in the same (renamed) folder
    });
});

it('translates every picker string in all four admin languages', function (): void {
    $vi = json_decode((string) file_get_contents(lang_path('vi.json')), true, 512, JSON_THROW_ON_ERROR);
    $pickerKeys = array_filter(array_keys($vi), fn (string $k): bool => str_starts_with($k, 'media.picker_'));
    expect($pickerKeys)->not->toBeEmpty();

    foreach (['en', 'ja', 'zh'] as $locale) {
        $pack = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, 512, JSON_THROW_ON_ERROR);
        $missing = array_values(array_diff($pickerKeys, array_keys($pack)));
        expect($missing)->toBe([]);
    }
});
