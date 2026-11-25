<?php

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * media:rekey-site moves one website's files filed before website codes to `{code}/{file}`: a dry
 * run counts, --apply copies the objects (the old ones stay) and, in one transaction, moves the keys
 * and rewrites every stored reference (a bare key, or an address ending with one). Another website is
 * never touched, and a second run finds nothing.
 */

const REKEY_HERO = 'a-vinh/hero/hero-home-7ca90eafe212.jpg';
const REKEY_LOGO = 'cu-rk/library/logo-ab12cd.png';
const REKEY_GONE = 'cu-rk/library/mat-ef34.png';
const REKEY_OTHER = 'a-vinh/hero/khac-1234.jpg';

beforeEach(function (): void {
    seedTenancy();
    Storage::fake(MediaKey::disk());
    DB::connection('pgsql_admin')->table('reserved_site_codes')->insert(['code' => 'cu-rk', 'reason' => 'legacy_key', 'site_id' => 1]);
});

/** A library row of $site at $key; its object is stored unless $stored is false. */
function rekeyMedia(int $site, string $key, bool $stored = true, ?string $hash = null): Media
{
    if ($stored) {
        Storage::disk(MediaKey::disk())->put($key, "bytes of {$key}");
    }

    return app(Tenancy::class)->enter($site, fn (): Media => Media::query()->create([
        'collection' => 'library', 'slug' => 'anh', 'hash' => $hash ?? hash('sha256', $key),
        'storage_key' => $key, 'disk' => 'r2', 'mime' => str_ends_with($key, '.png') ? 'image/png' : 'image/jpeg', 'size' => 10,
    ]));
}

/**
 * Site 1's content naming its files by key: a post's featured picture (and its history), a file
 * button in its body and the brand logo address. Site 2 holds a file and a post of its own.
 *
 * @return array{post: Entry, other: Entry}
 */
function rekeyContent(): array
{
    rekeyMedia(1, REKEY_HERO);
    rekeyMedia(1, REKEY_LOGO);
    rekeyMedia(1, REKEY_GONE, stored: false);
    rekeyMedia(2, REKEY_OTHER);

    $post = app(Tenancy::class)->enter(1, function (): Entry {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => REKEY_HERO, 'note' => 'cu-rk/library/khong-co-1234.png']]);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'draft', 'body' => ['blocks' => []]]);
        DB::table('entry_translations')->where('entry_id', $post->id)->update(['body' => json_encode(['blocks' => [
            ['type' => 'cta', 'props' => ['button_url_do' => ['k' => 'file', 'file' => REKEY_LOGO], 'style' => (object) []]],
        ]], JSON_UNESCAPED_SLASHES)]);
        EntryRevision::query()->create(['entry_id' => $post->id, 'locale' => 'vi', 'kind' => RevisionKind::Edit,
            'snapshot' => ['v' => 1, 'meta' => ['image_url' => REKEY_HERO], 'text' => 'ảnh '.REKEY_HERO.' trong câu']]);
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => [
            'logo' => 'https://files.test/media/'.REKEY_LOGO.'?w=64', 'name' => 'A',
        ]]);

        return $post;
    });
    $other = app(Tenancy::class)->enter(2, fn (): Entry => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => REKEY_HERO]]));

    return ['post' => $post, 'other' => $other];
}

/** @return array{0: int, 1: string} */
function rekeyRun(bool $apply = false, string $site = '1'): array
{
    $exit = Artisan::call('media:rekey-site', ['site' => $site, '--apply' => $apply]);

    return [$exit, Artisan::output()];
}

/** @return list<string> */
function rekeyKeys(int $site): array
{
    return app(Tenancy::class)->enter($site, fn (): array => Media::query()->withTrashed()->orderBy('id')->pluck('storage_key')->all());
}

it('counts in a dry run and writes nothing', function (): void {
    rekeyContent();

    [$exit, $output] = rekeyRun();

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Files to move: 3.')
        ->and($output)->toMatch('/entries\.meta\s*\|\s*1\s*\|\s*1\s*\|\s*1/')            // the hero; the note names no file
        ->and($output)->toMatch('/entry_revisions\.snapshot\s*\|\s*1\s*\|\s*1\s*\|/')   // the text only mentions the key
        ->and($output)->toMatch('/site_settings\.value\s*\|\s*1\s*\|\s*1\s*\|/')
        ->and($output)->toContain('unmatched in entries.meta: cu-rk/library/khong-co-1234.png')
        ->and($output)->toContain('Nothing was written.')
        ->and(rekeyKeys(1))->toBe([REKEY_HERO, REKEY_LOGO, REKEY_GONE])
        ->and(Storage::disk(MediaKey::disk())->allFiles(siteCode(1)))->toBe([]);
});

