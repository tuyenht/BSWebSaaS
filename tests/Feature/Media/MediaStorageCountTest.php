<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\PurgeMedia;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\TrashMedia;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\EnsureMediaFromKey;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Services\SiteCounters;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The media library's two running totals (MediaStorage): `media.bytes`, the master size of every file
 * the website keeps (its Trash included), and `media.trash_bytes`, the part in the Trash. Each path
 * that adds, trashes, restores or deletes a file moves them in its own transaction; a file uploaded
 * again (the same content) adds nothing. Refusing an upload past the plan is behind the storage
 * switch, OFF by default: then nothing is ever refused.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
    config(['media.storage_enforce' => false]);
});

/** A real 1×1 PNG; $tail after its end makes another file (another hash) that still sniffs as PNG. */
function mscPng(string $tail = ''): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    ).$tail;
}

/**
 * Site $site's two totals: read in the open tenant context when a test is inside one (a nested
 * enter() would end that context), else in a context of its own.
 *
 * @return array{bytes: int, trash: int}
 */
function mscTotals(int $site = 1): array
{
    $read = fn (): array => app(SiteCounters::class)->read([MediaStorage::BYTES, MediaStorage::TRASH])['values'];
    $v = app(CurrentSite::class)->hasId() ? $read() : app(Tenancy::class)->enter($site, $read);

    return ['bytes' => $v[MediaStorage::BYTES], 'trash' => $v[MediaStorage::TRASH]];
}

function mscUpload(int $site, string $name, string $bytes, bool $mayRestore = false): Media
{
    return app(Tenancy::class)->enter($site, fn () => app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent($name, $bytes), mayRestore: $mayRestore));
}

function mscRegister(int $site, string $tempKey, string $bytes): Media
{
    Storage::disk('r2')->put($tempKey, $bytes);
    Cache::put(PresignMediaUpload::ownershipKey($tempKey), $site, now()->addMinutes(5));

    return app(Tenancy::class)->enter($site, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'tep.png'));
}

function mscLimitMb(int $site, int $mb): void
{
    app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->create(['feature_key' => MediaStorage::FEATURE, 'value' => $mb]));
}

/** @param  list<string>  $rights */
function mscMember(array $rights): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', $rights));

    return $user->fresh();
}

it('counts a new upload once, and nothing for the same content uploaded again', function (): void {
    $a = mscUpload(1, 'a.png', mscPng('a'));
    expect(mscTotals())->toBe(['bytes' => $a->size, 'trash' => 0]);

    mscUpload(1, 'a-again.png', mscPng('a')); // a dedup hit: the same row
    $b = mscRegister(1, '_incoming/x/01b.png', mscPng('bb'));
    expect(mscTotals())->toBe(['bytes' => $a->size + $b->size, 'trash' => 0])
        // the other website's totals did not move
        ->and(mscTotals(2))->toBe(['bytes' => 0, 'trash' => 0]);
});

it('counts a file filed from a stored key (a seed), without ever refusing it', function (): void {
    config(['media.storage_enforce' => true]);
    mscLimitMb(1, 0);
    Storage::disk('r2')->put(siteCode(1).'/seed-0123456789ab.png', mscPng('seed'));

    $media = app(Tenancy::class)->enter(1, fn () => app(EnsureMediaFromKey::class)->handle(siteCode(1).'/seed-0123456789ab.png', ['size' => 321]));
    app(Tenancy::class)->enter(1, fn () => app(EnsureMediaFromKey::class)->handle(siteCode(1).'/seed-0123456789ab.png'));

    expect($media->wasRecentlyCreated)->toBeTrue()->and(mscTotals())->toBe(['bytes' => 321, 'trash' => 0]);
});

it('moves only the Trash total on trash and restore, and takes both down on a purge', function (): void {
    $a = mscUpload(1, 'a.png', mscPng('a'));
    $b = mscUpload(1, 'b.png', mscPng('bbb'));
    $all = $a->size + $b->size;

    app(Tenancy::class)->enter(1, fn () => app(TrashMedia::class)->trash(new Collection([$a, $b])));
    expect(mscTotals())->toBe(['bytes' => $all, 'trash' => $all]);

    // trashing a file already in the Trash moves nothing
    $again = app(Tenancy::class)->enter(1, fn () => app(TrashMedia::class)->trash(new Collection([Media::withTrashed()->find($a->id)])));
    expect($again)->toHaveCount(0)->and(mscTotals())->toBe(['bytes' => $all, 'trash' => $all]);

    app(Tenancy::class)->enter(1, fn () => app(TrashMedia::class)->restore(new Collection([Media::withTrashed()->find($a->id)])));
    expect(mscTotals())->toBe(['bytes' => $all, 'trash' => $b->size]);

    // a purge of one in the Trash and one not: both leave the stored total, the trashed one the Trash total
    $n = app(Tenancy::class)->enter(1, fn () => app(PurgeMedia::class)->handle(Media::withTrashed()->whereKey([$a->id, $b->id])->get()));
    expect($n)->toBe(2)->and(mscTotals())->toBe(['bytes' => 0, 'trash' => 0])
        ->and(app(Tenancy::class)->enter(1, fn () => Media::withTrashed()->count()))->toBe(0);
});

it('takes a file back out of the Trash on a re-upload of its content, counting it out of the Trash only', function (): void {
    $a = mscUpload(1, 'a.png', mscPng('a'));
    app(Tenancy::class)->enter(1, fn () => app(TrashMedia::class)->trash(new Collection([$a])));

    mscUpload(1, 'a-again.png', mscPng('a'), mayRestore: true);

    expect(mscTotals())->toBe(['bytes' => $a->size, 'trash' => 0]);
});

