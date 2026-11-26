<?php

use App\Modules\Admin\Console\RekeySiteMedia;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Support\EntryCoverUsage;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Services\EnsureMediaFromKey;
use App\Modules\Media\Services\SiteMediaRekey;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Media\Support\MediaKeyRewriter;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * media:rekey-site moves one website's files filed before website codes to `{code}/{file}`: a dry
 * run counts, --apply copies the objects (the old ones stay), moves the keys a chunk per transaction
 * with the references naming them (a bare key, or an address on the media host ending with one),
 * then repairs any reference to an old key of the website. Another website is never touched, and a
 * second run finds nothing, or repairs an old key written back meanwhile. An address on another host
 * ending with a moved key is reported, never rewritten; a key that cannot be moved is skipped and
 * listed; a chunk that lost a lock conflict is retried; a permanent delete removes the old object too.
 * A seed file a live place names with no row of the website is adopted (copied under the code with a
 * row, as the seeder does), never one only history names or one missing from storage.
 */

const REKEY_HERO = 'a-vinh/hero/hero-home-7ca90eafe212.jpg';
const REKEY_LOGO = 'cu-rk/library/logo-ab12cd.png';
const REKEY_GONE = 'cu-rk/library/mat-ef34.png';
const REKEY_OTHER = 'a-vinh/hero/khac-1234.jpg';

