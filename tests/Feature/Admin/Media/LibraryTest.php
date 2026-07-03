<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Admin Media library (Increment ③) — tenant-plane DAM UI. Read is gated by media.view (re-checked
 * in boot() since the livewire/update endpoint bypasses route middleware); delete re-gates
 * media.delete. Masters are RLS-isolated per site, listed newest-first on a flat query budget.
 * Upload no longer flows through this component — it goes browser → R2 → register via the
 * media.{presign,register,upload} endpoints (covered by UploadEndpointsTest). Delete is soft (Thùng
 * rác — the R2 object + attachments survive until a force-purge, covered by MediaObserverTest).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
});

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

it('lets a member with media.view open the library', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['media.view']));
    seedMediaAsset(1, ['original_filename' => 'bao-gia.png']);

    $this->actingAs($viewer)->get('http://a.test/admin/media')
        ->assertOk()
        ->assertSee(__('media.library_title'))
        ->assertSee('bao-gia.png');
});

it('forbids a member without media.view', function (): void {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));

    $this->actingAs($author)->get('http://a.test/admin/media')->assertForbidden();
});

it('isolates media per site (RLS) — site A library never shows site B assets', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['media.view']));
    seedMediaAsset(1, ['original_filename' => 'site-a-asset.png', 'hash' => str_repeat('1', 64), 'storage_key' => 'a/library/x-111111111111.png']);
    seedMediaAsset(2, ['original_filename' => 'site-b-asset.png', 'hash' => str_repeat('2', 64), 'storage_key' => 'b/library/x-222222222222.png']);

    $this->actingAs($viewer)->get('http://a.test/admin/media')
        ->assertOk()
        ->assertSee('site-a-asset.png')
        ->assertDontSee('site-b-asset.png');
});

it('soft-deletes a master with media.delete (sends it to the Thùng rác)', function (): void {
    $manager = User::factory()->create();
    $asset = seedMediaAsset(1);

    app(Tenancy::class)->enter(1, function () use ($manager, $asset): void {
        grantRole($manager, 'admin', ['media.view', 'media.delete']);

        Livewire::actingAs($manager->fresh())
            ->test(Library::class)
            ->call('delete', $asset->ulid);

        expect(Media::query()->find($asset->id))->toBeNull()
            ->and(Media::withTrashed()->find($asset->id))->not->toBeNull();
    });
});

it('forbids delete without media.delete', function (): void {
    $viewer = User::factory()->create();
    $asset = seedMediaAsset(1);

    app(Tenancy::class)->enter(1, function () use ($viewer, $asset): void {
        grantRole($viewer, 'editor', ['media.view']);

        Livewire::actingAs($viewer->fresh())
            ->test(Library::class)
            ->call('delete', $asset->ulid)
            ->assertForbidden();

        expect(Media::query()->find($asset->id))->not->toBeNull();
    });
});

it('lists the library on a flat query budget (no N+1 across rows)', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($viewer): void {
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
