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

it('bulk soft-deletes a selected master with media.delete.any (sends it to the Thùng rác)', function (): void {
    $manager = User::factory()->create();
    $asset = seedMediaAsset(1);

    app(Tenancy::class)->enter(1, function () use ($manager, $asset): void {
        grantRole($manager, 'admin', ['media.view', 'media.delete.any']);

        Livewire::actingAs($manager->fresh())
            ->test(Library::class)
            ->assertSet('pickerManageMode', true)   // the upload panes are the picker's, in manage mode
            ->call('togglePick', $asset->ulid)      // the page picks by ulid, never by bigint id
            ->call('trashPicked');

        expect(Media::query()->find($asset->id))->toBeNull()
            ->and(Media::withTrashed()->find($asset->id))->not->toBeNull();
    });
});

it('forbids bulk delete without a delete right', function (): void {
    $viewer = User::factory()->create();
    $asset = seedMediaAsset(1);

    app(Tenancy::class)->enter(1, function () use ($viewer, $asset): void {
        grantRole($viewer, 'editor', ['media.view']);

        Livewire::actingAs($viewer->fresh())
            ->test(Library::class)
            ->set('picked', [$asset->ulid])
            ->call('confirmTrash')
            ->assertForbidden();
        Livewire::actingAs($viewer->fresh())
            ->test(Library::class)
            ->call('pickerBulkDelete')   // the shared picker's id-based endpoint is closed here
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

    // Nav/entitlement/theme baseline + the picker's flat set (page rows, count, folders+counts,
    // trash count, formats). An N+1 over the 10 rows would blow well past this.
    expect($count)->toBeLessThanOrEqual(30);
});

it('lays the tools out as board 12 draws them: select-all, search, Sort and the view switch on one row, the filters below', function (): void {
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($manager, 'admin', ['media.view', 'media.delete.any']));
    seedMediaAsset(1, ['original_filename' => 'xuong.png']);

    $html = $this->actingAs($manager)->get('http://a.test/admin/media')->assertOk()->getContent();

    // Two layouts, one copy each of «Chọn tất cả» and Sắp xếp: the wide row first, then the narrow one.
    preg_match_all('/aria-controls="(sm-[0-9a-f]{8}(?:-wide)?)"/', $html, $sorts);
    expect(array_values(array_unique($sorts[1])))->toHaveCount(2)
        ->and($sorts[1][0])->toEndWith('-wide')
        ->and(substr_count($html, e(__('media.lib.sel_all_aria'))))->toBe(2);

    // Row 1 in the order seen (the Tab order): select-all, search, Sort, grid / list; the filter buttons come after it.
    $at = fn (string $needle): int|false => strpos($html, $needle);
    $wide = $at('-wide"');
    expect($at(e(__('media.lib.sel_all_aria'))))->toBeLessThan($at('id="media-search"'))
        ->and($at('id="media-search"'))->toBeLessThan($wide)
        ->and($wide)->toBeLessThan($at('aria-label="'.e(__('media.lib.view_label')).'"'))
        ->and($at('aria-label="'.e(__('media.lib.view_label')).'"'))->toBeLessThan($at('data-fsec="kinds"'));
});
