<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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
        $used = seedPickerMedia('used', ['original_filename' => 'da-dung.jpg', 'storage_key' => 's/library/da-dung.jpg']);
        // "Used" = referenced by a product gallery (or an entry cover) — MediaUsageLocator.
        $product = Product::query()->create(['status' => 'published']);
        ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $used->id, 'position' => 0]);

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

it('creates a folder behind media.upload and selects the new folder', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->set('pickerNewFolderName', 'Sản phẩm')
            ->call('createPickerFolder');

        $folder = MediaFolder::query()->where('slug', 'san-pham')->first();
        expect($folder)->not->toBeNull()
            ->and($folder?->name)->toBe('Sản phẩm')          // name keeps its diacritics
            ->and($component->get('pickerFolderId'))->toBe($folder?->id);
    });
});

it('transliterates the slug from the name, previews it live, and de-dups WP-style', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $component = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery');

        // Vietnamese name → diacritic-free ASCII slug, previewed live, no fallback warning.
        $component->set('pickerNewFolderName', 'Đồ gá & Khuôn');
        expect($component->get('pickerNewFolderSlug'))->toBe('do-ga-khuon')
            ->and($component->get('pickerNewFolderSlugFallback'))->toBeFalse();

        // Same base name twice → the second folder de-dups to -2 (never an error).
        $component->call('createPickerFolder');
        $component->set('pickerNewFolderOpen', true)->set('pickerNewFolderName', 'Đồ gá & Khuôn')->call('createPickerFolder');

        expect(MediaFolder::query()->pluck('slug')->sort()->values()->all())->toBe(['do-ga-khuon', 'do-ga-khuon-2']);
    });
});

it('falls back to an auto slug for an all-ideograph name and flags it for editing', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $component = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery')
            ->set('pickerNewFolderName', '製品カタログ'); // no Latin transliteration

        expect($component->get('pickerNewFolderSlug'))->toStartWith('thu-muc-')
            ->and($component->get('pickerNewFolderSlugFallback'))->toBeTrue();

        $component->call('createPickerFolder');
        $folder = MediaFolder::query()->first();
        expect($folder?->name)->toBe('製品カタログ')                 // Unicode name preserved
            ->and($folder?->slug)->toMatch('/^thu-muc-\d+$/');    // ASCII fallback slug
    });
});

it('honours a hand-edited slug over the derived one', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery')
            ->set('pickerNewFolderName', 'Bài viết')
            ->call('editPickerFolderSlug')       // pencil → freeze auto-derive
            ->set('pickerNewFolderName', 'Bài viết đổi tên') // name change no longer moves the slug
            ->set('pickerNewFolderSlug', 'Tin Tức Nổi Bật')  // sanitized on save
            ->call('createPickerFolder');

        expect(MediaFolder::query()->value('slug'))->toBe('tin-tuc-noi-bat');
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
    $user = pickerManager(['media.upload']);

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

it('403s a URL fetch for a view-only actor (fetch is an upload-class action)', function (): void {
    $user = pickerManager(); // media.view only

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Http::preventStrayRequests();

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'editor')
            ->set('pickerUrlInput', 'https://93.184.216.34/a.jpg')
            ->call('previewPickerUrl')
            ->assertStatus(403);
        Http::assertNothingSent();
    });
});

it('drops an un-inserted URL preview\'s staged bytes when the modal closes', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Storage::fake('r2');
        Http::fake(['https://93.184.216.34/a.jpg' => Http::response(pickerPng(), 200)]);

        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'editor')
            ->set('pickerUrlInput', 'https://93.184.216.34/a.jpg')->call('previewPickerUrl');

        $tempKey = $component->instance()->pickerUrlPreview['tempKey'];
        Storage::disk('r2')->assertExists($tempKey);

        $component->call('closeImagePicker');
        Storage::disk('r2')->assertMissing($tempKey); // no abandoned object left behind
    });
});

it('rate-limits URL fetches per user', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Storage::fake('r2');
        // A fresh response per request — one shared instance would drain its body stream after the
        // first read (the streamed fetch reads to eof), making later fetches look empty.
        Http::fake(fn () => Http::response(pickerPng(), 200));

        $component = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'editor');
        // 20/min cap — the 21st distinct URL is throttled, not fetched.
        for ($i = 1; $i <= 20; $i++) {
            $component->set('pickerUrlInput', "https://93.184.216.34/a{$i}.jpg")->call('previewPickerUrl')
                ->assertHasNoErrors();
        }
        $component->set('pickerUrlInput', 'https://93.184.216.34/over.jpg')->call('previewPickerUrl')
            ->assertHasErrors('pickerUrlInput');
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