it('counts the library screen’s trash, undo, restore and purge', function (): void {
    $user = mscMember(['media.view', 'media.delete.any', 'media.purge']);
    $a = mscUpload(1, 'a.png', mscPng('a'));
    $b = mscUpload(1, 'b.png', mscPng('bb'));
    $all = $a->size + $b->size;

    app(Tenancy::class)->enter(1, function () use ($user, $a, $b, $all): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('togglePick', $a->ulid)->call('togglePick', $b->ulid)->call('trashPicked');
        expect(mscTotals())->toBe(['bytes' => $all, 'trash' => $all]);

        $page->call('undo');
        expect(mscTotals())->toBe(['bytes' => $all, 'trash' => 0]);

        $page->call('togglePick', $a->ulid)->call('trashPicked')
            ->call('setFolder', '~trash')->call('togglePick', $a->ulid)->call('restorePicked');
        expect(mscTotals())->toBe(['bytes' => $all, 'trash' => 0]);

        $page->call('setFolder', '')->call('togglePick', $b->ulid)->call('trashPicked')
            ->call('setFolder', '~trash')->call('askPurge', true)->call('confirmPurge');
        expect(mscTotals())->toBe(['bytes' => $a->size, 'trash' => 0]);
    });
});

it('counts the shared picker’s Trash, restore, purge and emptying the Trash (by id)', function (): void {
    $user = mscMember(['catalog.view', 'catalog.manage', 'media.view', 'media.delete.any', 'media.purge']);
    $a = mscUpload(1, 'a.png', mscPng('a'));
    $b = mscUpload(1, 'b.png', mscPng('bb'));
    $c = mscUpload(1, 'c.png', mscPng('ccc'));
    $all = $a->size + $b->size + $c->size;

    app(Tenancy::class)->enter(1, function () use ($user, $a, $b, $c, $all): void {
        $picker = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery')
            ->call('togglePickerItem', $a->id)->call('togglePickerItem', $b->id)->call('togglePickerItem', $c->id)
            ->call('pickerBulkDelete');
        expect(mscTotals())->toBe(['bytes' => $all, 'trash' => $all]);

        $picker->call('openPickerTrash')->call('togglePickerItem', $a->id)->call('restorePickerSelection');
        expect(mscTotals())->toBe(['bytes' => $all, 'trash' => $b->size + $c->size]);

        $picker->call('togglePickerItem', $b->id)->call('purgePickerSelection');
        expect(mscTotals())->toBe(['bytes' => $a->size + $c->size, 'trash' => $c->size]);

        $picker->call('emptyPickerTrash');
        expect(mscTotals())->toBe(['bytes' => $a->size, 'trash' => 0]);
    });
});

it('counts the nightly purge of old Trash', function (): void {
    $a = mscUpload(1, 'a.png', mscPng('a'));
    $b = mscUpload(1, 'b.png', mscPng('bb'));
    app(Tenancy::class)->enter(1, fn () => app(TrashMedia::class)->trash(new Collection([$a, $b])));
    app(Tenancy::class)->enter(1, fn () => Media::onlyTrashed()->whereKey($a->id)->update(['deleted_at' => now()->subDays(40)]));

    $this->artisan('media:purge-trash')->assertSuccessful();

    expect(mscTotals())->toBe(['bytes' => $b->size, 'trash' => $b->size]);
});

it('never refuses an upload while the storage switch is off, even far past the plan', function (): void {
    mscLimitMb(1, 0);

    $a = mscUpload(1, 'a.png', mscPng('a'));
    $b = mscRegister(1, '_incoming/x/01b.png', mscPng('bb'));
    $presign = app(Tenancy::class)->enter(1, fn () => app(MediaStorage::class)->fits(50 * 1048576));

    expect($presign)->toBeTrue()->and(mscTotals()['bytes'])->toBe($a->size + $b->size);
});

it('refuses an upload past the plan once the storage switch is on, keeping nothing of it', function (): void {
    config(['media.storage_enforce' => true]);
    mscLimitMb(1, 1); // 1 MB
    app(Tenancy::class)->enter(1, fn () => app(SiteCounters::class)->add(MediaStorage::BYTES, 1048576 - 50));

    // the early answer before the bytes travel
    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(PresignMediaUpload::class)->handle('a.png', 'image/png', 100, 'a')))
        ->toThrow(fn (MediaRejectedException $e) => expect($e->reason)->toBe('storage_full'));

    // the authoritative check, in the statement that counts the file
    $tempKey = '_incoming/x/01c.png';
    expect(fn () => mscRegister(1, $tempKey, mscPng(str_repeat('c', 60))))
        ->toThrow(fn (MediaRejectedException $e) => expect($e->reason)->toBe('storage_full'));
    expect(app(Tenancy::class)->enter(1, fn () => Media::withTrashed()->count()))->toBe(0)
        ->and(Storage::disk('r2')->allFiles())->toBe([])
        ->and(mscTotals()['bytes'])->toBe(1048576 - 50);

    expect(fn () => mscUpload(1, 'd.png', mscPng(str_repeat('d', 60))))
        ->toThrow(fn (MediaRejectedException $e) => expect($e->reason)->toBe('storage_full'));
    expect(app(Tenancy::class)->enter(1, fn () => Media::withTrashed()->count()))->toBe(0)
        ->and(Storage::disk('r2')->allFiles())->toBe([]);

    // a file that fits still goes in (the site's own override beat the plan's 500 MB)
    app(Tenancy::class)->enter(1, fn () => app(SiteCounters::class)->add(MediaStorage::BYTES, -1000));
    $ok = mscUpload(1, 'e.png', mscPng('e'));
    expect(mscTotals()['bytes'])->toBe(1048576 - 1050 + $ok->size);
});
