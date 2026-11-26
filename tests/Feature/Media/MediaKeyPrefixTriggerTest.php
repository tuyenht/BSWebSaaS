<?php

use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Media\Support\MediaKeyPrefix;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The database itself refuses a media row whose storage key is not under its own website's code,
 * even when application code got the key wrong: a key under another website's code, a key that is
 * not a clean relative path (storage rewrites those, possibly into another website's folder), and a
 * new key in the old layout. Old keys keep working only under the folders frozen when website codes
 * arrived, and never for two websites at once. The rule runs only when a key is written.
 */

beforeEach(fn () => seedTenancy());

/** @param  array<string, mixed>  $overrides */
function mediaRowFor(int $site, string $key, array $overrides = []): Media
{
    return app(Tenancy::class)->enter($site, fn () => Media::query()->create(array_merge([
        'collection' => 'library', 'slug' => 'anh', 'hash' => hash('sha256', $key),
        'storage_key' => $key, 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1,
    ], $overrides)));
}

/** The SQLSTATE a write fails with ('' when it succeeds). */
function prefixRuleState(Closure $write): string
{
    try {
        $write();
    } catch (QueryException $e) {
        return (string) ($e->errorInfo[0] ?? '');
    }

    return '';
}

/** A row written on the privileged connection (RLS bypassed), as a migration or an operator would. */
function adminMediaRow(int $site, string $key): void
{
    DB::connection('pgsql_admin')->table('media')->insert([
        'site_id' => $site, 'ulid' => (string) Str::ulid(), 'collection' => 'library', 'hash' => hash('sha256', $key.$site),
        'storage_key' => $key, 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('accepts a key under the site\'s own code, with or without year and month folders', function (): void {
    expect(mediaRowFor(1, withSiteCodes('{own}/anh-abcd.png'))->exists)->toBeTrue()
        ->and(mediaRowFor(1, withSiteCodes('{own}/2026/04/anh-abcd.png'))->exists)->toBeTrue();
});

it('refuses a key under another site\'s code, on insert and on update', function (): void {
    $insert = fn () => mediaRowFor(1, withSiteCodes('{other}/anh-abcd.png'));
    $own = mediaRowFor(1, withSiteCodes('{own}/anh-abcd.png'));
    $update = fn () => app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($own->id)->update(['storage_key' => withSiteCodes('{other}/anh-abcd.png')]));

    expect(prefixRuleState($insert))->toBe('23514')
        ->and(prefixRuleState($update))->toBe('23514');
});

it('refuses a key under a removed website\'s code', function (): void {
    $gone = (int) DB::connection('pgsql_admin')->table('sites')->insertGetId([
        'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'code' => 'd4g9ne', 'name' => 'G', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('pgsql_admin')->table('sites')->where('id', $gone)->delete();

    expect(prefixRuleState(fn () => mediaRowFor(1, 'd4g9ne/anh-abcd.png')))->toBe('23514');
});

it('refuses a key under a code no website holds yet, so it can never land in a later website\'s folder', function (): void {
    expect(prefixRuleState(fn () => mediaRowFor(1, 'p3nd1n/anh-abcd.png')))->toBe('23514')
        ->and(prefixRuleState(fn () => mediaRowFor(1, 'p3nd1n/anh-abcd.png', ['collection' => 'p3nd1n'])))->toBe('23514');
});

it('refuses a new key in the old layout under a folder that was never frozen', function (): void {
    expect(prefixRuleState(fn () => mediaRowFor(1, 'co-khi-moi/library/anh-abcd.png')))->toBe('23514')
        ->and(prefixRuleState(fn () => adminMediaRow(1, 'chua-ai-dung/anh-abcd.png')))->toBe('23514')
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
});

it('refuses a key that is not a clean relative path, whatever its first segment', function (string $key): void {
    expect(prefixRuleState(fn () => mediaRowFor(1, withSiteCodes($key))))->toBe('23514')
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
})->with(uncleanMediaKeys());

it('refuses an unclean or foreign key on the privileged connection too', function (): void {
    expect(prefixRuleState(fn () => adminMediaRow(1, withSiteCodes('./{other}/anh-abcd.png'))))->toBe('23514')
        ->and(prefixRuleState(fn () => adminMediaRow(1, withSiteCodes('{other}/anh-abcd.png'))))->toBe('23514')
        ->and(prefixRuleState(fn () => adminMediaRow(1, '_private/leads/x.pdf')))->toBe('23514');
});

it('keeps the platform\'s sys folder out of every website, the single-site collection too', function (): void {
    expect(prefixRuleState(fn () => mediaRowFor(1, 'sys/logo-ab12.png')))->toBe('23514')
        ->and(prefixRuleState(fn () => mediaRowFor(1, 'sys/logo-ab12.png', ['collection' => 'sys'])))->toBe('23514')
        ->and(prefixRuleState(fn () => adminMediaRow(1, 'sys/logo-ab12.png')))->toBe('23514')
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
});

it('reserves in the database the same platform folder and collections the application names', function (): void {
    $reserved = fn (string $reason): array => DB::connection('pgsql_admin')->table('reserved_site_codes')->where('reason', $reason)->orderBy('id')->pluck('code')->all();
    $frozen = DB::connection('pgsql_admin')->table('reserved_site_codes')->whereIn('code', MediaKey::COLLECTIONS)->distinct()->orderBy('code')->pluck('code')->all();
    $collections = MediaKey::COLLECTIONS;
    sort($collections);

    // A multi-site install: the collections stay reserved (never a website's code), closed to writes.
    expect($reserved('platform'))->toBe([MediaKey::SYSTEM_SEGMENT])
        ->and($reserved('collection'))->toBe([])
        ->and($frozen)->toBe($collections)
        ->and(MediaKey::isReservedFirstSegment('sys'))->toBeTrue()
        ->and(MediaKey::isReservedFirstSegment('_incoming'))->toBeTrue()
        ->and(MediaKey::isReservedFirstSegment('_anything'))->toBeTrue()
        ->and(MediaKey::isReservedFirstSegment(siteCode(1)))->toBeFalse()
        ->and(MediaKey::isReservedFirstSegment('library'))->toBeFalse();
});

it('refuses a single-site collection that is not one of the fixed collections before writing anything', function (string $collection): void {
    config(['media.tenant_prefix' => false]);

    app(Tenancy::class)->enter(1, fn () => app(MediaKeyPrefix::class)->forNewObject($collection));
})->with(['sys', '_private', 'hero', 'p3nd1n'])->throws(MediaKeyRejectedException::class);

it('gives a single-site upload its collection as the first folder', function (): void {
    config(['media.tenant_prefix' => false]);

    expect(app(Tenancy::class)->enter(1, fn () => app(MediaKeyPrefix::class)->forNewObject('cover')))->toBe('cover/');
});

it('keeps old keys working under a seeded folder, and single-site keys under a fixed collection', function (): void {
    $seeded = mediaRowFor(1, 'a-vinh/hero/hero-home-7ca90eafe212.jpg');
    [$single, $cover, $wrongCollection, $notFixed] = inSingleSiteMode(fn (): array => [
        mediaRowFor(1, 'library/logo-ab12.png'),
        mediaRowFor(1, 'cover/banner-ab12.png', ['collection' => 'cover']),
        prefixRuleState(fn () => mediaRowFor(1, 'cover/khac-ab12.png')),            // collection library
        prefixRuleState(fn () => mediaRowFor(1, 'hero/banner-ab12.png', ['collection' => 'hero'])),   // not a fixed collection
    ]);

    expect($seeded->exists)->toBeTrue()->and($single->exists)->toBeTrue()->and($cover->exists)->toBeTrue()
        ->and($wrongCollection)->toBe('23514')
        ->and($notFixed)->toBe('23514');
});

it('refuses a single-site key that another website already holds', function (): void {
    [$taken, $free] = inSingleSiteMode(function (): array {
        mediaRowFor(1, 'library/logo-ab12.png');

        return [prefixRuleState(fn () => mediaRowFor(2, 'library/logo-ab12.png')), mediaRowFor(2, 'library/logo-cd34.png')->exists];
    });

    expect($taken)->toBe('23514')->and($free)->toBeTrue();
});

it('refuses a key under a fixed collection on a multi-site install, where every upload goes under its code', function (): void {
    expect(prefixRuleState(fn () => mediaRowFor(1, 'library/logo-ab12.png')))->toBe('23514')
        ->and(prefixRuleState(fn () => mediaRowFor(1, 'cover/banner-ab12.png', ['collection' => 'cover'])))->toBe('23514')
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
});

it('refuses an old key that another website already holds', function (): void {
    $key = 'a-vinh/content/about-doi-ngu-a7b78a0a6ff7.jpg';
    mediaRowFor(1, $key);

    expect(prefixRuleState(fn () => mediaRowFor(2, $key)))->toBe('23514')
        ->and(app(Tenancy::class)->enter(2, fn () => Media::query()->count()))->toBe(0);
});

it('leaves a row alone when an update does not change its key', function (): void {
    $legacy = mediaRowFor(1, 'a-vinh/brand/logo-e76d7cb1dfe4.png');

    $renamed = app(Tenancy::class)->enter(1, function () use ($legacy): string {
        $row = Media::query()->findOrFail($legacy->id);
        $row->original_filename = 'tên mới.png';
        $row->save();
        $row->delete();    // to the trash and back: neither writes the key
        $row->restore();

        return (string) Media::query()->whereKey($legacy->id)->value('original_filename');
    });

    expect($renamed)->toBe('tên mới.png');
});

it('refuses moving a row to another site when its key keeps the old site\'s code', function (): void {
    $own = mediaRowFor(1, withSiteCodes('{own}/anh-abcd.png'));

    $move = fn () => DB::connection('pgsql_admin')->table('media')->where('id', $own->id)->update(['site_id' => 2]);

    expect(prefixRuleState($move))->toBe('23514');
});

it('runs the key guard as a role that sees every website\'s rows, which its lookup across websites needs', function (): void {
    // The guard refuses a shared-folder key outright when its owner lacks this; here the owner has it.
    $owner = DB::connection('pgsql_admin')->selectOne(
        'SELECT r.rolname, (r.rolsuper OR r.rolbypassrls) AS sees_all, p.prosecdef FROM pg_proc p JOIN pg_roles r ON r.oid = p.proowner '.
        "WHERE p.oid = 'public.media_storage_key_prefix_guard()'::regprocedure"
    );

    expect($owner)->not->toBeNull()
        ->and($owner->sees_all)->toBeTrue()
        ->and($owner->prosecdef)->toBeTrue()
        ->and($owner->rolname)->not->toBe('app_user');
});
