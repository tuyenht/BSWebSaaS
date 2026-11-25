<?php

use App\Modules\Media\Exceptions\MediaKeyRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\EnsureMediaFromKey;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Folders of old storage keys (`co-khi-an-vinh/library/…`) belong to the websites that used them:
 * the site-code migration records each folder with its owners — the websites whose keys use it, and
 * every website for the slug of its name, where the code before website codes keeps uploading until
 * the new code is live. Only an owner may write under such a folder, so no website can claim another
 * website's old folder. Two websites with the same name own one folder together: there a key is
 * accepted for one of them only.
 */

beforeEach(function (): void {
    seedTenancy();
    $this->reservedBefore = (int) DB::connection('pgsql_admin')->table('reserved_site_codes')->max('id');
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->table('reserved_site_codes')->where('id', '>', $this->reservedBefore)->delete();
});

/** The SQLSTATE a write fails with ('' when it succeeds). */
function legacyFolderState(Closure $write): string
{
    try {
        $write();
    } catch (QueryException $e) {
        return (string) ($e->errorInfo[0] ?? '');
    }

    return '';
}

function legacyRowFor(int $site, string $key): Media
{
    return app(Tenancy::class)->enter($site, fn () => Media::query()->create([
        'collection' => 'library', 'slug' => 'anh', 'hash' => hash('sha256', $key),
        'storage_key' => $key, 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1,
    ]));
}

function ownLegacyFolder(string $folder, int $site): void
{
    DB::connection('pgsql_admin')->table('reserved_site_codes')->insert(['code' => $folder, 'reason' => 'legacy_key', 'site_id' => $site]);
}

/** Runs the migration's freeze step as the privileged role, as the migration does. */
function runLegacyFreeze(): void
{
    $migration = require database_path('migrations/2097_01_01_000000_add_code_to_sites.php');
    $freeze = (new ReflectionMethod($migration, 'freezeLegacyFolders'))->getClosure($migration);

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        DB::transaction(fn () => $freeze());
    } finally {
        DB::setDefaultConnection($default);
    }
}

/** @return list<string> the folders recorded for $site */
function legacyFoldersOf(int $site): array
{
    return DB::connection('pgsql_admin')->table('reserved_site_codes')
        ->where('reason', 'legacy_key')->where('site_id', $site)->orderBy('code')->pluck('code')->all();
}

it('lets only the owner write under its old folder: another website can neither register nor insert a key there', function (): void {
    ownLegacyFolder('cu-a', 1);

    $register = fn () => app(Tenancy::class)->enter(2, fn () => app(EnsureMediaFromKey::class)->handle('cu-a/library/logo-ab12cd.png'));

    expect(legacyRowFor(1, 'cu-a/library/anh-ab12cd.png')->exists)->toBeTrue()
        ->and($register)->toThrow(MediaKeyRejectedException::class, 'old folder of another website')
        ->and(legacyFolderState(fn () => legacyRowFor(2, 'cu-a/library/khac-ab12cd.png')))->toBe('23514')
        ->and(app(Tenancy::class)->enter(2, fn () => Media::query()->count()))->toBe(0);
});

it('accepts a key for only one of two websites that share an old folder', function (): void {
    ownLegacyFolder('trung-ten', 1);
    ownLegacyFolder('trung-ten', 2);
    $key = 'trung-ten/library/logo-ab12cd.png';

    expect(legacyRowFor(1, $key)->exists)->toBeTrue()
        ->and(legacyFolderState(fn () => legacyRowFor(2, $key)))->toBe('23514')
        ->and(legacyRowFor(2, 'trung-ten/library/khac-ab12cd.png')->exists)->toBeTrue();
});

it('freezes the slug of every website\'s name for that website, trashed ones too, so uploads of the previous code still land', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('sites')->where('id', 1)->update(['name' => 'Cơ khí An Vinh']);
    $admin->table('sites')->where('id', 2)->update(['name' => 'Cơ khí An Vinh']);   // same name: one folder, two owners
    $trashed = (int) $admin->table('sites')->insertGetId([
        'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'Xưởng Cũ', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now(),
    ]);

    runLegacyFreeze();

    expect(legacyFoldersOf(1))->toBe(['co-khi-an-vinh'])
        ->and(legacyFoldersOf(2))->toBe(['co-khi-an-vinh'])
        ->and(legacyFoldersOf($trashed))->toBe(['xuong-cu'])
        // The previous code's layout: {slug(name)}/{collection}/{slug}-{hash}.{ext}
        ->and(legacyRowFor(1, 'co-khi-an-vinh/library/logo-ab12cd.png')->exists)->toBeTrue()
        ->and(legacyFolderState(fn () => legacyRowFor(2, 'co-khi-an-vinh/library/logo-ab12cd.png')))->toBe('23514');
});

