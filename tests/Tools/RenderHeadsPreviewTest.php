<?php

/*
 * Storefront preview renderer — a TOOL, not a test (outside the configured suites). Loads the An
 * Vinh content pack on an ephemeral test-database site, adds the floating contact setting the
 * design shows, and writes a few pages to storage/app/sf-preview as static HTML (fc.json there = the floating_contact value) with the real built assets.
 */

use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

afterEach(fn () => ContentPacks::cleanup());

it('renders An Vinh pages for screenshots', function (): void {
    if (! is_file(public_path('build/manifest.json'))) {
        $this->markTestSkipped('build the front end first');
    }
    $this->withVite();
    $folder = ContentPacks::use('an-vinh', database_path('content-packs'));
    ContentPacks::edit($folder, 'pack.json', fn (array $pack): array => ['hosts' => [ContentPacks::HOST]] + $pack);
    $siteId = StorefrontCards::site('Demo', ContentPacks::HOST);
    // Real photos: a media row per image slot (hash of the pack file), shown through file:// after rendering.
    $packDir = database_path('content-packs/an-vinh');
    app(Tenancy::class)->enter($siteId, function () use ($packDir): void {
        foreach (json_decode((string) file_get_contents($packDir.'/media.json'), true)['slots'] as $slot) {
            if (! str_ends_with((string) ($slot['file'] ?? ''), '.webp')) {
                continue;
            }
            $hash = hash_file('sha256', $packDir.'/'.$slot['file']);
            Media::query()->create([
                'collection' => $slot['collection'] ?? 'library', 'slug' => $slot['slot'], 'hash' => $hash,
                'storage_key' => 's/library/'.$slot['slot'].'.webp', 'mime' => 'image/webp', 'size' => 50_000, 'width' => 1600, 'height' => 900,
            ]);
        }
    });
    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output);

    $dir = storage_path('app/sf-preview');
    $fc = is_file($dir.'/fc.json') ? json_decode((string) file_get_contents($dir.'/fc.json'), true) : null;
    if (is_array($fc)) {
        app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(
            ['group_name' => 'storefront', 'key' => 'floating_contact'], ['value' => $fc],
        ));
    }

    @mkdir($dir, 0775, true);
    $pages = ['san_pham' => '/san-pham', 'du_an' => '/du-an', 'tin_tuc' => '/tin-tuc', 'tuyen_dung' => '/tuyen-dung', 'nang_luc' => '/nang-luc',
        'gioi_thieu' => '/gioi-thieu', 'nha_xuong' => '/nha-xuong-may-moc', 'doi_tac' => '/doi-tac', 'chinh_sach' => '/chinh-sach-bao-mat',
        'cam_on' => '/cam-on', 'lien_he' => '/lien-he', 'dich_vu' => '/gia-cong-phay-tien-cnc', 'tin_ct' => '/cong-nghe-in-3d-kim-loai',
        'du_an_ct' => '/he-thong-cap-phoi-tu-dong-hutchinson', 'home' => '/'];
    foreach ($pages as $name => $path) {
        $html = $this->get('http://'.ContentPacks::HOST.$path)->getContent();
        $html = str_replace('http://'.ContentPacks::HOST, '', (string) $html);
        $html = preg_replace('~https://files\.test/s/library/([a-z0-9-]+)\.webp(\?[^"\s,]*)?~', 'file://'.$packDir.'/media/$1.webp', $html);
        file_put_contents($dir.'/'.$name.'.html', $html);
    }
});
