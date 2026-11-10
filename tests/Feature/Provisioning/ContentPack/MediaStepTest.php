<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

it('counts a website in the Trash when it looks for a shared storage folder', function (): void {
    $otherId = StorefrontCards::site('DEMO!', 'khac.platform.test');
    Site::query()->whereKey($otherId)->firstOrFail()->delete();

    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true]);

    expect($exit)->toBe(1, $output)->and($output)->toContain('another website');
});

it('refuses an upload that would overwrite an object no image of the site owns', function (): void {
    [, $output] = ContentPacks::run('demo-co', ['--media' => true]);
    preg_match('#(demo/library/header-[0-9a-f]{4}\.png)#', $output, $m);
    Storage::disk('r2')->put($m[1], 'left by an earlier owner of the folder');

    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true]);

    expect($exit)->toBe(1, $output)
        ->and($output)->toContain('would overwrite an object')
        ->and(Storage::disk('r2')->get($m[1]))->toBe('left by an earlier owner of the folder')
        ->and(packMedia($this->siteId))->toBe([]);
});

it('refuses a forced restore of a row stored outside the site\'s folder', function (): void {
    $bytes = (string) file_get_contents($this->folder.'/media/header.png');
    ContentPacks::in($this->siteId, fn () => Media::query()->create([
        'collection' => 'library', 'slug' => 'cu', 'hash' => hash('sha256', $bytes),
        'storage_key' => 'cu/library/cu-0000.png', 'mime' => 'image/png', 'size' => strlen($bytes),
    ])->delete());

    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true]);
    expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'media:header'))->toBe('in-trash');

    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true, '--force-item' => ['media:header']]);
    expect($exit)->toBe(1, $output)->and($output)->toContain("outside this site's storage folder 'demo/'");
});

it('gives two slots with the same bytes one row, and forcing either restores it for both', function (): void {
    ContentPacks::edit($this->folder, 'media.json', function (array $media): array {
        $media['slots'][] = ['slot' => 'header-copy', 'file' => 'media/header.png', 'alt' => ['vi' => 'Bản sao']];

        return $media;
    });

    [$exit, $output] = mediaLoad();
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'media:header'))->toBe('upload')
        ->and(ContentPacks::verdict($output, 'media:header-copy'))->toBe('reuse')
        ->and(ContentPacks::in($this->siteId, fn () => Media::query()->count()))->toBe(2);

    $key = packMedia($this->siteId)['header.png'];
    ContentPacks::in($this->siteId, fn () => Media::query()->where('storage_key', $key)->firstOrFail()->delete());

    [, $output] = ContentPacks::run('demo-co', ['--media' => true]);
    expect(ContentPacks::verdict($output, 'media:header'))->toBe('in-trash')
        ->and(ContentPacks::verdict($output, 'media:header-copy'))->toBe('in-trash');

    [$exit, $output] = mediaLoad(['media:header-copy']);
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'media:header'))->toBe('restore')
        ->and(ContentPacks::verdict($output, 'media:header-copy'))->toBe('reuse (forced)')
        ->and(ContentPacks::in($this->siteId, fn () => Media::query()->where('storage_key', $key)->exists()))->toBeTrue();
});

it('puts back the stored copy of a file the site has when it went missing, and records the run', function (): void {
    $before = (int) AuditLog::query()->max('id'); // audit rows outlive the tables a test truncates
    mediaLoad();
    $key = packMedia($this->siteId)['header.png'];
    Storage::disk('r2')->delete($key);

    [$exit, $output] = mediaLoad();

    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'media:header'))->toBe('repair')
        ->and(ContentPacks::verdict($output, 'media:news-1'))->toBe('reuse')
        ->and(Storage::disk('r2')->get($key))->toBe(file_get_contents($this->folder.'/media/header.png'))
        ->and(AuditLog::query()->where('action', 'content_pack.media_applied')->where('id', '>', $before)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'content_pack.media_applied')->where('id', '>', $before)->latest('id')->firstOrFail()->meta)->toMatchArray(['pack' => 'demo-co', 'written' => 1]);
});

