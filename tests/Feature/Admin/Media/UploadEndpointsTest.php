<?php

use App\Modules\Identity\Models\User;
use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/*
 * DAM upload endpoints (P1) over HTTP — the contract the Uppy island depends on. `presign` issues a
 * short-lived R2 PUT URL; the browser PUTs the bytes STRAIGHT to R2; `register` finalizes the row
 * (the REAL gate: magic-byte sniff + size + dedup). `upload` is the local-disk fallback that proxies
 * the file through Laravel. All three sit in the tenant admin group (auth + SetCurrentSite → RLS) and
 * re-gate media.upload. The presigned PUT + R2 CORS are browser/R2 concerns that Storage::fake cannot
 * presign → verified E2E on prod; here we cover the gate, the validation, and the register/upload
 * happy paths through the full middleware stack.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');

    // These cover the endpoints' gate / validation / finalize logic, not CSRF — the route keeps CSRF
    // in prod and the Uppy island sends X-CSRF-TOKEN from the <meta> tag. (This stack does not
    // auto-skip CSRF for authenticated test POSTs, so disable the CSRF middleware here.)
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/** A real 1×1 PNG (finfo sniffs image/png; no GD needed — mirrors the action tests). */
function endpointPng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );
}

/** A site-1 member carrying exactly the given permissions (membership = a role in the site team). */
function mediaMember(array $permissions): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', $permissions));

    return $user;
}

// ── gate: every endpoint re-checks media.upload (a viewer with only media.view is forbidden) ──
it('forbids presign without media.upload', function (): void {
    $this->actingAs(mediaMember(['media.view']))
        ->postJson('http://a.test/admin/media/presign', ['filename' => 'a.png', 'mime' => 'image/png', 'size' => 10])
        ->assertForbidden();
});

it('forbids register without media.upload', function (): void {
    $this->actingAs(mediaMember(['media.view']))
        ->postJson('http://a.test/admin/media/register', ['tempKey' => '_incoming/a/x.png', 'filename' => 'a.png'])
        ->assertForbidden();
});

it('forbids the local-fallback upload without media.upload', function (): void {
    $this->actingAs(mediaMember(['media.view']))
        ->post('http://a.test/admin/media/upload', ['file' => UploadedFile::fake()->createWithContent('a.png', endpointPng())])
        ->assertForbidden();
});

// ── presign: validation + advisory rejection (the signed-URL happy path is E2E — needs real R2) ──
it('rejects an unsupported declared mime on presign (422 with the sentence in the admin\'s language)', function (): void {
    $this->actingAs(mediaMember(['media.view', 'media.upload']))
        ->postJson('http://a.test/admin/media/presign', ['filename' => 'a.svg', 'mime' => 'image/svg+xml', 'size' => 10])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('media.err_mimes')]);
});

it('answers an unexpected failure with a plain sentence and keeps its own text out of the response', function (): void {
    // Storage not configured is the operator's problem: logged, never shown (it names .env and the disk).
    config(['media.disk' => 'r2', 'filesystems.disks.r2.driver' => 's3', 'filesystems.disks.r2.bucket' => null]);

    $response = $this->actingAs(mediaMember(['media.view', 'media.upload']))
        ->postJson('http://a.test/admin/media/presign', ['filename' => 'a.png', 'mime' => 'image/png', 'size' => 10])
        ->assertStatus(500)
        ->assertExactJson(['message' => __('media.err_unexpected')]);

    expect((string) $response->getContent())->not->toContain('.env')->not->toContain('configured');
});

