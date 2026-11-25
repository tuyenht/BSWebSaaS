<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The migration that closes the media folders the site-code migration left open. On a multi-site
 * install a seeded folder or a collection becomes the old folder of the websites holding rows under
 * it (or of none), so no other website can write a new key there. On every install, an owner row of
 * an old folder its website holds no file under is released, the folder staying frozen. down()
 * reopens the seeded folders and the collections.
 */

beforeEach(function (): void {
    seedTenancy();
    $this->reservedBefore = DB::connection('pgsql_admin')->table('reserved_site_codes')->get(['code', 'reason', 'site_id'])
        ->map(fn (object $row): array => (array) $row)->all();

    $admin = DB::connection('pgsql_admin');
    $admin->table('reserved_site_codes')->insert([
        ['code' => 'mau-sd', 'reason' => 'seed_folder', 'site_id' => null],
        ['code' => 'mau-trong', 'reason' => 'seed_folder', 'site_id' => null],
        ['code' => 'mau-bst', 'reason' => 'collection', 'site_id' => null],
        ['code' => 'ten-cu', 'reason' => 'legacy_key', 'site_id' => 1],
        ['code' => 'ten-cu', 'reason' => 'legacy_key', 'site_id' => 2],
        ['code' => 'ten-rong', 'reason' => 'legacy_key', 'site_id' => 1],
    ]);
    closeFoldersMedia(1, 'mau-sd/hero/a-1234.jpg');
    closeFoldersMedia(2, 'mau-bst/b-1234.png', 'mau-bst');
    closeFoldersMedia(2, 'ten-cu/library/c-5678.png');
});

// The reserved list is platform-wide and outlives truncation: put back what the test found.
afterEach(function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('reserved_site_codes')->delete();
    $admin->table('reserved_site_codes')->insert($this->reservedBefore);
});

function closeFoldersMedia(int $site, string $key, string $collection = 'library'): void
{
    DB::connection('pgsql_admin')->table('media')->insert([
        'site_id' => $site, 'ulid' => (string) Str::ulid(), 'collection' => $collection, 'hash' => hash('sha256', $key),
        'storage_key' => $key, 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function closeFoldersRun(string $method): void
{
    $migration = require database_path('migrations/2098_01_01_000000_close_open_media_folders.php');
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->{$method}();
    } finally {
        DB::setDefaultConnection($default);
    }
}

/** @return list<string> "reason:site" rows of $code */
function closeFoldersRows(string $code): array
{
    return DB::connection('pgsql_admin')->table('reserved_site_codes')->where('code', $code)->orderBy('site_id')->get(['reason', 'site_id'])
        ->map(fn (object $row): string => $row->reason.':'.($row->site_id ?? '-'))->all();
}

function closeFoldersWriteState(int $site, string $key, string $collection = 'library'): string
{
    try {
        closeFoldersMedia($site, $key, $collection);
    } catch (QueryException $e) {
        return (string) ($e->errorInfo[0] ?? '');
    }

    return '';
}

it('closes the seeded folders and collections on a multi-site install, keeping the rows under them', function (): void {
    closeFoldersRun('up');

    expect(closeFoldersRows('mau-sd'))->toBe(['legacy_key:1'])
        ->and(closeFoldersRows('mau-trong'))->toBe(['legacy_key:-'])
        ->and(closeFoldersRows('mau-bst'))->toBe(['legacy_key:2'])
        ->and(closeFoldersWriteState(2, 'mau-sd/hero/moi-1234.jpg'))->toBe('23514')
        ->and(closeFoldersWriteState(1, 'mau-trong/moi-1234.jpg'))->toBe('23514')
        ->and(closeFoldersWriteState(1, 'mau-bst/moi-1234.png', 'mau-bst'))->toBe('23514')
        ->and(closeFoldersWriteState(1, 'mau-sd/hero/van-1234.jpg'))->toBe('');   // its owner keeps its old folder
});

it('releases an owner row of a folder its website holds no file under, the folder staying frozen', function (): void {
    closeFoldersRun('up');

    expect(closeFoldersRows('ten-cu'))->toBe(['legacy_key:2'])
        ->and(closeFoldersRows('ten-rong'))->toBe(['legacy_key:-'])
        ->and(closeFoldersWriteState(1, 'ten-rong/moi-1234.png'))->toBe('23514')
        ->and(closeFoldersWriteState(1, 'ten-cu/library/moi-1234.png'))->toBe('23514');
});

it('keeps the seeded folders and collections open on a single-site install, and still releases unused owners', function (): void {
    config(['media.tenant_prefix' => false]);
    try {
        closeFoldersRun('up');
    } finally {
        config(['media.tenant_prefix' => true]);
    }

    expect(closeFoldersRows('mau-trong'))->toBe(['seed_folder:-'])
        ->and(closeFoldersRows('mau-bst'))->toBe(['collection:-'])
        ->and(closeFoldersRows('ten-rong'))->toBe(['legacy_key:-']);
});

it('changes nothing when run again, and down() reopens the seeded folders and collections', function (): void {
    closeFoldersRun('up');
    $once = DB::connection('pgsql_admin')->table('reserved_site_codes')->orderBy('code')->orderBy('site_id')->get(['code', 'reason', 'site_id'])->all();
    closeFoldersRun('up');
    expect(DB::connection('pgsql_admin')->table('reserved_site_codes')->orderBy('code')->orderBy('site_id')->get(['code', 'reason', 'site_id'])->all())->toEqual($once);

    closeFoldersRun('down');

    expect(closeFoldersRows('library'))->toBe(['collection:-'])
        ->and(closeFoldersRows('cover'))->toBe(['collection:-'])
        ->and(closeFoldersRows('an-vinh'))->toBe(['seed_folder:-'])
        ->and(closeFoldersRows('cwv'))->toBe(['seed_folder:-']);
});