beforeEach(function (): void {
    seedTenancy();
    Storage::fake(MediaKey::disk());
    config(['media.cloudflare.base' => 'https://files.test/media']);
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
            'logo' => 'https://files.test/media/'.REKEY_LOGO.'?w=64', 'name' => 'A', 'elsewhere' => 'https://cdn.other.test/'.REKEY_LOGO,
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
        ->and($output)->toMatch('/site_settings\.value\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|\s*1\s*\|/')   // the logo on another host
        ->and($output)->toContain('unmatched (other host) in site_settings.value: https://cdn.other.test/'.REKEY_LOGO.' (not rewritten)')
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
        ->and($output)->toContain('Objects copied: 2, already there: 0, missing from storage: 1, new key grown past another object: 0.')
        ->and($output)->toContain('missing: '.REKEY_GONE)
        ->and($output)->toContain('Keys moved: 3 of 3.')
        ->and($output)->toContain('unmatched (other host) in site_settings.value: https://cdn.other.test/'.REKEY_LOGO.' (not rewritten)')
        ->and(rekeyKeys(1))->toBe([$hero, $logo, "{$code}/mat-ef34.png"])
        ->and(app(Tenancy::class)->enter(1, fn (): array => Media::query()->withTrashed()->orderBy('id')->get()->map(fn (Media $m) => $m->meta['moved_from'] ?? null)->all()))
        ->toBe([REKEY_HERO, REKEY_LOGO, REKEY_GONE])
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
            ->and(SiteSetting::query()->where('key', 'brand')->sole()->value)->toEqual(['logo' => "https://files.test/media/{$logo}?w=64", 'name' => 'A', 'elsewhere' => 'https://cdn.other.test/'.REKEY_LOGO])
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

it('reuses a copy an interrupted run left at the new key, and grows the hash past any other object there', function (): void {
    rekeyContent();
    $code = siteCode(1);
    $disk = Storage::disk(MediaKey::disk());
    $disk->put("{$code}/hero-home-7ca90eafe212.jpg", 'bytes of '.REKEY_HERO);     // the same bytes: an earlier copy
    $disk->put("{$code}/logo-ab12cd.png", 'a different file');                      // not this file
    $disk->put("{$code}/mat-ef34.png", 'cannot be compared: the source is gone');

    [$exit, $output] = rekeyRun(apply: true);

    $keys = rekeyKeys(1);
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Objects copied: 1, already there: 1, missing from storage: 1, new key grown past another object: 2.')
        ->and($keys[0])->toBe("{$code}/hero-home-7ca90eafe212.jpg")
        ->and($keys[1])->toMatch("#^{$code}/logo-ab12cd[0-9a-f]+\\.png$#")
        ->and($keys[2])->toMatch("#^{$code}/mat-ef34[0-9a-f]+\\.png$#")
        ->and($disk->get($keys[1]))->toBe('bytes of '.REKEY_LOGO)
        ->and($disk->get("{$code}/logo-ab12cd.png"))->toBe('a different file')
        ->and(app(Tenancy::class)->enter(1, fn () => DB::table('entry_translations')->value('body')))->toContain($keys[1]);
});

/**
 * One row in every place the command rewrites, each naming the logo by its key or its address.
 *
 * @return array<string, int> place => row id
 */
function rekeyEveryPlace(): array
{
    $address = 'https://files.test/media/'.REKEY_LOGO;
    $admin = DB::connection('pgsql_admin');
    $row = fn (string $table, array $values): int => (int) $admin->table($table)->insertGetId(['site_id' => 1, ...$values]);
    $ulid = fn (): string => strtolower((string) Str::ulid());

    $entry = $row('entries', ['ulid' => $ulid(), 'type' => 'post', 'status' => 'draft', 'meta' => json_encode(['image_url' => REKEY_LOGO])]);
    $block = $row('content_blocks', ['ulid' => $ulid(), 'key' => 'chan-trang', 'name' => 'Chân trang', 'region' => 'footer', 'status' => 'draft']);
    $menu = $row('menus', ['ulid' => $ulid(), 'handle' => 'chinh', 'name' => 'Chính', 'meta' => json_encode(['icon' => REKEY_LOGO])]);
    $profile = $admin->table('site_profiles')->where('site_id', 1)->value('id');
    if ($profile === null) {
        $profile = $row('site_profiles', ['meta' => '{}']);
    }
    $admin->table('site_profiles')->where('id', $profile)->update(['meta' => json_encode(['logo' => REKEY_LOGO])]);

    return [
        'entries.meta' => $entry,
        'entry_translations.body' => $row('entry_translations', ['entry_id' => $entry, 'locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai-moi', 'body' => json_encode(['blocks' => [['type' => 'image', 'props' => ['src' => $address]]]])]),
        'entry_drafts.content' => $row('entry_drafts', ['entry_id' => $entry, 'content' => json_encode(['locales' => ['vi' => ['meta' => ['image_url' => REKEY_LOGO]]]])]),
        'entry_revisions.snapshot' => $row('entry_revisions', ['ulid' => $ulid(), 'entry_id' => $entry, 'locale' => 'vi', 'kind' => 'edit', 'snapshot' => json_encode(['meta' => ['image_url' => REKEY_LOGO]])]),
        'content_block_translations.body' => $row('content_block_translations', ['content_block_id' => $block, 'locale' => 'vi', 'body' => json_encode(['blocks' => [['type' => 'cta', 'props' => ['button_url_do' => ['k' => 'file', 'file' => REKEY_LOGO]]]]])]),
        'site_settings.value' => $row('site_settings', ['group_name' => 'appearance', 'key' => 'heading_decor', 'value' => json_encode(['mask_media' => REKEY_LOGO])]),
        'taxonomies.meta' => $row('taxonomies', ['vocabulary' => 'category', 'meta' => json_encode(['image_url' => REKEY_LOGO])]),
        'products.meta' => $row('products', ['ulid' => $ulid(), 'meta' => json_encode(['images' => [REKEY_LOGO]])]),
        'menus.meta' => $menu,
        'menu_items.meta' => $row('menu_items', ['menu_id' => $menu, 'link_type' => 'url', 'url' => '/', 'meta' => json_encode(['image' => $address.'?w=64'])]),
        'content_sections.meta' => $row('content_sections', ['key' => 'du-an', 'label' => 'Dự án', 'meta' => json_encode(['cover' => REKEY_LOGO])]),
        'site_profiles.meta' => (int) $profile,
        'forms.settings' => $row('forms', ['ulid' => $ulid(), 'key' => 'lien-he', 'settings' => json_encode(['attachment' => REKEY_LOGO])]),
    ];
}

it('rewrites the reference in every place it lists', function (): void {
    rekeyMedia(1, REKEY_LOGO);
    $rows = rekeyEveryPlace();
    $logo = siteCode(1).'/logo-ab12cd.png';
    expect(array_keys($rows))->toEqualCanonicalizing(array_map(fn (array $place): string => implode('.', $place), RekeySiteMedia::PLACES));

    [$exit, $output] = rekeyRun(apply: true);

    expect($exit)->toBe(0, $output);
    $left = [];
    foreach ($rows as $place => $id) {
        [$table, $column] = explode('.', $place);
        $text = (string) DB::connection('pgsql_admin')->table($table)->where('id', $id)->value($column);
        if (! str_contains($text, $logo) || str_contains($text, REKEY_LOGO) || preg_match('/'.preg_quote($place, '/').'\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|/', $output) !== 1) {
            $left[$place] = $text;
        }
    }
    expect($left)->toBe([], $output);
});

it('repairs an old key written back after the run, counting it as a reference to rewrite, and the cover usage comes back', function (): void {
    ['post' => $post] = rekeyContent();
    $hero = siteCode(1).'/hero-home-7ca90eafe212.jpg';
    expect(rekeyRun(apply: true)[0])->toBe(0);

    // An editor opened before the run saves: the old key is back, and its save drops the cover row.
    DB::connection('pgsql_admin')->table('entries')->where('id', $post->id)->update(['meta' => json_encode(['image_url' => REKEY_HERO])]);
    $cover = fn (): array => app(Tenancy::class)->enter(1, fn (): array => Mediable::query()->where('mediable_type', 'entry')->where('mediable_id', $post->id)->where('collection', 'cover')->pluck('media_id')->all());
    app(Tenancy::class)->enter(1, fn (): array => EntryCoverUsage::reconcileSite(1));
    expect($cover())->toBe([]);

    [$exit, $output] = rekeyRun();
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Files to move: 0.')
        ->and($output)->toMatch('/entries\.meta\s*\|\s*1\s*\|\s*1\s*\|\s*0/')
        ->and($output)->not->toContain('unmatched in entries.meta');

    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and(app(Tenancy::class)->enter(1, fn () => Entry::query()->findOrFail($post->id)->meta))->toBe(['image_url' => $hero])
        ->and($cover())->toBe([app(Tenancy::class)->enter(1, fn () => Media::query()->where('storage_key', $hero)->value('id'))])
        ->and(rekeyRun()[1])->toMatch('/entries\.meta\s*\|\s*0\s*\|\s*0\s*\|\s*0/');
});

it('keeps the finished chunks of a run that stops, and the next run moves the rest', function (): void {
    $code = siteCode(1);
    foreach (range(1, SiteMediaRekey::MOVE_CHUNK + 1) as $n) {
        rekeyMedia(1, sprintf('cu-rk/library/anh-%03d-%04x.png', $n, $n), stored: false);
    }
    // The last row's new key is taken by an upload that lands after the plan: its chunk fails.
    $failing = false;
    DB::listen(function (QueryExecuted $query) use (&$failing, $code): void {
        if (! $failing && $query->connectionName !== 'pgsql_admin' && str_contains(strtolower($query->sql), 'update media as m set storage_key') && str_contains(implode(' ', array_map(strval(...), $query->bindings)), sprintf('anh-%03d', SiteMediaRekey::MOVE_CHUNK))) {
            $failing = true;
            rekeyMedia(1, sprintf('%s/anh-%03d-%04x.png', $code, SiteMediaRekey::MOVE_CHUNK + 1, SiteMediaRekey::MOVE_CHUNK + 1), stored: false);
        }
    });

    [$exit, $output] = rekeyRun(apply: true);
    $under = fn (): int => count(array_filter(rekeyKeys(1), fn (string $key): bool => str_starts_with($key, "{$code}/")));
    expect($exit)->toBe(1, $output)
        ->and($output)->toContain('Stopped after '.SiteMediaRekey::MOVE_CHUNK.' of '.(SiteMediaRekey::MOVE_CHUNK + 1).' keys')
        ->and($under())->toBe(SiteMediaRekey::MOVE_CHUNK + 1);   // the first chunk + the upload

    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)->and($output)->toContain('Keys moved: 1 of 1.')
        ->and($under())->toBe(SiteMediaRekey::MOVE_CHUNK + 2);
});

it('rewrites only addresses on the configured media host', function (): void {
    config(['media.cloudflare.base' => 'https://Media.Example.test/cwv-media']);
    $rewriter = new MediaKeyRewriter([REKEY_LOGO => 'q7k2m9/logo-ab12cd.png']);

    expect(MediaKeyRewriter::hosts())->toBe(['media.example.test'])
        ->and($rewriter->rewriteString('https://media.example.test/cwv-media/'.REKEY_LOGO))->toBe('https://media.example.test/cwv-media/q7k2m9/logo-ab12cd.png')
        ->and($rewriter->rewriteString('https://files.test/media/'.REKEY_LOGO))->toBeNull();

    config(['media.cloudflare.base' => null]);
    expect(MediaKeyRewriter::hosts())->toBe([]);
});

it('takes the website by its code too, and refuses an unknown one', function (): void {
    rekeyMedia(1, REKEY_LOGO);

    expect(rekeyRun(site: siteCode(1))[1])->toContain('Files to move: 1.')
        ->and(rekeyRun(site: 'zzzzzz'))->sequence(
            fn ($exit) => $exit->toBe(1),
            fn ($output) => $output->toContain('No website with that id or code.'),
        );
});

it('reads a value that can be a code as a code first, then as an id', function (): void {
    rekeyMedia(1, REKEY_LOGO);
    DB::connection('pgsql_admin')->table('sites')->insert(['ulid' => 'SITEC000000000000000000001', 'tenant_id' => 1, 'code' => '000001', 'name' => 'C', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $third = (int) DB::connection('pgsql_admin')->table('sites')->where('code', '000001')->value('id');

    expect(rekeyRun(site: '000001')[1])->toContain("website #{$third} (C)")->toContain('Files to move: 0.')
        ->and(rekeyRun(site: '000002')[1])->toContain('website #2 (B)')     // no such code: the id
        ->and(rekeyRun(site: '1')[1])->toContain('website #1 (A)')->toContain('Files to move: 1.');
});

it('skips and lists a key it cannot give a new one, and still moves the rest', function (): void {
    rekeyMedia(1, REKEY_LOGO);
    rekeyMedia(1, 'cu-rk/library/khong-duoi');
    $logo = siteCode(1).'/logo-ab12cd.png';

    [$exit, $output] = rekeyRun();
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Files to move: 1.')
        ->and($output)->toContain('Files skipped (move them by hand): 1.')
        ->and($output)->toContain('skipped: cu-rk/library/khong-duoi (no file name with an extension; re-key it by hand)');

    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('skipped: cu-rk/library/khong-duoi')
        ->and($output)->toContain('Keys moved: 1 of 1.')
        ->and(rekeyKeys(1))->toBe([$logo, 'cu-rk/library/khong-duoi']);
});

it('runs a chunk again whole when it lost a lock conflict, and gives up after the last try', function (string $state): void {
    rekeyMedia(1, REKEY_LOGO);
    $logo = siteCode(1).'/logo-ab12cd.png';
    $tries = 0;
    $failures = 1;
    // The chunk's lock on the media rows is where a brand save (media, then settings) meets it.
    DB::listen(function (QueryExecuted $query) use (&$tries, &$failures, $state): void {
        $sql = strtolower($query->sql);
        if ($query->connectionName === 'pgsql_admin' || ! str_contains($sql, 'from "media"') || ! str_contains($sql, 'for update')) {
            return;
        }
        $tries++;
        if ($tries <= $failures) {
            $pdo = new PDOException($state === '40P01' ? 'deadlock detected' : 'canceling statement due to lock timeout');
            $pdo->errorInfo = [$state, 7, $pdo->getMessage()];

            throw new QueryException($query->connectionName, $query->sql, [], $pdo);
        }
    });

    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and($tries)->toBe(2)
        ->and(rekeyKeys(1))->toBe([$logo]);

    // A conflict on every try: the chunk left nothing, and the run says so.
    rekeyMedia(1, 'cu-rk/library/mat-ef34.png');
    [$tries, $failures] = [0, SiteMediaRekey::MOVE_TRIES];
    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(1, $output)
        ->and($tries)->toBe(SiteMediaRekey::MOVE_TRIES)
        ->and($output)->toContain('Stopped after 0 of 1 keys')
        ->and(rekeyKeys(1))->toBe([$logo, 'cu-rk/library/mat-ef34.png']);
})->with(['deadlock' => '40P01', 'lock timeout' => '55P03']);

it('removes the old object too when a moved file is deleted for good, but never a seed file', function (): void {
    rekeyMedia(1, REKEY_LOGO);
    $disk = Storage::disk(MediaKey::disk());
    $seed = 'an-vinh/hero/hero-home-7ca90eafe212.jpg';
    $disk->put($seed, 'seed bytes');
    $seeded = app(Tenancy::class)->enter(1, fn (): Media => app(EnsureMediaFromKey::class)->fromSeedSource($seed));
    expect($seeded->meta)->toBe(['seed_source' => $seed]);
    expect(rekeyRun(apply: true)[0])->toBe(0);
    $logo = siteCode(1).'/logo-ab12cd.png';
    expect($disk->exists($logo))->toBeTrue()->and($disk->exists(REKEY_LOGO))->toBeTrue();

    app(Tenancy::class)->enter(1, function () use ($logo, $seeded): void {
        Media::query()->where('storage_key', $logo)->sole()->forceDelete();
        Media::query()->findOrFail($seeded->id)->forceDelete();
    });

    expect($disk->exists($logo))->toBeFalse()
        ->and($disk->exists(REKEY_LOGO))->toBeFalse()                      // its address before the move is gone too
        ->and($disk->exists($seeded->storage_key))->toBeFalse()
        ->and($disk->get($seed))->toBe('seed bytes');                       // shared by every seeded website
});

it('records an old key under a seed folder as the seed source, so a permanent delete keeps the shared seed file', function (): void {
    $disk = Storage::disk(MediaKey::disk());
    $seed = 'an-vinh/hero/hero-home-7ca90eafe212.jpg';
    // As on a website seeded before codes: the seed folder is recorded as its old folder.
    DB::connection('pgsql_admin')->table('reserved_site_codes')->insert(['code' => 'an-vinh', 'reason' => 'legacy_key', 'site_id' => 1]);
    rekeyMedia(1, $seed);                                                  // an older row pointing straight at the seed file
    rekeyMedia(1, REKEY_LOGO);                                             // and one of the website's own old files
    expect(rekeyRun(apply: true)[0])->toBe(0);

    $code = siteCode(1);
    [$hero, $logo] = app(Tenancy::class)->enter(1, fn (): array => [
        Media::query()->where('storage_key', "{$code}/hero-home-7ca90eafe212.jpg")->sole(),
        Media::query()->where('storage_key', "{$code}/logo-ab12cd.png")->sole(),
    ]);
    expect($hero->meta)->toBe(['seed_source' => $seed])
        ->and($logo->meta)->toBe(['moved_from' => REKEY_LOGO])
        ->and(app(SiteMediaRekey::class)->previousKeys(1))->toBe([$seed => $hero->storage_key, REKEY_LOGO => $logo->storage_key]);

    app(Tenancy::class)->enter(1, function () use ($hero, $logo): void {
        $hero->forceDelete();
        $logo->forceDelete();
    });

    expect($disk->exists($hero->storage_key))->toBeFalse()                // the website's own copy is gone
        ->and($disk->get($seed))->toBe("bytes of {$seed}")                  // the seed file stays for every later seeding
        ->and($disk->exists($logo->storage_key))->toBeFalse()
        ->and($disk->exists(REKEY_LOGO))->toBeFalse();                      // a moved row's own old object still goes
});

const REKEY_SEED_LOGO = 'an-vinh/brand/logo-e76d7cb1dfe4.png';
const REKEY_SEED_HERO = 'an-vinh/hero/hero-home-7ca90eafe212.jpg';

/** A brand setting of $site whose logo is the address $logo (the form an older seeder stored). */
function rekeyBrand(int $site, string $logo): void
{
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => ['logo' => $logo, 'name' => 'A']]));
}

