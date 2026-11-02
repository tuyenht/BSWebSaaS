<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * site:load-content --media — the image step. A dry run lists what each image slot would do and
 * uploads nothing; --apply uploads the pack's files through the media library's own upload path
 * (magic-byte sniffing, content-hash key, one row per (collection, hash)), reuses a file the site
 * already has, reports one in the Trash instead of restoring it, and refuses before any upload
 * when the site's storage folder is shared with another website or a key would land outside it.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    Storage::fake('r2');
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

/**
 * Dry run of the image step, then apply what it printed; returns the apply's [exit code, output].
 *
 * @param  list<string>  $force
 * @return array{0: int, 1: string}
 */
function mediaLoad(array $force = []): array
{
    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true, '--force-item' => $force]);
    if ($exit !== 0) {
        throw new RuntimeException("Image dry run failed:\n".$output);
    }

    return ContentPacks::run('demo-co', ['--media' => true, '--apply' => true, '--plan' => ContentPacks::fingerprint($output), '--force-item' => $force]);
}

/** @return array<string, string> slot file name → the site's media row key, for the pack's two files */
function packMedia(int $siteId): array
{
    return ContentPacks::in($siteId, fn () => Media::query()->withTrashed()->whereIn('original_filename', ['header.png', 'news-1.png'])
        ->pluck('storage_key', 'original_filename')->all());
}

it('uploads nothing on a dry run and prints what each slot would do', function (): void {
    $writes = ContentPacks::writes(function () use (&$exit, &$output): void {
        [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true]);
    });

    expect($exit)->toBe(0, $output)
        ->and($writes)->toBe([])
        ->and(Storage::disk('r2')->allFiles())->toBe([])
        ->and(ContentPacks::fingerprint($output))->toMatch('/^[0-9a-f]{12}$/')
        ->and(ContentPacks::verdict($output, 'media:header'))->toBe('upload')
        ->and(ContentPacks::verdict($output, 'media:news-1'))->toBe('upload')
        ->and($output)->toMatch('#media:header\s.*demo/library/header-[0-9a-f]{4}\.png#');
});

it('uploads each file once through the media library and reuses it on the next run (one row, one put)', function (): void {
    [$exit, $output] = mediaLoad();
    expect($exit)->toBe(0, $output);

    $keys = packMedia($this->siteId);
    expect($keys)->toHaveCount(2)
        ->and($keys['header.png'])->toMatch('#^demo/library/header-[0-9a-f]{4}\.png$#')
        ->and(Storage::disk('r2')->allFiles())->toEqualCanonicalizing(array_values($keys));

    $header = ContentPacks::in($this->siteId, fn () => Media::query()->where('storage_key', $keys['header.png'])->firstOrFail());
    expect($header->hash)->toBe(hash_file('sha256', $this->folder.'/media/header.png'))
        ->and($header->mime)->toBe('image/png')
        ->and($header->width)->toBe(8)
        ->and($header->alt)->toBe(['vi' => 'Đầu trang'])
        ->and($header->created_by)->toBeNull();

    // A second put would bring the original bytes back over this marker.
    Storage::disk('r2')->put($keys['header.png'], 'marker');

    [$exit, $output] = mediaLoad();
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'media:header'))->toBe('reuse')
        ->and(ContentPacks::verdict($output, 'media:news-1'))->toBe('reuse')
        ->and(packMedia($this->siteId))->toBe($keys)
        ->and(ContentPacks::in($this->siteId, fn () => Media::query()->count()))->toBe(2)
        ->and(Storage::disk('r2')->get($keys['header.png']))->toBe('marker');
});

it('reports a matching file in the Trash without restoring it, and restores it only when forced', function (): void {
    mediaLoad();
    $key = packMedia($this->siteId)['header.png'];
    ContentPacks::in($this->siteId, fn () => Media::query()->where('storage_key', $key)->firstOrFail()->delete());

    [$exit, $output] = mediaLoad();
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'media:header'))->toBe('in-trash')
        ->and($output)->toContain('--force-item=media:header')
        ->and(ContentPacks::in($this->siteId, fn () => Media::onlyTrashed()->where('storage_key', $key)->exists()))->toBeTrue();

    [$exit, $output] = mediaLoad(['media:header']);
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'media:header'))->toBe('restore (forced)')
        ->and(ContentPacks::in($this->siteId, fn () => Media::query()->where('storage_key', $key)->exists()))->toBeTrue()
        ->and(ContentPacks::in($this->siteId, fn () => Media::query()->withTrashed()->count()))->toBe(2);
});