it('copies the objects, moves the keys and rewrites every reference of the website only', function (): void {
    ['post' => $post, 'other' => $other] = rekeyContent();
    $code = siteCode(1);
    $hero = "{$code}/hero-home-7ca90eafe212.jpg";
    $logo = "{$code}/logo-ab12cd.png";

    [$exit, $output] = rekeyRun(apply: true);

    $disk = Storage::disk(MediaKey::disk());
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Objects copied: 2, already there: 0, missing from storage: 1.')
        ->and($output)->toContain('missing: '.REKEY_GONE)
        ->and($output)->toContain('Keys moved: 3 of 3.')
        ->and(rekeyKeys(1))->toBe([$hero, $logo, "{$code}/mat-ef34.png"])
        ->and($disk->get($hero))->toBe('bytes of '.REKEY_HERO)
        ->and($disk->exists(REKEY_HERO))->toBeTrue()                       // the old object stays
        ->and(rekeyKeys(2))->toBe([REKEY_OTHER]);

    app(Tenancy::class)->enter(1, function () use ($post, $hero, $logo): void {
        $fresh = Entry::query()->findOrFail($post->id);
        expect($fresh->meta)->toEqual(['image_url' => $hero, 'note' => 'cu-rk/library/khong-co-1234.png'])
            ->and(json_decode((string) DB::table('entry_translations')->where('entry_id', $post->id)->value('body'), true)['blocks'][0]['props'])
            ->toEqual(['style' => [], 'button_url_do' => ['k' => 'file', 'file' => $logo]])
            ->and(DB::table('entry_translations')->where('entry_id', $post->id)->value('body'))->toContain('"style": {}')
            ->and(EntryRevision::query()->where('entry_id', $post->id)->sole()->snapshot)
            ->toEqual(['v' => 1, 'meta' => ['image_url' => $hero], 'text' => 'ảnh '.REKEY_HERO.' trong câu'])
            ->and(SiteSetting::query()->where('key', 'brand')->sole()->value)->toEqual(['logo' => "https://files.test/media/{$logo}?w=64", 'name' => 'A'])
            ->and(Mediable::query()->where('mediable_type', 'entry')->where('mediable_id', $post->id)->where('collection', 'cover')->pluck('media_id')->all())
            ->toBe([Media::query()->where('storage_key', $hero)->value('id')]);
    });
    expect(app(Tenancy::class)->enter(2, fn () => Entry::query()->findOrFail($other->id)->meta))->toBe(['image_url' => REKEY_HERO]);

    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)->and($output)->toContain('Files to move: 0.')->and($output)->toContain('Keys moved: 0 of 0.');
});

it('grows the hash when the file name is already taken under the code, whatever the extension', function (): void {
    $code = siteCode(1);
    rekeyMedia(1, "{$code}/logo-ab12cd.webp");
    rekeyMedia(1, REKEY_LOGO, hash: 'ab12cd'.str_repeat('9', 58));
    rekeyMedia(1, 'a-vinh/library/logo-ab12cd.png');   // the same name again, from another old folder

    [$exit, $output] = rekeyRun(apply: true);

    // The second one's hash does not start with its suffix: the suffix grows along suffix + hash.
    $along = 'ab12cd'.hash('sha256', 'a-vinh/library/logo-ab12cd.png');
    $third = substr($along, 0, 7) === 'ab12cd9' ? substr($along, 0, 8) : substr($along, 0, 7);
    expect($exit)->toBe(0, $output)
        ->and(rekeyKeys(1))->toBe(["{$code}/logo-ab12cd.webp", "{$code}/logo-ab12cd9.png", "{$code}/logo-{$third}.png"]);
});

it('stops before writing anything when another file already sits at a new key', function (): void {
    rekeyContent();
    Storage::disk(MediaKey::disk())->put(siteCode(1).'/logo-ab12cd.png', 'a different, longer file');

    [$exit, $output] = rekeyRun(apply: true);

    expect($exit)->toBe(1)
        ->and($output)->toContain('A different file already sits at')
        ->and(rekeyKeys(1))->toBe([REKEY_HERO, REKEY_LOGO, REKEY_GONE]);
});

it('takes the website by its code too, and refuses an unknown one', function (): void {
    rekeyMedia(1, REKEY_LOGO);

    expect(rekeyRun(site: siteCode(1))[1])->toContain('Files to move: 1.')
        ->and(rekeyRun(site: 'zzzzzz'))->sequence(
            fn ($exit) => $exit->toBe(1),
            fn ($output) => $output->toContain('No website with that id or code.'),
        );
});

it('refuses a single-site install, whose files stay under their collections', function (): void {
    config(['media.tenant_prefix' => false]);

    [$exit, $output] = rekeyRun(apply: true);

    expect($exit)->toBe(1)->and($output)->toContain('media.tenant_prefix is off');
});
