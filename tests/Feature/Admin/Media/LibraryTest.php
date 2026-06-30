<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Admin Media library (Increment ③) — tenant-plane DAM UI. Read is gated by media.view (re-checked
 * in boot() since the livewire/update endpoint bypasses route middleware); upload re-gates
 * media.upload and delete re-gates media.delete (the view/write split). Masters are RLS-isolated per
 * site, listed newest-first on a flat query budget. Upload routes through the UploadMedia action
 * (magic-byte sniff + content-hash dedup → one R2 master). Delete is soft (Thùng rác — the R2 object
 * + attachments survive until a force-purge, covered by MediaObserverTest).
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
});

/** A real 1×1 PNG (finfo sniffs image/png; no GD needed — mirrors UploadMediaTest). */
function libraryPng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );
}

/**
 * @param  array<string, mixed>  $attrs
 */
function seedMediaAsset(int $site, array $attrs = []): Media
{
    return app(Tenancy::class)->enter($site, fn () => Media::query()->create(array_merge([
        'collection' => 'library',
        'slug' => 'anh',
        'hash' => str_repeat('a', 64),
        'storage_key' => 'a/library/anh-aaaaaaaaaaaa.png',
        'mime' => 'image/png',
        'size' => 1024,
    ], $attrs)));
}

it('lets a member with media.view open the library', function () {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['media.view']));
    seedMediaAsset(1, ['original_filename' => 'bao-gia.png']);

    $this->actingAs($viewer)->get('http://a.test/admin/media')
        ->assertOk()
        ->assertSee(__('media.library_title'))
        ->assertSee('bao-gia.png');
});

it('forbids a member without media.view', function () {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));

    $this->actingAs($author)->get('http://a.test/admin/media')->assertForbidden();
});

it('isolates media per site (RLS) — site A library never shows site B assets', function () {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['media.view']));
    seedMediaAsset(1, ['original_filename' => 'site-a-asset.png', 'hash' => str_repeat('1', 64), 'storage_key' => 'a/library/x-111111111111.png']);
    seedMediaAsset(2, ['original_filename' => 'site-b-asset.png', 'hash' => str_repeat('2', 64), 'storage_key' => 'b/library/x-222222222222.png']);

    $this->actingAs($viewer)->get('http://a.test/admin/media')
        ->assertOk()
        ->assertSee('site-a-asset.png')
        ->assertDontSee('site-b-asset.png');
});

it('uploads an image through the component (media.upload) → one R2 master + a site-stamped row', function () {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['media.view', 'media.upload']);

        Livewire::actingAs($editor->fresh())
            ->test(Library::class)
            ->set('upload', UploadedFile::fake()->createWithContent('Bảng Giá.png', libraryPng()))
            ->call('save')
            ->assertHasNoErrors();

        $media = Media::query()->first();
        expect($media)->not->toBeNull()
            ->and($media->site_id)->toBe(1)
            ->and($media->collection)->toBe('library')
            ->and($media->mime)->toBe('image/png')
            ->and($media->created_by)->toBe($editor->id);
        Storage::disk('r2')->assertExists($media->storage_key);
    });
});

it('forbids upload without media.upload', function () {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer) {
        grantRole($viewer, 'editor', ['media.view']);

        Livewire::actingAs($viewer->fresh())
            ->test(Library::class)
            ->set('upload', UploadedFile::fake()->createWithContent('x.png', libraryPng()))
            ->call('save')
            ->assertForbidden();

        expect(Media::query()->count())->toBe(0);
    });
});

it('rejects a spoofed extension at the component (plain-language error, no row)', function () {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['media.view', 'media.upload']);

        // A .png whose bytes are PHP — finfo sniffs the real content (text/x-php), so the mimes
        // rule (first gate) and the action's magic-byte sniff (second gate) both reject it; the
        // user sees a friendly error, not a 500, and no row is written.
        $spoofed = UploadedFile::fake()->createWithContent('evil.png', '<?php echo "x"; ?>');

        Livewire::actingAs($editor->fresh())
            ->test(Library::class)
            ->set('upload', $spoofed)
            ->call('save')
            ->assertHasErrors('upload');

        expect(Media::query()->count())->toBe(0);
    });
});

it('soft-deletes a master with media.delete (sends it to the Thùng rác)', function () {
    $manager = User::factory()->create();
    $asset = seedMediaAsset(1);

    app(Tenancy::class)->enter(1, function () use ($manager, $asset) {
        grantRole($manager, 'admin', ['media.view', 'media.delete']);

        Livewire::actingAs($manager->fresh())
            ->test(Library::class)
            ->call('delete', $asset->ulid);

        expect(Media::query()->find($asset->id))->toBeNull()
            ->and(Media::withTrashed()->find($asset->id))->not->toBeNull();
    });
});

it('forbids delete without media.delete', function () {
    $viewer = User::factory()->create();
    $asset = seedMediaAsset(1);

    app(Tenancy::class)->enter(1, function () use ($viewer, $asset) {
        grantRole($viewer, 'editor', ['media.view']);

        Livewire::actingAs($viewer->fresh())
            ->test(Library::class)
            ->call('delete', $asset->ulid)
            ->assertForbidden();

        expect(Media::query()->find($asset->id))->not->toBeNull();
    });
});

it('lists the library on a flat query budget (no N+1 across rows)', function () {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($viewer) {
        grantRole($viewer, 'editor', ['media.view']);
        foreach (range(1, 10) as $i) {
            seedMediaAsset(1, [
                'hash' => hash('sha256', "asset-{$i}"),
                'storage_key' => "a/library/asset-{$i}.png",
                'original_filename' => "asset-{$i}.png",
            ]);
        }
    });

    $count = countQueries(fn () => $this->actingAs($viewer)->get('http://a.test/admin/media')->assertOk());

    expect($count)->toBeLessThanOrEqual(18); // flat; an N+1 over the rows would exceed this
});