it('never resolves a content slot to a file in the Trash', function (): void {
    mediaLoad();
    $key = packMedia($this->siteId)['header.png'];
    ContentPacks::in($this->siteId, fn () => Media::query()->where('storage_key', $key)->firstOrFail()->delete());

    [, $output] = ContentPacks::run();

    expect($output)->toContain('1 with the pack\'s own image')
        ->and($output)->toContain("image slot 'header' is not uploaded yet (run with --media first)");
});

it('uploads over an object that already holds the same bytes (left by a run that failed after its put)', function (): void {
    [, $output] = ContentPacks::run('demo-co', ['--media' => true]);
    preg_match('#(demo/library/header-[0-9a-f]{4}\.png)#', $output, $m);
    Storage::disk('r2')->put($m[1], (string) file_get_contents($this->folder.'/media/header.png'));

    [$exit, $output] = mediaLoad();

    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'media:header'))->toBe('upload')
        ->and(packMedia($this->siteId)['header.png'] ?? null)->toBe($m[1]);
});

it('refuses two slots that would be stored under the same key', function (): void {
    config(['media.url_hash_length' => 0]);
    File::ensureDirectoryExists($this->folder.'/other');
    // Another picture under the same file name (other bytes: a trailer after the PNG's end).
    File::put($this->folder.'/other/header.png', File::get($this->folder.'/media/header.png').'other');
    ContentPacks::edit($this->folder, 'media.json', function (array $media): array {
        $media['slots'][] = ['slot' => 'header-other', 'file' => 'other/header.png'];

        return $media;
    });

    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true]);

    expect($exit)->toBe(1, $output)->and($output)->toContain('would both be stored under');
});

it('refuses to repair a row stored outside the site\'s folder', function (): void {
    $bytes = (string) file_get_contents($this->folder.'/media/header.png');
    ContentPacks::in($this->siteId, fn () => Media::query()->create([
        'collection' => 'library', 'slug' => 'cu', 'hash' => hash('sha256', $bytes),
        'storage_key' => 'cu/library/cu-0000.png', 'mime' => 'image/png', 'size' => strlen($bytes),
    ]));

    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true]);

    expect($exit)->toBe(1, $output)->and($output)->toContain("outside this site's storage folder 'demo/'")
        ->and(Storage::disk('r2')->allFiles())->toBe([]);
});

it('records the files written before a run stopped part-way', function (): void {
    $before = (int) AuditLog::query()->max('id'); // audit rows outlive the tables a test truncates
    [, $output] = ContentPacks::run('demo-co', ['--media' => true]);
    $created = 0;
    Media::creating(function () use (&$created): void {
        if (++$created > 1) {
            throw new RuntimeException('the database went away');
        }
    });

    [$exit, $output] = ContentPacks::run('demo-co', ['--media' => true, '--apply' => true, '--plan' => ContentPacks::fingerprint($output)]);

    expect($exit)->toBe(1, $output)
        ->and($output)->toContain('stopped part-way (1 written)')
        ->and(packMedia($this->siteId))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'content_pack.media_applied')->where('id', '>', $before)->sole()->meta)->toMatchArray(['written' => 1]);
});

it('uploads a document slot as a PDF through the media library', function (): void {
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    File::put($this->folder.'/media/profile.pdf', $pdf);
    ContentPacks::edit($this->folder, 'media.json', function (array $media): array {
        $media['slots'][] = ['slot' => 'profile', 'kind' => 'document', 'file' => 'media/profile.pdf', 'alt' => ['vi' => 'Hồ sơ năng lực']];

        return $media;
    });

    [$exit, $output] = mediaLoad();
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'media:profile'))->toBe('upload');

    $row = ContentPacks::in($this->siteId, fn () => Media::query()->where('original_filename', 'profile.pdf')->firstOrFail());
    expect($row->mime)->toBe('application/pdf')
        ->and($row->storage_key)->toMatch('#^demo/library/profile-[0-9a-f]{4}\.pdf$#')
        ->and($row->hash)->toBe(hash('sha256', $pdf))
        ->and($row->width)->toBeNull()
        ->and($row->alt)->toBe(['vi' => 'Hồ sơ năng lực'])
        ->and(Storage::disk('r2')->get($row->storage_key))->toBe($pdf);
});