it('validates the required fields on presign (422)', function (): void {
    // A non-empty object that omits every required key (an empty [] would encode as a JSON list).
    $this->actingAs(mediaMember(['media.view', 'media.upload']))
        ->postJson('http://a.test/admin/media/presign', ['probe' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filename', 'mime', 'size']);
});

// ── register: the REAL gate, over HTTP (the browser has already PUT to the temp key) ──
it('finalizes a staged upload over HTTP → 200 {ulid} + a site-stamped row', function (): void {
    $editor = mediaMember(['media.view', 'media.upload']);

    $tempKey = '_incoming/a/01http.png';
    Storage::disk('r2')->put($tempKey, endpointPng());
    Cache::put(PresignMediaUpload::ownershipKey($tempKey), 1, now()->addMinutes(5));

    $this->actingAs($editor)
        ->postJson('http://a.test/admin/media/register', ['tempKey' => $tempKey, 'filename' => 'Bảng Giá.png', 'width' => 1, 'height' => 1])
        ->assertOk()
        ->assertJsonStructure(['ulid']);

    $media = app(Tenancy::class)->enter(1, fn () => Media::query()->first());
    expect($media)->not->toBeNull()
        ->and($media->site_id)->toBe(1)
        ->and($media->mime)->toBe('image/png')
        ->and($media->width)->toBe(1)
        ->and($media->created_by)->toBe($editor->id);
    Storage::disk('r2')->assertExists($media->storage_key);
    Storage::disk('r2')->assertMissing($tempKey);
});

it('rejects a register replaying ANOTHER site\'s temp key (cross-tenant guard) over HTTP', function (): void {
    $editor = mediaMember(['media.view', 'media.upload']);

    $tempKey = '_incoming/b/01foreign.png';
    Storage::disk('r2')->put($tempKey, endpointPng());
    Cache::put(PresignMediaUpload::ownershipKey($tempKey), 2, now()->addMinutes(5)); // owned by site 2

    $this->actingAs($editor)
        ->postJson('http://a.test/admin/media/register', ['tempKey' => $tempKey, 'filename' => 'x.png'])
        ->assertStatus(422);

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
});

it('validates the required fields on register (422)', function (): void {
    $this->actingAs(mediaMember(['media.view', 'media.upload']))
        ->postJson('http://a.test/admin/media/register', ['probe' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tempKey', 'filename']);
});

// ── upload: local-disk fallback (dev) proxies the file through Laravel ──
it('ingests a multipart file via the local fallback → 200 {ulid} + a site-stamped row', function (): void {
    $editor = mediaMember(['media.view', 'media.upload']);

    $this->actingAs($editor)
        ->post('http://a.test/admin/media/upload', ['file' => UploadedFile::fake()->createWithContent('Bảng Giá.png', endpointPng())])
        ->assertOk()
        ->assertJsonStructure(['ulid']);

    $media = app(Tenancy::class)->enter(1, fn () => Media::query()->first());
    expect($media)->not->toBeNull()
        ->and($media->site_id)->toBe(1)
        ->and($media->mime)->toBe('image/png')
        ->and($media->created_by)->toBe($editor->id);
    Storage::disk('r2')->assertExists($media->storage_key);
});

// ── a re-upload of a file in the Trash: restoring is a media.delete right, not an upload one ──
/** A binned library master holding endpointPng()'s bytes, in site 1. */
function trashedEndpointMaster(): Media
{
    return app(Tenancy::class)->enter(1, function (): Media {
        $media = Media::query()->create([
            'collection' => 'library',
            'slug' => 'da-xoa',
            'hash' => hash('sha256', endpointPng()),
            'storage_key' => 'a/library/da-xoa.png',
            'disk' => 'r2',
            'original_filename' => 'da-xoa.png',
            'mime' => 'image/png',
            'size' => strlen(endpointPng()),
        ]);
        Storage::disk('r2')->put($media->storage_key, endpointPng());
        $media->delete();

        return $media;
    });
}

function isTrashedInSiteOne(Media $media): ?bool
{
    return app(Tenancy::class)->enter(1, fn (): ?bool => Media::query()->withTrashed()->whereKey($media->id)->first()?->trashed());
}

it('refuses a register that would take a trashed file out of the Trash for an uploader without media.delete', function (): void {
    $master = trashedEndpointMaster();
    $tempKey = '_incoming/a/01again.png';
    Storage::disk('r2')->put($tempKey, endpointPng());
    Cache::put(PresignMediaUpload::ownershipKey($tempKey), 1, now()->addMinutes(5));

    $this->actingAs(mediaMember(['media.view', 'media.upload']))
        ->postJson('http://a.test/admin/media/register', ['tempKey' => $tempKey, 'filename' => 'lai.png'])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('media.err_in_trash')]);

    expect(isTrashedInSiteOne($master))->toBeTrue();
});

it('restores a trashed file on register for an uploader holding media.delete', function (): void {
    $master = trashedEndpointMaster();
    $tempKey = '_incoming/a/01again.png';
    Storage::disk('r2')->put($tempKey, endpointPng());
    Cache::put(PresignMediaUpload::ownershipKey($tempKey), 1, now()->addMinutes(5));

    $this->actingAs(mediaMember(['media.view', 'media.upload', 'media.delete']))
        ->postJson('http://a.test/admin/media/register', ['tempKey' => $tempKey, 'filename' => 'lai.png'])
        ->assertOk()
        ->assertExactJson(['ulid' => $master->ulid]);

    expect(isTrashedInSiteOne($master))->toBeFalse();
});

it('refuses a local-fallback upload that would take a trashed file out of the Trash without media.delete', function (): void {
    $master = trashedEndpointMaster();

    $this->actingAs(mediaMember(['media.view', 'media.upload']))
        ->post('http://a.test/admin/media/upload', ['file' => UploadedFile::fake()->createWithContent('lai.png', endpointPng())], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('media.err_in_trash')]);

    expect(isTrashedInSiteOne($master))->toBeTrue();
});

it('restores a trashed file on a local-fallback upload for an uploader holding media.delete', function (): void {
    $master = trashedEndpointMaster();

    $this->actingAs(mediaMember(['media.view', 'media.upload', 'media.delete']))
        ->post('http://a.test/admin/media/upload', ['file' => UploadedFile::fake()->createWithContent('lai.png', endpointPng())], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertExactJson(['ulid' => $master->ulid]);

    expect(isTrashedInSiteOne($master))->toBeFalse();
});