it('adopts a seed file a live place names with no row of the website, rewrites it in its own form, and finds nothing the second time', function (): void {
    $disk = Storage::disk(MediaKey::disk());
    $disk->put(REKEY_SEED_LOGO, 'seed logo');
    $disk->put(REKEY_SEED_HERO, 'seed hero');
    rekeyBrand(1, 'https://files.test/media/'.REKEY_SEED_LOGO);
    $post = app(Tenancy::class)->enter(1, fn (): Entry => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => REKEY_SEED_HERO]]));
    rekeyMedia(2, REKEY_OTHER);                                             // another website naming the same seed file
    rekeyBrand(2, 'https://files.test/media/'.REKEY_SEED_LOGO);
    $code = siteCode(1);
    $logo = "{$code}/logo-e76d7cb1dfe4.png";
    $hero = "{$code}/hero-home-7ca90eafe212.jpg";

    [$exit, $output] = rekeyRun();
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Seed files to adopt: 2.')
        ->and($output)->toContain('to adopt: '.REKEY_SEED_LOGO." -> {$logo}")
        ->and($output)->toMatch('/site_settings\.value\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|/')
        ->and($output)->toMatch('/entries\.meta\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|/')
        ->and(rekeyKeys(1))->toBe([])
        ->and($disk->allFiles($code))->toBe([]);

    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files adopted: 2 of 2.');
    app(Tenancy::class)->enter(1, function () use ($post, $logo, $hero): void {
        expect(Media::query()->orderBy('storage_key')->get()->map(fn (Media $m): array => [$m->storage_key, $m->meta])->all())
            ->toBe([[$hero, ['seed_source' => REKEY_SEED_HERO]], [$logo, ['seed_source' => REKEY_SEED_LOGO]]])
            ->and(SiteSetting::query()->where('key', 'brand')->sole()->value)->toEqual(['logo' => "https://files.test/media/{$logo}", 'name' => 'A'])
            ->and(Entry::query()->findOrFail($post->id)->meta)->toBe(['image_url' => $hero]);   // a key stays a key
    });
    expect($disk->get($logo))->toBe('seed logo')
        ->and($disk->get(REKEY_SEED_LOGO))->toBe('seed logo')                // the seed file stays for every website
        ->and($disk->get(REKEY_SEED_HERO))->toBe('seed hero')
        ->and(rekeyKeys(2))->toBe([REKEY_OTHER])
        ->and(app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->where('key', 'brand')->sole()->value))
        ->toEqual(['logo' => 'https://files.test/media/'.REKEY_SEED_LOGO, 'name' => 'A']);

    [$exit, $output] = rekeyRun();
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Files to move: 0.')
        ->and($output)->toContain('Seed files to adopt: 0.')
        ->and($output)->toMatch('/site_settings\.value\s*\|\s*0\s*\|\s*0\s*\|\s*0\s*\|/')
        ->and($output)->toMatch('/entries\.meta\s*\|\s*0\s*\|\s*0\s*\|\s*0\s*\|/');

    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files adopted: 0 of 0.')->and(rekeyKeys(1))->toHaveCount(2);
});

