<?php

/*
 * Storefront preview renderer — a TOOL, not a test (outside the configured suites). Loads the An
 * Vinh content pack on an ephemeral test-database site, adds the floating contact setting the
 * design shows, a product category tree for the phone menu's "Sản phẩm" tab, and writes a few pages to storage/app/sf-preview as static HTML (fc.json there = the floating_contact value) with the real built assets.
 */

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteModule;
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

    // The phone menu's "Sản phẩm" tab needs a product tree to show: on this ephemeral site only, switch the catalog
    // on and add twelve groups under the products section (one with a very long name, the first two with children and
    // grandchildren), each with its route through the normal create path. The pack's own categories stay.
    app(Tenancy::class)->enter($siteId, function (): void {
        SiteModule::query()->updateOrCreate(['module_key' => 'catalog'], ['enabled' => true]);
        // The pack has the "Sản phẩm" category but no products section pointing at it: this site gets one.
        $root = (int) ContentSection::query()->where('key', 'products')->value('taxonomy_id')
            ?: (int) Taxonomy::query()->whereHas('translations', fn ($t) => $t->where('slug', 'san-pham'))->value('id');
        ContentSection::query()->updateOrCreate(['key' => 'products'], ['label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $root]);
        $add = function (string $name, string $slug, int $parent, int $position): int {
            $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $parent, 'position' => $position]);
            $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);
            app(TaxonomyPaths::class)->refreshBranch($category->id);
            app(RouteSync::class)->syncTaxonomy($category, 'vi', $slug);

            return $category->id;
        };
        $long = 'Máy tính xách tay, máy tính bảng và thiết bị văn phòng dùng cho doanh nghiệp vừa và nhỏ';
        foreach (range(1, 12) as $i) {
            $id = $add($i === 1 ? $long : "Nhóm hàng {$i}", "nhom-hang-{$i}", $root, 10 + $i);
            if ($i <= 2) {
                foreach (range(1, 3) as $j) {
                    $child = $add("Dòng {$i}.{$j}", "dong-{$i}-{$j}", $id, $j);
                    if ($j === 1) {
                        $add("Mẫu {$i}.{$j}.1 có tên khá dài để thử xuống dòng", "mau-{$i}-{$j}-1", $child, 1);
                        $add("Mẫu {$i}.{$j}.2", "mau-{$i}-{$j}-2", $child, 2);
                    }
                }
            }
        }
    });

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
        'du_an_ct' => '/he-thong-cap-phoi-tu-dong-hutchinson', 'home' => '/',
        'nhom_san_pham' => '/mau-1-1-1'];   // a product category page: the drawer opens on its "Sản phẩm" tab
    foreach ($pages as $name => $path) {
        $html = $this->get('http://'.ContentPacks::HOST.$path)->getContent();
        $html = str_replace('http://'.ContentPacks::HOST, '', (string) $html);
        $html = preg_replace('~https://files\.test/s/library/([a-z0-9-]+)\.webp(\?[^"\s,]*)?~', 'file://'.$packDir.'/media/$1.webp', $html);
        file_put_contents($dir.'/'.$name.'.html', $html);
    }
});
