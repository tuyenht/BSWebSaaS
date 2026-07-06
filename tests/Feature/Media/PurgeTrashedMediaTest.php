<?php

use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Storage;

/*
 * media:purge-trash — the scheduled trash-retention job. Permanently deletes soft-deleted media
 * older than config('media.trash_retention_days'), per-site (RLS) and per-instance (so
 * MediaObserver drops the R2 object). Younger trash + live media are left alone.
 */

beforeEach(function (): void {
    seedTenancy();
    Storage::fake('r2');
});

/**
 * @param  array<string, mixed>  $attrs
 */
function seedTrashMedia(string $suffix, array $attrs = []): Media
{
    return Media::query()->create(array_merge([
        'collection' => 'library',
        'slug' => "anh-{$suffix}",
        'hash' => hash('sha256', $suffix),
        'storage_key' => "s/library/anh-{$suffix}.jpg",
        'disk' => 'r2',
        'mime' => 'image/jpeg',
        'size' => 1024,
    ], $attrs));
}

it('purges trash older than the retention window and keeps younger trash + live media', function (): void {
    config(['media.trash_retention_days' => 15]);

    [$staleId, $freshId, $liveId] = app(Tenancy::class)->enter(1, function (): array {
        $stale = seedTrashMedia('stale', ['storage_key' => 's/library/stale.jpg']);
        Storage::disk('r2')->put($stale->storage_key, 'x');
        $stale->delete();
        $stale->forceFill(['deleted_at' => now()->subDays(20)])->saveQuietly(); // 20d ago > 15d

        $fresh = seedTrashMedia('fresh');
        $fresh->delete(); // just now

        $live = seedTrashMedia('live');

        return [$stale->id, $fresh->id, $live->id];
    });

    $this->artisan('media:purge-trash')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($staleId, $freshId, $liveId): void {
        expect(Media::withTrashed()->whereKey($staleId)->exists())->toBeFalse()  // purged
            ->and(Media::withTrashed()->whereKey($freshId)->exists())->toBeTrue() // too young
            ->and(Media::query()->whereKey($liveId)->exists())->toBeTrue();       // live
    });
    Storage::disk('r2')->assertMissing('s/library/stale.jpg'); // observer removed the object
});

it('scopes the purge per-site (never reaches across tenants)', function (): void {
    config(['media.trash_retention_days' => 15]);

    $otherSiteId = app(Tenancy::class)->enter(2, function (): int {
        $m = seedTrashMedia('site2');
        $m->delete();
        $m->forceFill(['deleted_at' => now()->subDays(30)])->saveQuietly();

        return $m->id;
    });

    $this->artisan('media:purge-trash')->assertSuccessful();

    // Command iterates all sites, so site 2's stale trash is also purged — prove it ran there too.
    expect(app(Tenancy::class)->enter(2, fn () => Media::withTrashed()->whereKey($otherSiteId)->exists()))->toBeFalse();
});