it('never adopts a seed file only history names, and reports it as unmatched', function (): void {
    $seed = 'an-vinh/du-an/nha-xuong-1a2b3c.jpg';
    Storage::disk(MediaKey::disk())->put($seed, 'seed bytes');
    $snapshot = ['v' => 1, 'meta' => ['image_url' => $seed]];
    $post = app(Tenancy::class)->enter(1, function () use ($snapshot): Entry {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
        EntryRevision::query()->create(['entry_id' => $post->id, 'locale' => 'vi', 'kind' => RevisionKind::Edit, 'snapshot' => $snapshot]);

        return $post;
    });

    [$exit, $output] = rekeyRun(apply: true);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Seed files adopted: 0 of 0.')
        ->and($output)->toContain("unmatched in entry_revisions.snapshot: {$seed}")
        ->and(rekeyKeys(1))->toBe([])
        ->and(app(Tenancy::class)->enter(1, fn () => EntryRevision::query()->where('entry_id', $post->id)->sole()->snapshot))->toEqual($snapshot);
});

it('leaves a seed file missing from storage unmatched and gives it no row', function (): void {
    $seed = 'an-vinh/brand/mat-9f9f9f.png';
    rekeyBrand(1, "https://files.test/media/{$seed}");

    [$exit, $output] = rekeyRun();
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Seed files to adopt: 0.')
        ->and($output)->toContain("not adopted: {$seed} (the seed file is not in storage; left unmatched)")
        ->and($output)->toContain("unmatched in site_settings.value: https://files.test/media/{$seed}");

    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Seed files adopted: 0 of 0.')
        ->and(rekeyKeys(1))->toBe([])
        ->and(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'brand')->sole()->value))
        ->toEqual(['logo' => "https://files.test/media/{$seed}", 'name' => 'A']);
});