/** The pack's company profile as a document slot, with a download button on the about page; returns the PDF's bytes. */
function profileButton(string $folder): string
{
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    File::put($folder.'/media/profile.pdf', $pdf);
    ContentPacks::edit($folder, 'media.json', function (array $media): array {
        $media['slots'][] = ['slot' => 'profile', 'kind' => 'document', 'file' => 'media/profile.pdf'];

        return $media;
    });
    ContentPacks::item($folder, 'pages.json', 'page:/gioi-thieu', function (array $page): array {
        $page['body']['vi'][] = ['type' => 'cta', 'props' => [
            'heading' => 'Hồ sơ năng lực',
            'button_label' => ['text' => 'Tải hồ sơ', 'type_and_size_of' => 'profile'],
            'button_url_do' => ['k' => 'file', 'file' => ['media' => 'profile']],
        ]];

        return $page;
    });

    return $pdf;
}

/** The props of the about page's last block, as stored. */
function aboutLastBlock(int $siteId): array
{
    return ContentPacks::in($siteId, function (): array {
        $entry = Entry::query()->whereKey(SiteRoute::query()->where('path', '/gioi-thieu')->value('entry_id'))->firstOrFail();
        $blocks = $entry->translationFor('vi')?->body['blocks'] ?? [];

        return end($blocks)['props'] ?? [];
    });
}

it('gives a page button the uploaded document and its label the file\'s type and size', function (): void {
    profileButton($this->folder);
    [$exit, $output] = mediaLoad();
    expect($exit)->toBe(0, $output);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    $key = ContentPacks::in($this->siteId, fn () => Media::query()->where('original_filename', 'profile.pdf')->value('storage_key'));
    expect(aboutLastBlock($this->siteId))->toMatchArray([
        'button_label' => 'Tải hồ sơ (PDF · 1 KB)',
        'button_url_do' => ['k' => 'file', 'file' => $key],
    ]);

    $html = $this->get('http://'.ContentPacks::HOST.'/gioi-thieu')->assertOk()->getContent();
    expect($html)->toMatch('#<a [^>]*href="[^"]*'.preg_quote((string) $key, '#').'"[^>]*download[^>]*>.*?Tải hồ sơ \(PDF · 1 KB\)#s');
});

it('leaves the download button out, with a warning, while its document is not uploaded', function (): void {
    profileButton($this->folder);
    [$exit, $plan] = ContentPacks::run();
    expect($exit)->toBe(0, $plan)
        ->and($plan)->toContain("page:/gioi-thieu: document slot 'profile' is not uploaded yet (run with --media first); the download button is left out.");

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(aboutLastBlock($this->siteId))->toMatchArray(['button_label' => 'Tải hồ sơ'])->not->toHaveKey('button_url_do');
});

it('leaves the download button out when its document slot names an existing image', function (): void {
    profileButton($this->folder);
    $image = ContentPacks::in($this->siteId, fn (): Media => StorefrontCards::media('brochure'));
    ContentPacks::edit($this->folder, 'media.json', function (array $media) use ($image): array {
        $media['slots'] = array_map(fn (array $slot): array => $slot['slot'] === 'profile'
            ? ['slot' => 'profile', 'kind' => 'document', 'legacy_key' => $image->storage_key]
            : $slot, $media['slots']);

        return $media;
    });

    [$exit, $plan] = ContentPacks::run();
    expect($exit)->toBe(0, $plan)
        ->and($plan)->toContain("page:/gioi-thieu: document slot 'profile' names a file that is not a document; the download button is left out.");

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(aboutLastBlock($this->siteId))->toMatchArray(['button_label' => 'Tải hồ sơ'])->not->toHaveKey('button_url_do');
});
