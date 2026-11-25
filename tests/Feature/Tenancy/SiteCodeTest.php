<?php

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteDirectory;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Exceptions\SiteCodeIsPermanentException;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\StorefrontCards;

/*
 * Every website has a permanent code, the first folder of its media keys (`k3m9xq/…`): six random
 * characters without i, l, o or u, drawn by the database when the website is created, unique over
 * every website (the trashed ones too), never changed, and never handed to another website — not
 * even after the website is removed. Folders of old storage keys (`an-vinh`) are never issued.
 */

beforeEach(fn () => seedTenancy());

/** The SQLSTATE a write fails with ('' when it succeeds). */
function siteCodeState(Closure $write): string
{
    try {
        $write();
    } catch (QueryException $e) {
        return (string) ($e->errorInfo[0] ?? '');
    }

    return '';
}

/** @param  array<string, mixed>  $overrides */
function insertSite(array $overrides = []): int
{
    return (int) DB::connection('pgsql_admin')->table('sites')->insertGetId(array_merge([
        'ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'S', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides));
}

it('gives every new website a random six-character code, a new one each time', function (): void {
    $codes = array_map(fn (): string => siteCode(insertSite()), range(1, 20));

    expect($codes)->each->toMatch('/^[0-9a-hjkmnp-tv-z]{6}$/')
        ->and(array_unique($codes))->toHaveCount(20);
});

it('gives a website made by provisioning its code, not one taken from its name or address', function (): void {
    $site = StorefrontCards::site('Xưởng Mẫu', 'mau.platform.test');

    expect(siteCode($site))->toMatch('/^[0-9a-hjkmnp-tv-z]{6}$/')
        ->and(siteCode($site))->not->toContain('mau');
});

it('draws again when the code it drew is reserved', function (): void {
    $admin = DB::connection('pgsql_admin');
    $draw = function () use ($admin): string {
        $admin->statement('SELECT setseed(0.42)');     // the same draws each time

        return (string) $admin->selectOne('SELECT issue_site_code() AS code')->code;
    };

    [$first, $again] = $admin->transaction(function () use ($admin, $draw): array {
        $first = $draw();
        $admin->table('reserved_site_codes')->insert(['code' => $first, 'reason' => 'legacy_key']);

        return [$first, $draw()];
    });
    $admin->table('reserved_site_codes')->where('code', $first)->delete();

    expect($again)->toMatch('/^[0-9a-hjkmnp-tv-z]{6}$/')->not->toBe($first);
});

it('refuses a code that is not a DNS label', function (string $code): void {
    expect(siteCodeState(fn () => insertSite(['code' => $code])))->toBeIn(['23514', '22001']);
})->with(['upper case' => 'AAAAAA', 'letter i' => 'aaaaai', 'letter l' => 'aaaaal', 'letter o' => 'aaaaao', 'letter u' => 'aaaaau', 'dash' => 'aa-aaa', 'too short' => 'aaaaa', 'too long' => 'aaaaaaa']);

it('keeps codes unique over every website, the trashed ones too', function (): void {
    $trashed = insertSite(['code' => 'c7th3x', 'deleted_at' => now()]);

    expect(siteCode($trashed))->toBe('c7th3x')
        ->and(siteCodeState(fn () => insertSite(['code' => 'c7th3x'])))->toBe('23505');
});

it('never lets anyone change a code: the application role, the privileged role, or the model', function (): void {
    $app = siteCodeState(fn () => DB::table('sites')->where('id', 1)->update(['code' => 'kkkkkk']));
    $admin = siteCodeState(fn () => DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['code' => 'kkkkkk']));
    $site = Site::query()->findOrFail(1);
    $site->code = 'kkkkkk';

    expect($app)->toBe('23514')
        ->and($admin)->toBe('23514')
        ->and(fn () => $site->save())->toThrow(SiteCodeIsPermanentException::class)
        ->and(siteCode(1))->toBe('aaaaaa');
});

it('leaves the code alone when other columns of the website change', function (): void {
    DB::table('sites')->where('id', 1)->update(['name' => 'Tên mới']);

    expect(siteCode(1))->toBe('aaaaaa');
});

it('reserves a removed website\'s code for good', function (): void {
    $id = insertSite(['code' => 'x0ahan']);
    DB::connection('pgsql_admin')->table('sites')->where('id', $id)->delete();

    expect(DB::table('reserved_site_codes')->where('code', 'x0ahan')->value('reason'))->toBe('deleted_site')
        ->and(siteCodeState(fn () => insertSite(['code' => 'x0ahan'])))->toBe('23514');
});

it('never issues a folder of old storage keys as a code', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('reserved_site_codes')->insertOrIgnore(['code' => 'c0k4ac', 'reason' => 'legacy_key']);

    try {
        expect(siteCodeState(fn () => insertSite(['code' => 'c0k4ac'])))->toBe('23514')
            ->and(DB::table('reserved_site_codes')->where('code', 'an-vinh')->value('reason'))->toBe('seed_folder');
    } finally {
        $admin->table('reserved_site_codes')->where('code', 'c0k4ac')->delete();
    }
});

it('keeps the reserved list read-only for the application', function (): void {
    expect(siteCodeState(fn () => DB::table('reserved_site_codes')->insert(['code' => 'moi', 'reason' => 'legacy_key'])))->toBe('42501')
        ->and(siteCodeState(fn () => DB::table('reserved_site_codes')->where('code', 'an-vinh')->delete()))->toBe('42501')
        ->and(DB::table('reserved_site_codes')->where('code', 'an-vinh')->exists())->toBeTrue();
});

it('reads the current website\'s code and tells another website\'s code apart', function (): void {
    $directory = app(SiteDirectory::class);

    [$own, $mine, $theirs, $free] = app(Tenancy::class)->enter(1, fn (): array => [
        $directory->codeOfCurrent(app(CurrentSite::class)),
        $directory->isCodeOfAnotherSite(app(CurrentSite::class), 'aaaaaa'),
        $directory->isCodeOfAnotherSite(app(CurrentSite::class), 'bbbbbb'),
        $directory->isCodeOfAnotherSite(app(CurrentSite::class), 'zzzzzz'),
    ]);

    expect($own)->toBe('aaaaaa')->and($mine)->toBeFalse()->and($theirs)->toBeTrue()->and($free)->toBeFalse();
});

it('refuses to roll the migration back once media is filed under a website code', function (): void {
    app(Tenancy::class)->enter(1, fn () => DB::table('media')->insert([
        'site_id' => 1, 'ulid' => (string) Str::ulid(), 'collection' => 'library', 'hash' => str_repeat('e', 64),
        'storage_key' => 'aaaaaa/anh-eeee.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $migration = require database_path('migrations/2097_01_01_000000_add_code_to_sites.php');

    // As the privileged role, as migrations run: it sees every website's media rows.
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'filed under website codes');
    } finally {
        DB::setDefaultConnection($default);
    }
    expect(DB::connection('pgsql_admin')->getSchemaBuilder()->hasColumn('sites', 'code'))->toBeTrue();
});