/** The brand logo address of $site, read back. */
function rekeyBrandLogo(int $site): mixed
{
    return app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->where('key', 'brand')->sole()->value['logo'] ?? null);
}

it('never takes in a seed file under another website\'s old folder, and adopts it for that website itself', function (): void {
    DB::connection('pgsql_admin')->table('reserved_site_codes')->insert(['code' => 'an-vinh', 'reason' => 'legacy_key', 'site_id' => 1]);
    $disk = Storage::disk(MediaKey::disk());
    $disk->put(REKEY_SEED_LOGO, 'seed logo');
    $address = 'https://files.test/media/'.REKEY_SEED_LOGO;
    rekeyBrand(1, $address);
    rekeyBrand(2, $address);
    $line = 'not adopted: '.REKEY_SEED_LOGO." (another website's folder; left unmatched)";

    [$exit, $output] = rekeyRun(site: '2');
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files to adopt: 0.')->and($output)->toContain($line);
    [$exit, $output] = rekeyRun(apply: true, site: '2');
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files adopted: 0 of 0.')->and($output)->toContain($line)
        ->and(rekeyKeys(2))->toBe([])
        ->and($disk->allFiles(siteCode(2)))->toBe([])
        ->and(rekeyBrandLogo(2))->toBe($address);

    [$exit, $output] = rekeyRun(apply: true);
    $logo = siteCode(1).'/logo-e76d7cb1dfe4.png';
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files adopted: 1 of 1.')
        ->and(rekeyKeys(1))->toBe([$logo])
        ->and(rekeyBrandLogo(1))->toBe("https://files.test/media/{$logo}");
});