it('opens the detail panel for a library image and loads its alt in the site locale', function (): void {
    $user = pickerManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $media = seedPickerMedia('detail', ['alt' => ['vi' => 'Đồ gá phay CNC', 'en' => 'CNC milling jig']]);

        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerDetail', $media->id);

        expect($component->get('pickerDetailId'))->toBe($media->id)
            ->and($component->get('pickerDetailAlt'))->toBe('Đồ gá phay CNC'); // site default = vi
        expect($component->instance()->mediaPickerState()['detail']?->id)->toBe($media->id);

        $component->call('closePickerDetail');
        expect($component->get('pickerDetailId'))->toBeNull();
    });
});

it('saves alt for the site locale without touching other locales (media.upload)', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $media = seedPickerMedia('alt', ['alt' => ['en' => 'keep me']]);

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerDetail', $media->id)
            ->set('pickerDetailAlt', '  Bàn gá khoan  ')
            ->call('savePickerDetailAlt');

        $fresh = $media->fresh();
        expect($fresh?->alt)->toBe(['en' => 'keep me', 'vi' => 'Bàn gá khoan']); // trimmed, en preserved
    });
});

it('refuses an alt text longer than the SEO hard cap and saves one at the cap', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $media = seedPickerMedia('altcap', ['alt' => ['vi' => 'giữ nguyên']]);

        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerDetail', $media->id)
            ->set('pickerDetailAlt', str_repeat('ả', 189))
            ->call('savePickerDetailAlt');

        expect($media->fresh()?->alt)->toBe(['vi' => 'giữ nguyên']);
        $component->assertHasErrors(['pickerDetailAlt' => __('media.picker_detail_alt_too_long', ['max' => 188])])
            ->assertSeeHtml(e(__('media.picker_detail_alt_too_long', ['max' => 188])));

        $component->set('pickerDetailAlt', str_repeat('ả', 188))
            ->call('savePickerDetailAlt')
            ->assertHasNoErrors();
        expect($media->fresh()?->alt)->toBe(['vi' => str_repeat('ả', 188)]);
    });
});

it('403s saving alt without media.upload', function (): void {
    $user = pickerManager(); // media.view only

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $media = seedPickerMedia('ro');

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerDetail', $media->id)
            ->set('pickerDetailAlt', 'nope')
            ->call('savePickerDetailAlt')
            ->assertStatus(403);

        expect($media->fresh()?->alt)->toBe([]);
    });
});

it('never opens the detail of another site\'s image', function (): void {
    $user = pickerManager();

    $foreign = app(Tenancy::class)->enter(2, fn (): int => seedPickerMedia('foreign2')->id);

    app(Tenancy::class)->enter(1, function () use ($user, $foreign): void {
        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerDetail', $foreign)
            ->assertSet('pickerDetailId', null); // RLS-scoped resolve → nothing
    });
});

it('shows only trashed media in the trash view and keeps live media out', function (): void {
    $user = pickerManager(['media.delete']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $live = seedPickerMedia('live', ['original_filename' => 'con-song.jpg']);
        $binned = seedPickerMedia('binned', ['original_filename' => 'da-xoa.jpg']);
        $binned->delete();

        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerTrash')
            ->assertSet('pickerTrash', true);

        $names = $component->instance()->mediaPickerState()['page']->pluck('original_filename')->all();
        expect($names)->toBe(['da-xoa.jpg'])                       // only the trashed one
            ->and($component->instance()->mediaPickerState()['trashTotal'])->toBe(1);

        // Leaving trash for a folder returns to live media.
        $component->call('setPickerFolder', null)->assertSet('pickerTrash', false);
        expect($component->instance()->mediaPickerState()['page']->pluck('original_filename')->all())->toBe(['con-song.jpg']);
    });
});

it('restores a trashed image to its original folder', function (): void {
    $user = pickerManager(['media.delete']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $folder = MediaFolder::query()->create(['name' => 'Sản phẩm', 'slug' => 'san-pham']);
        $media = seedPickerMedia('r', ['folder_id' => $folder->id]);
        $media->delete();

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerTrash')
            ->call('togglePickerItem', $media->id)
            ->call('restorePickerSelection')
            ->assertSet('pickerSelected', []);

        $fresh = $media->fresh();
        expect($fresh?->trashed())->toBeFalse()
            ->and($fresh?->folder_id)->toBe($folder->id); // back where it was
    });
});

it('permanently deletes a trashed image + its R2 object (purge fires the observer)', function (): void {
    $user = pickerManager(['media.delete']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Storage::fake('r2');
        $media = seedPickerMedia('p', ['storage_key' => 's/library/purge-me.jpg']);
        Storage::disk('r2')->put($media->storage_key, 'bytes');
        $media->delete();

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerTrash')
            ->call('togglePickerItem', $media->id)
            ->call('purgePickerSelection');

        expect(Media::withTrashed()->whereKey($media->id)->exists())->toBeFalse(); // row gone
        Storage::disk('r2')->assertMissing('s/library/purge-me.jpg');              // object gone
    });
});