it('refuses before any upload when another website\'s name gives the same storage folder', function (): void {
    StorefrontCards::site('DEMO!', 'khac.platform.test');

    $writes = ContentPacks::writes(function () use (&$exit, &$output): void {
        [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true]);
    });

    expect($exit)->toBe(1, $output)
        ->and($output)->toContain('another website')
        ->and($output)->not->toContain('DEMO!')
        ->and($output)->not->toContain('khac.platform.test')
        ->and($writes)->toBe([])
        ->and(Storage::disk('r2')->allFiles())->toBe([]);

    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true, '--apply' => true, '--plan' => 'abcdefabcdef']);
    expect($exit)->toBe(1, $output)->and($output)->toContain('another website')
        ->and(packMedia($this->siteId))->toBe([]);
});

it('refuses a key that would land outside the site\'s own storage folder', function (): void {
    config(['media.tenant_prefix' => false]);

    $writes = ContentPacks::writes(function () use (&$exit, &$output): void {
        [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true]);
    });

    expect($exit)->toBe(1, $output)
        ->and($output)->toContain("outside this site's storage folder 'demo/'")
        ->and($writes)->toBe([])
        ->and(Storage::disk('r2')->allFiles())->toBe([]);
});

it('refuses an apply whose image plan changed since the dry run', function (): void {
    [, $output] = ContentPacks::run('demo-co', ['--media' => true]);
    // Staff upload the same picture in between: the slot would now be reused, not uploaded.
    $bytes = (string) file_get_contents($this->folder.'/media/header.png');
    ContentPacks::in($this->siteId, fn () => Media::query()->create([
        'collection' => 'library', 'slug' => 'dau-trang', 'hash' => hash('sha256', $bytes),
        'storage_key' => 'demo/library/dau-trang-0000.png', 'mime' => 'image/png', 'size' => strlen($bytes),
    ]));

    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true, '--apply' => true, '--plan' => ContentPacks::fingerprint($output)]);

    expect($exit)->toBe(1, $output)->and($output)->toContain('plan changed')
        ->and(packMedia($this->siteId))->toBe([])
        ->and(Storage::disk('r2')->allFiles())->toBe([]);
});

it('takes only media slot keys for --force-item with --media', function (): void {
    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true, '--force-item' => ['page:/gioi-thieu']]);

    expect($exit)->toBe(1)->and($output)->toContain('--force-item with --media takes media:<slot> keys');
});

it('leaves another website\'s media alone, even with the same bytes', function (): void {
    $otherId = StorefrontCards::site('Khác', 'khac.platform.test');
    $bytes = (string) file_get_contents($this->folder.'/media/header.png');
    ContentPacks::in($otherId, fn () => Media::query()->create([
        'collection' => 'library', 'slug' => 'header', 'hash' => hash('sha256', $bytes),
        'storage_key' => 'khac/library/header-0000.png', 'mime' => 'image/png', 'size' => strlen($bytes),
    ]));
    Storage::disk('r2')->put('khac/library/header-0000.png', 'theirs');
    $before = DB::connection('pgsql_admin')->table('media')->where('site_id', $otherId)->get()->map(fn ($row) => (array) $row)->all();

    [$exit, $output] = mediaLoad();

    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'media:header'))->toBe('upload')
        ->and(packMedia($this->siteId))->toHaveCount(2)
        ->and(DB::connection('pgsql_admin')->table('media')->where('site_id', $otherId)->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and(Storage::disk('r2')->get('khac/library/header-0000.png'))->toBe('theirs')
        ->and(Site::query()->whereKey($otherId)->exists())->toBeTrue();
});

it('resolves the pack\'s image slots to the uploaded media on the next content load', function (): void {
    [$exit, $output] = mediaLoad();
    expect($exit)->toBe(0, $output);
    $keys = packMedia($this->siteId);

    [, $dry] = ContentPacks::run();
    expect($dry)->toContain('2 with the pack\'s own image');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    ContentPacks::in($this->siteId, function () use ($keys): void {
        $header = Media::query()->where('storage_key', $keys['header.png'])->firstOrFail();
        $entry = Entry::query()->whereKey(SiteRoute::query()->where('path', '/gioi-thieu')->value('entry_id'))->firstOrFail();
        $post = Entry::query()->whereKey(EntryTranslation::query()->where('slug', 'ra-mat-website')->value('entry_id'))->firstOrFail();

        expect($entry->translationFor('vi')?->body['blocks'][0]['props']['image_url'])->toBe($header->id)
            ->and(Mediable::query()->where('mediable_type', 'entry')->where('mediable_id', $entry->id)->where('media_id', $header->id)->exists())->toBeTrue()
            ->and($post->meta['image_url'] ?? null)->toBe($keys['news-1.png']);
    });

    $html = $this->get('http://'.ContentPacks::HOST.'/gioi-thieu')->assertOk()->getContent();
    expect($html)->toContain('header-');
});