it('adopts a seed file under a folder whose only row has no owner, as a hard-deleted owner leaves it', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('reserved_site_codes')->where('code', 'an-vinh')->where('reason', 'legacy_key')->whereNotNull('site_id')->delete();
    // The ownerless row the sites AFTER DELETE trigger leaves (or the freeze, where nobody held a row).
    $inserted = $admin->table('reserved_site_codes')->insertOrIgnore(['code' => 'an-vinh', 'reason' => 'legacy_key', 'site_id' => null]) === 1;
    try {
        expect($admin->table('reserved_site_codes')->where('code', 'an-vinh')->where('reason', 'legacy_key')->pluck('site_id')->all())->toBe([null]);
        Storage::disk(MediaKey::disk())->put(REKEY_SEED_LOGO, 'seed logo');
        rekeyBrand(1, 'https://files.test/media/'.REKEY_SEED_LOGO);
        $logo = siteCode(1).'/logo-e76d7cb1dfe4.png';

        [$exit, $output] = rekeyRun(apply: true);

        expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files adopted: 1 of 1.')
            ->and(rekeyKeys(1))->toBe([$logo])
            ->and(rekeyBrandLogo(1))->toBe("https://files.test/media/{$logo}");
    } finally {
        if ($inserted) {
            $admin->table('reserved_site_codes')->where('code', 'an-vinh')->where('reason', 'legacy_key')->whereNull('site_id')->delete();
        }
    }
});

it('never adopts a seed file under a folder several websites own, even for one of them', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('reserved_site_codes')->insert([
        ['code' => 'an-vinh', 'reason' => 'legacy_key', 'site_id' => 1],
        ['code' => 'an-vinh', 'reason' => 'legacy_key', 'site_id' => 2],
    ]);
    $disk = Storage::disk(MediaKey::disk());
    $disk->put(REKEY_SEED_LOGO, 'seed logo');
    $address = 'https://files.test/media/'.REKEY_SEED_LOGO;
    rekeyBrand(1, $address);
    $line = 'not adopted: '.REKEY_SEED_LOGO.' (shared folder; left unmatched)';

    [$exit, $output] = rekeyRun();
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files to adopt: 0.')->and($output)->toContain($line);
    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files adopted: 0 of 0.')->and($output)->toContain($line)
        ->and(rekeyKeys(1))->toBe([])
        ->and($disk->allFiles(siteCode(1)))->toBe([])
        ->and(rekeyBrandLogo(1))->toBe($address);
});