it('freezes the first folder of existing keys for the website that holds them, never a website code', function (): void {
    ownLegacyFolder('cu-a', 1);
    legacyRowFor(1, 'cu-a/library/anh-ab12cd.png');
    legacyRowFor(1, siteCode(1).'/anh-ab12.png');
    legacyRowFor(2, siteCode(2).'/anh-ab12.png');
    DB::connection('pgsql_admin')->table('reserved_site_codes')->where('id', '>', $this->reservedBefore)->delete();

    runLegacyFreeze();

    expect(legacyFoldersOf(1))->toBe(['a', 'cu-a'])          // 'a' = slug of the name «A»
        ->and(legacyFoldersOf(2))->toBe(['b'])
        ->and(DB::table('reserved_site_codes')->whereIn('code', [siteCode(1), siteCode(2)])->exists())->toBeFalse()
        ->and(legacyFolderState(fn () => legacyRowFor(2, siteCode(1).'/khac-ab12.png')))->toBe('23514');
});

it('keeps a removed website\'s old folder frozen but writable by nobody', function (): void {
    $admin = DB::connection('pgsql_admin');
    $gone = fn (): int => (int) $admin->table('sites')->insertGetId([
        'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'G', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    [$first, $second] = [$gone(), $gone()];
    ownLegacyFolder('chung-cu', $first);
    ownLegacyFolder('chung-cu', $second);

    $admin->table('sites')->whereIn('id', [$first, $second])->delete();

    expect($admin->table('reserved_site_codes')->where('code', 'chung-cu')->get(['reason', 'site_id'])->map(fn ($row): array => (array) $row)->all())
        ->toBe([['reason' => 'legacy_key', 'site_id' => null]])
        ->and(legacyFolderState(fn () => legacyRowFor(1, 'chung-cu/library/anh-ab12cd.png')))->toBe('23514');
});

it('refuses another website\'s or a removed website\'s old folder before writing, so the caller\'s transaction goes on', function (): void {
    $admin = DB::connection('pgsql_admin');
    $gone = (int) $admin->table('sites')->insertGetId([
        'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'G', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    ownLegacyFolder('cu-a', 1);
    ownLegacyFolder('cu-mat', $gone);
    $admin->table('sites')->where('id', $gone)->delete();   // its folder stays frozen, without an owner

    $result = app(Tenancy::class)->enter(2, function (): array {
        $refused = [];
        foreach (['cu-a/library/logo-ab12cd.png', 'cu-mat/library/logo-ab12cd.png'] as $key) {
            try {
                app(EnsureMediaFromKey::class)->handle($key);
            } catch (MediaKeyRejectedException) {
                $refused[] = $key;
            }
        }

        // Nothing reached the database: the same transaction still takes writes.
        return [$refused, app(EnsureMediaFromKey::class)->handle(siteCode(2).'/logo-ab12.png')->exists];
    });

    expect($result)->toBe([['cu-a/library/logo-ab12cd.png', 'cu-mat/library/logo-ab12cd.png'], true]);
});

it('registers a key under the website\'s own old folder, a seeded folder and a shared one', function (): void {
    ownLegacyFolder('cu-a', 1);
    ownLegacyFolder('trung-ten', 1);
    ownLegacyFolder('trung-ten', 2);
    DB::connection('pgsql_admin')->table('reserved_site_codes')->insertOrIgnore(['code' => 'cwv', 'reason' => 'seed_folder']);

    $rows = app(Tenancy::class)->enter(1, fn (): array => array_map(
        fn (string $key): bool => app(EnsureMediaFromKey::class)->handle($key)->exists,
        ['cu-a/library/logo-ab12cd.png', 'cwv/library/logo-ab12cd.png', 'trung-ten/library/logo-ab12cd.png'],
    ));

    expect($rows)->toBe([true, true, true]);
});
