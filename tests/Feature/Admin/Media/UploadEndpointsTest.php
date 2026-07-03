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
it('rejects an unsupported declared mime on presign (422 with a message)', function (): void {
    $this->actingAs(mediaMember(['media.view', 'media.upload']))
        ->postJson('http://a.test/admin/media/presign', ['filename' => 'a.svg', 'mime' => 'image/svg+xml', 'size' => 10])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
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
