<?php

use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The database itself refuses a media row whose storage key sits under ANOTHER website's code, even
 * if application code got the key wrong. The rule runs only when a key is written (insert, or an
 * update that changes the key or the site), so rows that predate site codes keep working until they
 * are re-keyed. A key must be a clean relative path (storage rewrites any other one, possibly into
 * another site's folder), and a legacy key may not be held by two websites.
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

it('accepts a key under the site\'s own code', function (): void {
    $media = mediaRowFor(1, siteCode(1).'/anh-abcd.png');

    expect($media->exists)->toBeTrue();
});

it('refuses a key under another site\'s code, on insert and on update', function (): void {
    $insert = fn () => mediaRowFor(1, siteCode(2).'/anh-abcd.png');
    $own = mediaRowFor(1, siteCode(1).'/anh-abcd.png');
    $update = fn () => app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($own->id)->update(['storage_key' => siteCode(2).'/anh-abcd.png']));

    expect(prefixRuleState($insert))->toBe('23514')
        ->and(prefixRuleState($update))->toBe('23514');
});

it('refuses a key that is not a clean relative path, whatever its first segment', function (string $key): void {
    expect(prefixRuleState(fn () => mediaRowFor(1, withSiteCodes($key))))->toBe('23514')
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
})->with(uncleanMediaKeys());

it('refuses an unclean key on the privileged connection and on update too', function (): void {
    $own = mediaRowFor(1, siteCode(1).'/anh-abcd.png');

    $insert = fn () => DB::connection('pgsql_admin')->table('media')->insert([
        'site_id' => 1, 'ulid' => (string) Str::ulid(), 'collection' => 'library', 'hash' => str_repeat('c', 64),
        'storage_key' => './'.siteCode(2).'/anh-abcd.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $update = fn () => app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($own->id)->update(['storage_key' => siteCode(2).'\\anh-abcd.png']));

    expect(prefixRuleState($insert))->toBe('23514')
        ->and(prefixRuleState($update))->toBe('23514');
});

it('refuses a key whose first segment looks like a website code but is not the site\'s own', function (): void {
    $alphabet = '0123456789abcdefghjkmnpqrstvwxyz';
    do {
        $unissued = '';
        for ($i = 0; $i < 8; $i++) {
            $unissued .= $alphabet[random_int(0, 31)];
        }
    } while (DB::connection('pgsql_admin')->table('sites')->where('code', $unissued)->exists());

    // A legacy name-slug key with the code shape would sit in the namespace of a code issued later.
    expect(prefixRuleState(fn () => mediaRowFor(1, "{$unissued}/library/anh-abcd.png")))->toBe('23514');
});

it('refuses a legacy key that another website already holds', function (): void {
    $key = 'a-vinh/library/anh-aaaaaaaaaaaa.jpg';
    mediaRowFor(1, $key);

    expect(prefixRuleState(fn () => mediaRowFor(2, $key)))->toBe('23514')
        ->and(app(Tenancy::class)->enter(2, fn () => Media::query()->count()))->toBe(0);
});

it('keeps the legacy-key rule within one site: same-site writes still work', function (): void {
    $legacy = mediaRowFor(1, 'a-vinh/library/anh-aaaaaaaaaaaa.jpg');

    $again = prefixRuleState(fn () => mediaRowFor(1, 'a-vinh/library/anh-aaaaaaaaaaaa.jpg'));
    $rekeyed = app(Tenancy::class)->enter(1, function () use ($legacy): string {
        Media::query()->whereKey($legacy->id)->update(['storage_key' => 'a-vinh/library/anh-bbbbbbbbbbbb.jpg']);

        return (string) Media::query()->whereKey($legacy->id)->value('storage_key');
    });
    $other = mediaRowFor(2, 'b-vinh/library/anh-aaaaaaaaaaaa.jpg');

    expect($again)->toBe('23505')            // the site's own unique key, not the cross-site rule
        ->and($rekeyed)->toBe('a-vinh/library/anh-bbbbbbbbbbbb.jpg')
        ->and($other->exists)->toBeTrue();
});

it('also refuses the foreign key on the privileged connection', function (): void {
    $insert = fn () => DB::connection('pgsql_admin')->table('media')->insert([
        'site_id' => 1, 'ulid' => (string) Str::ulid(), 'collection' => 'library', 'hash' => str_repeat('d', 64),
        'storage_key' => siteCode(2).'/anh-abcd.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(prefixRuleState($insert))->toBe('23514');
});

it('leaves a legacy-key row alone when an update does not change its key', function (): void {
    $legacy = mediaRowFor(1, 'a/library/anh-aaaaaaaaaaaa.png');

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
    $own = mediaRowFor(1, siteCode(1).'/anh-abcd.png');

    $move = fn () => DB::connection('pgsql_admin')->table('media')->where('id', $own->id)->update(['site_id' => 2]);

    expect(prefixRuleState($move))->toBe('23514');
});