it('empties the whole trash but never touches live media', function (): void {
    $user = pickerManager(['media.delete']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Storage::fake('r2');
        $live = seedPickerMedia('keep');
        foreach (['a', 'b', 'c'] as $s) {
            seedPickerMedia("bin-{$s}")->delete();
        }

        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerTrash')
            ->call('emptyPickerTrash');

        expect(Media::withTrashed()->count())->toBe(1)                 // only the live one remains
            ->and(Media::query()->whereKey($live->id)->exists())->toBeTrue();
    });
});

it('403s restore/purge/empty without media.delete', function (): void {
    $user = pickerManager(); // media.view only

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $media = seedPickerMedia('nope');
        $media->delete();

        Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery')
            ->call('openPickerTrash')->set('pickerSelected', [$media->id])
            ->call('restorePickerSelection')->assertStatus(403);

        expect($media->fresh()?->trashed())->toBeTrue(); // untouched
    });
});

it('403s the device upload pane for a view-only actor', function (): void {
    $user = pickerManager(); // media.view only

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'editor')
            ->call('openPickerSource', 'device') // writes real media → needs media.upload
            ->assertStatus(403);
    });
});

it('opens the device pane with media.upload and lands on the library after a batch', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'editor')
            ->call('openPickerSource', 'device')
            ->assertSet('pickerSource', 'device')
            ->dispatch('media-uploaded')                 // the island fires this after register
            ->assertSet('pickerTab', 'library')
            ->assertSet('pickerSource', null);
    });
});

it('shows the uploader and every product/post using the image in the detail panel', function (): void {
    $user = pickerManager(['media.upload']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $media = seedPickerMedia('used', ['created_by' => $user->id, 'storage_key' => 's/library/used.jpg']);

        $product = Product::query()->create(['status' => 'published']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá phay CNC', 'slug' => 'do-ga-phay']);
        ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $media->id, 'position' => 0]);

        $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => ['image_url' => $media->storage_key]]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Bài giới thiệu', 'slug' => 'bai-gt', 'status' => 'published']);

        $component = Livewire::actingAs($user)->test(Editor::class)
            ->call('openImagePicker', 'gallery')
            ->call('openPickerDetail', $media->id);

        $state = $component->instance()->mediaPickerState();
        expect($state['detailUploader'])->toBe($user->name)
            ->and(collect($state['detailUsages'])->pluck('label')->all())->toContain('Đồ gá phay CNC', 'Bài giới thiệu')
            ->and(collect($state['detailUsages'])->pluck('kind')->all())->toContain('product', 'post');
    });
});

it('filters used vs unused across product galleries and entry covers', function (): void {
    $user = pickerManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $inGallery = seedPickerMedia('g', ['storage_key' => 's/library/g.jpg']);
        $asCover = seedPickerMedia('c', ['storage_key' => 's/library/c.jpg']);
        $unused = seedPickerMedia('n', ['storage_key' => 's/library/n.jpg', 'original_filename' => 'chua-dung.jpg']);

        $product = Product::query()->create(['status' => 'published']);
        ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $inGallery->id, 'position' => 0]);
        Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => ['image_url' => $asCover->storage_key]]);

        $component = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery');

        $component->set('pickerType', 'used');
        expect($component->instance()->mediaPickerState()['page']->pluck('id')->all())
            ->toContain($inGallery->id, $asCover->id)->not->toContain($unused->id);

        $component->set('pickerType', 'unused');
        expect($component->instance()->mediaPickerState()['page']->pluck('id')->all())
            ->toContain($unused->id)->not->toContain($inGallery->id, $asCover->id);
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

it('refuses a client-written URL preview, so closing the picker cannot delete another site\'s file', function (): void {
    $user = pickerManager();
    Storage::fake('r2');
    $foreign = 'b/library/logo-ab12.png';
    Storage::disk('r2')->put($foreign, pickerPng());

    app(Tenancy::class)->enter(1, function () use ($user, $foreign): void {
        $component = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery');

        expect(fn () => $component->set('pickerUrlPreview', [
            'tempKey' => $foreign, 'url' => 'https://example.com/x.png', 'filename' => 'x.png',
            'mime' => 'image/png', 'size' => 10, 'width' => null, 'height' => null,
        ]))->toThrow(CannotUpdateLockedPropertyException::class);

        $component->call('closeImagePicker');
    });

    Storage::disk('r2')->assertExists($foreign);
});