it('adopts a seed file under a folder whose only owner is the website itself', function (): void {
    DB::connection('pgsql_admin')->table('reserved_site_codes')->insert(['code' => 'an-vinh', 'reason' => 'legacy_key', 'site_id' => 1]);
    Storage::disk(MediaKey::disk())->put(REKEY_SEED_LOGO, 'seed logo');
    rekeyBrand(1, 'https://files.test/media/'.REKEY_SEED_LOGO);
    $logo = siteCode(1).'/logo-e76d7cb1dfe4.png';

    [$exit, $output] = rekeyRun();
    expect($exit)->toBe(0, $output)->and($output)->toContain('to adopt: '.REKEY_SEED_LOGO." -> {$logo}");
    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files adopted: 1 of 1.')
        ->and(rekeyKeys(1))->toBe([$logo])
        ->and(rekeyBrandLogo(1))->toBe("https://files.test/media/{$logo}");
});

it('shows a seed file whose name a planned move takes as not adopted in the dry run too', function (): void {
    rekeyMedia(1, REKEY_LOGO);                                              // moves to {code}/logo-ab12cd.png
    $seed = 'an-vinh/brand/logo-ab12cd.jpg';                                // the same stem under another extension
    Storage::disk(MediaKey::disk())->put($seed, 'seed logo');
    rekeyBrand(1, "https://files.test/media/{$seed}");
    $code = siteCode(1);
    $line = "not adopted: {$seed} (the website already has another file at {$code}/logo-ab12cd.jpg; left unmatched)";

    [$exit, $output] = rekeyRun();
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files to adopt: 0.')->and($output)->toContain($line);
    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files adopted: 0 of 0.')->and($output)->toContain($line)
        ->and(rekeyKeys(1))->toBe(["{$code}/logo-ab12cd.png"])
        ->and(rekeyBrandLogo(1))->toBe("https://files.test/media/{$seed}");
});

it('does not adopt a seed file whose new key another row of the website holds', function (): void {
    $disk = Storage::disk(MediaKey::disk());
    $disk->put(REKEY_SEED_LOGO, 'seed logo');
    $target = siteCode(1).'/logo-e76d7cb1dfe4.png';
    rekeyMedia(1, $target, stored: false);                                  // no object, so only the row stands in the way
    rekeyBrand(1, 'https://files.test/media/'.REKEY_SEED_LOGO);
    $line = 'not adopted: '.REKEY_SEED_LOGO." (the website already has another file at {$target}; left unmatched)";

    foreach ([false, true] as $apply) {
        [$exit, $output] = rekeyRun(apply: $apply);
        expect($exit)->toBe(0, $output)->and($output)->toContain($line);
    }
    expect(rekeyKeys(1))->toBe([$target])
        ->and($disk->exists($target))->toBeFalse()
        ->and(rekeyBrandLogo(1))->toBe('https://files.test/media/'.REKEY_SEED_LOGO);
});

it('does not adopt a seed file when another object is stored at its new key', function (): void {
    $disk = Storage::disk(MediaKey::disk());
    $disk->put(REKEY_SEED_LOGO, 'seed logo');
    $target = siteCode(1).'/logo-e76d7cb1dfe4.png';
    $disk->put($target, 'other bytes');                                     // an object with no row
    rekeyBrand(1, 'https://files.test/media/'.REKEY_SEED_LOGO);
    $line = 'not adopted: '.REKEY_SEED_LOGO." (another object is stored at {$target}; left unmatched)";

    foreach ([false, true] as $apply) {
        [$exit, $output] = rekeyRun(apply: $apply);
        expect($exit)->toBe(0, $output)->and($output)->toContain($line);
    }
    expect(rekeyKeys(1))->toBe([])
        ->and($disk->get($target))->toBe('other bytes')
        ->and(rekeyBrandLogo(1))->toBe('https://files.test/media/'.REKEY_SEED_LOGO);
});

it('adopts only the first of two seed files with the same name, and lists the other in the dry run too', function (): void {
    $disk = Storage::disk(MediaKey::disk());
    $brand = 'an-vinh/brand/anh-1a2b.png';
    $hero = 'an-vinh/hero/anh-1a2b.png';
    $disk->put($brand, 'brand bytes');
    $disk->put($hero, 'hero bytes');
    rekeyBrand(1, "https://files.test/media/{$brand}");
    $post = app(Tenancy::class)->enter(1, fn (): Entry => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => $hero]]));
    $target = siteCode(1).'/anh-1a2b.png';
    $line = "not adopted: {$hero} (same name as {$brand}; left unmatched)";

    [$exit, $output] = rekeyRun();
    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('Seed files to adopt: 1.')
        ->and($output)->toContain("to adopt: {$brand} -> {$target}")
        ->and($output)->toContain($line);

    [$exit, $output] = rekeyRun(apply: true);
    expect($exit)->toBe(0, $output)->and($output)->toContain('Seed files adopted: 1 of 1.')->and($output)->toContain($line)
        ->and(rekeyKeys(1))->toBe([$target])
        ->and($disk->get($target))->toBe('brand bytes')
        ->and(rekeyBrandLogo(1))->toBe("https://files.test/media/{$target}")
        ->and(app(Tenancy::class)->enter(1, fn () => Entry::query()->findOrFail($post->id)->meta))->toBe(['image_url' => $hero]);
});

