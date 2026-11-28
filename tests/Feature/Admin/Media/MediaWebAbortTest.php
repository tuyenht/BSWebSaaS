<?php

use App\Modules\Identity\Models\User;
use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\TrashMedia;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Services\SiteCounters;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * A web request whose database write fails. The page and the upload endpoints run inside the tenant
 * transaction the middleware opens (Tenancy::enter); an error in an action becomes an error page, or
 * the endpoint's own «try again», inside that transaction, which then ends with a COMMIT. After a
 * failed statement PostgreSQL treats that COMMIT as a rollback, but Laravel runs the after-commit
 * callbacks and none of the after-rollback ones. So the media actions keep their work in a savepoint
 * of their own: a failure there really rolls back, with the stored-file deletes a purge queued (they
 * must not run: the files are still in the Trash) and with the cleanup of a copy an upload stored
 * (it must run: no row names the copy).
 *
 * The failure is the storage total's row held by another transaction (on pgsql_admin) past
 * SiteCounters::LOCK_WAIT, as a slow request of the same website would.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
    config(['media.storage_enforce' => false]); // today's setting: nothing is refused for room
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/** A site-1 member holding exactly $rights. */
function mwabMember(array $rights): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', $rights));

    return $user->fresh();
}

/** A real 1×1 PNG; $tail after its end makes another file (another hash) that still sniffs as PNG. */
function mwabPng(string $tail): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    ).$tail;
}

function mwabTotal(string $metric = MediaStorage::BYTES): int
{
    return (int) DB::connection('pgsql_admin')->table('site_counters')
        ->where('site_id', 1)->where('metric', $metric)->value('value');
}

/** Runs $request while another transaction holds site 1's `media.bytes` row; returns what $request returned. */
function mwabWhileTotalHeld(Closure $request): mixed
{
    $admin = DB::connection('pgsql_admin');
    $admin->beginTransaction();
    try {
        $admin->update("update site_counters set value = value where site_id = 1 and metric = 'media.bytes'");

        return $request();
    } finally {
        $admin->rollBack();
    }
}

it('keeps the files of a purge from the library page whose total cannot be written', function (): void {
    $user = mwabMember(['media.view', 'media.upload', 'media.delete.any', 'media.purge']);
    $media = app(Tenancy::class)->enter(1, function (): Media {
        $media = app(UploadMedia::class)->handle(UploadedFile::fake()->createWithContent('may-phay.png', mwabPng('purge')));
        app(TrashMedia::class)->trash(new Collection([$media]));

        return $media;
    });
    $size = (int) $media->size;

    $page = (string) $this->actingAs($user)->get('http://a.test/admin/media')->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]*)"/', $page, $found);
    $snapshot = collect($found[1])->map(fn (string $s): string => html_entity_decode($s))
        ->first(fn (string $s): bool => array_key_exists('purgeAll', (array) (json_decode($s, true)['data'] ?? [])));
    expect($snapshot)->toBeString();

    // «Xóa vĩnh viễn» on the whole Trash, through the livewire/update endpoint and its middleware
    $status = mwabWhileTotalHeld(fn (): int => $this->actingAs($user)->withHeader('X-Livewire', '1')
        ->postJson('http://a.test/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => [],
            'calls' => [['method' => 'askPurge', 'params' => [true], 'metadata' => []], ['method' => 'confirmPurge', 'params' => [], 'metadata' => []]],
        ]]])->status());

    expect($status)->toBe(500)
        ->and(app(Tenancy::class)->enter(1, fn () => Media::onlyTrashed()->pluck('id')->all()))->toBe([$media->id])
        ->and(Storage::disk('r2')->exists($media->storage_key))->toBeTrue() // still in the Trash, file and all
        ->and(mwabTotal())->toBe($size)
        ->and(mwabTotal(MediaStorage::TRASH))->toBe($size);
});

it('keeps no stored copy of a new file whose total cannot be written, with the storage switch off', function (string $path): void {
    $user = mwabMember(['media.view', 'media.upload']);
    app(Tenancy::class)->enter(1, fn () => app(SiteCounters::class)->add(MediaStorage::BYTES, 100)); // the total's row exists

    $tempKey = '_incoming/a/01abort.png';
    if ($path === 'register') {
        Storage::disk('r2')->put($tempKey, mwabPng('new'));
        Cache::put(PresignMediaUpload::ownershipKey($tempKey), 1, now()->addMinutes(5));
    }

    $status = mwabWhileTotalHeld(fn (): int => ($path === 'upload'
        ? $this->actingAs($user)->post('http://a.test/admin/media/upload', ['file' => UploadedFile::fake()->createWithContent('new.png', mwabPng('new'))], ['Accept' => 'application/json'])
        : $this->actingAs($user)->postJson('http://a.test/admin/media/register', ['tempKey' => $tempKey, 'filename' => 'new.png'])
    )->status());

    expect($status)->toBe(500) // the endpoint's own «try again»
        ->and(app(Tenancy::class)->enter(1, fn () => Media::withTrashed()->count()))->toBe(0)
        ->and(Storage::disk('r2')->allFiles())->toBe([])
        ->and(mwabTotal())->toBe(100);
})->with(['an upload through the server' => 'upload', 'a presigned upload' => 'register']);