it('reports a seed file whose copy fails, tries the next one, and still finishes the run', function (): void {
    $disk = Storage::disk(MediaKey::disk());
    $disk->put(REKEY_SEED_LOGO, 'seed logo');
    $disk->put(REKEY_SEED_HERO, 'seed hero');
    $code = siteCode(1);
    $disk->put($code, 'a file where the folder should be');                // every copy under the code fails
    rekeyMedia(1, REKEY_LOGO, stored: false);                               // a key that still moves (no object to copy)
    rekeyBrand(1, 'https://files.test/media/'.REKEY_SEED_LOGO);
    app(Tenancy::class)->enter(1, fn (): Entry => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => REKEY_SEED_HERO]]));

    [$exit, $output] = rekeyRun(apply: true);

    expect($exit)->toBe(1, $output)                                         // so the release step stops
        ->and($output)->toContain('Seed files adopted: 0 of 2.')
        ->and($output)->toContain('not adopted: '.REKEY_SEED_HERO.' (copy failed: ')
        ->and($output)->toContain('not adopted: '.REKEY_SEED_LOGO.' (copy failed: ')
        ->and($output)->toContain('Some seed files could not be adopted')
        ->and($output)->toContain('Keys moved: 1 of 1. Featured-picture usage:')   // the reference pass and the usage still ran
        ->and($output)->not->toContain('Stopped after')
        ->and(rekeyKeys(1))->toBe(["{$code}/logo-ab12cd.png"])
        ->and(rekeyBrandLogo(1))->toBe('https://files.test/media/'.REKEY_SEED_LOGO);
});

/** Two seed files site 1's live places name (logo first in the adoption order), with their objects. */
function rekeyTwoSeeds(): void
{
    $disk = Storage::disk(MediaKey::disk());
    $disk->put(REKEY_SEED_LOGO, 'seed logo');
    $disk->put(REKEY_SEED_HERO, 'seed hero');
    rekeyBrand(1, 'https://files.test/media/'.REKEY_SEED_LOGO);
    app(Tenancy::class)->enter(1, fn (): Entry => Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => ['image_url' => REKEY_SEED_HERO]]));
}

it('reports a seed whose key another writer took (a unique violation), adopts the next one and ends with a failure exit', function (): void {
    rekeyTwoSeeds();
    $code = siteCode(1);
    $creates = 0;
    Event::listen('eloquent.creating: '.Media::class, function () use (&$creates): void {
        if (++$creates === 1) {
            $pdo = new PDOException('duplicate key value violates unique constraint');
            $pdo->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint'];

            throw new QueryException('pgsql', 'insert into "media"', [], $pdo);
        }
    });

    [$exit, $output] = rekeyRun(apply: true);

    expect($exit)->toBe(1, $output)
        ->and($output)->toContain('Seed files adopted: 1 of 2.')
        ->and($output)->toContain('not adopted: '.REKEY_SEED_LOGO." ({$code}/logo-e76d7cb1dfe4.png was taken meanwhile; left unmatched)")
        ->and($output)->toContain('Some seed files could not be adopted')
        ->and($output)->not->toContain('Stopped after')
        ->and(rekeyKeys(1))->toBe(["{$code}/hero-home-7ca90eafe212.jpg"]);
});

it('stops the run on any other error while adopting a seed, a tenancy assertion among them', function (): void {
    rekeyTwoSeeds();
    Event::listen('eloquent.creating: '.Media::class, function (): void {
        throw new RuntimeException('Tenant isolation assertion failed: a test stand-in.');
    });

    [$exit, $output] = rekeyRun(apply: true);

    expect($exit)->toBe(1, $output)
        ->and($output)->toContain('Stopped after 0 of 0 keys: Tenant isolation assertion failed: a test stand-in.')
        ->and($output)->not->toContain('not adopted:')                     // never taken for a failed seed
        ->and(rekeyKeys(1))->toBe([]);                                      // and the next seed was not tried
});

it('refuses a single-site install, whose files stay under their collections', function (): void {
    config(['media.tenant_prefix' => false]);

    [$exit, $output] = rekeyRun(apply: true);

    expect($exit)->toBe(1)->and($output)->toContain('media.tenant_prefix is off');
});
