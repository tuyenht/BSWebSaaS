<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Admin\Support\Settings\BranchList;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The media library's «Người tạo» and «Thời gian» filters, the «Thư mục» group of the Filter sheet
 * (while the folder column is hidden) and the «Dùng ở N nơi» chip of each file (design board 12).
 * Uploaders are named by ulid, folders by slug; every count stays inside the site.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
});

/** @param  array<string, mixed>  $attrs */
function mfFile(int $site, string $name, array $attrs = [], ?CarbonImmutable $at = null): Media
{
    return app(Tenancy::class)->enter($site, function () use ($site, $name, $attrs, $at): Media {
        $file = Media::query()->create(array_merge([
            'collection' => 'library',
            'slug' => pathinfo($name, PATHINFO_FILENAME),
            'hash' => hash('sha256', $site.$name),
            'storage_key' => "s{$site}/library/{$name}",
            'original_filename' => $name,
            'mime' => 'image/png',
            'size' => 2048,
            'width' => 800,
            'height' => 600,
        ], $attrs));
        if ($at !== null) {
            Media::query()->whereKey($file->id)->update(['created_at' => $at]);
        }

        return $file;
    });
}

function mfMember(string $role, string $name, int $site = 1): User
{
    $spec = PermissionRegistry::tenantRoleTemplates('corporate')[$role]['permissions'];
    $user = User::factory()->create(['name' => $name]);
    app(Tenancy::class)->enter($site, fn () => grantRole($user, $role, $spec === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $spec));

    return $user->fresh();
}

it('offers «Người tạo» from two uploaders on, the member first, and filters by ulid', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $other = mfMember('editor', 'An Khang');
    mfFile(1, 'cua-toi.png', ['created_by' => $me->id]);
    mfFile(1, 'cua-khang-1.png', ['created_by' => $other->id]);
    mfFile(1, 'cua-khang-2.png', ['created_by' => $other->id]);

    $html = $this->actingAs($me)->get('http://a.test/admin/media')->assertOk()->getContent();
    expect($html)->toContain(e(__('media.lib.f_by')), 'value="'.$other->ulid.'"', e(__('entries.f_mine', ['name' => 'Bảo Ngọc'])))
        ->and($html)->not->toContain('value="'.$other->id.'"');
    // the member comes first, then the others by name
    expect(strpos($html, e(__('entries.f_mine', ['name' => 'Bảo Ngọc']))))->toBeLessThan(strpos($html, '>An Khang<'));

    app(Tenancy::class)->enter(1, function () use ($me, $other): void {
        Livewire::actingAs($me)->test(Library::class)
            ->set('by', [$other->ulid])
            ->assertSee('cua-khang-1')->assertSee('cua-khang-2')->assertDontSee('cua-toi')
            ->call('clearBy')
            ->assertSet('by', [])
            ->assertSee('cua-toi');
    });
});

it('hides «Người tạo» while one person uploaded everything', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    mfFile(1, 'a.png', ['created_by' => $me->id]);
    mfFile(1, 'b.png', ['created_by' => $me->id]);

    $this->actingAs($me)->get('http://a.test/admin/media')->assertOk()
        ->assertDontSee('data-fsheet="by"', false);
});

it('never lets another site’s member be chosen or counted as an uploader', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $stranger = mfMember('admin', 'Người lạ', 2);
    mfFile(1, 'cua-toi.png', ['created_by' => $me->id]);
    mfFile(2, 'cua-site-b.png', ['created_by' => $stranger->id]);

    $html = $this->actingAs($me)->get('http://a.test/admin/media')->assertOk()->getContent();
    expect($html)->not->toContain($stranger->ulid)->not->toContain('Người lạ');

    app(Tenancy::class)->enter(1, function () use ($me, $stranger): void {
        // a ulid of no uploader here matches nothing rather than reaching the other site's files
        Livewire::actingAs($me)->test(Library::class)
            ->set('by', [$stranger->ulid])
            ->assertDontSee('cua-site-b')
            ->assertSee('cua-toi');
    });
});

it('filters «Thời gian» by the upload day of the site, with each choice counted', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $zone = app(Tenancy::class)->enter(1, fn (): string => app(SiteTime::class)->zone());
    $now = CarbonImmutable::now($zone);
    mfFile(1, 'hom-nay.png', [], $now);
    mfFile(1, 'tuan-nay.png', [], $now->subDays(5));
    mfFile(1, 'thang-truoc.png', [], $now->subDays(40));
    mfFile(1, 'nam-ngoai.png', [], $now->subDays(200));

    app(Tenancy::class)->enter(1, function () use ($me, $now): void {
        $page = Livewire::actingAs($me)->test(Library::class);
        expect($page->viewData('whenCounts'))->toBe(['' => 4, 'today' => 1, '7' => 2, '30' => 2, '90' => 3, 'range' => 4]);

        $page->set('when', 'today')->assertSee('hom-nay')->assertDontSee('tuan-nay');
        $page->set('when', '90')->assertSee('thang-truoc')->assertDontSee('nam-ngoai');

        // a day typed in turns «Tùy chọn» on; either end may stay open
        $page->set('from', $now->subDays(6)->format('Y-m-d'))
            ->assertSet('when', 'range')
            ->assertSee('tuan-nay')->assertSee('hom-nay')->assertDontSee('thang-truoc');
        $page->set('to', $now->subDays(1)->format('Y-m-d'))->assertSee('tuan-nay')->assertDontSee('hom-nay');
        expect($page->viewData('whenCounts')['range'])->toBe(1);

        // another choice drops the dates; nonsense never reaches the query
        $page->set('when', '30')->assertSet('from', '')->assertSet('to', '');
        $page->set('when', 'mai')->assertSet('when', '');
        $page->set('when', 'range')->set('from', '2026-02-31')->assertSet('from', '');

        $page->set('when', '7')->set('by', [$me->ulid])->call('clearFilters')
            ->assertSet('when', '')->assertSet('by', []);
    });
});

it('chooses the folder from the Filter sheet while the folder column is hidden', function (): void {
    $admin = mfMember('admin', 'Bảo Ngọc');
    $author = mfMember('author', 'An Khang');
    app(Tenancy::class)->enter(1, fn () => MediaFolder::query()->create(['name' => 'Ảnh xưởng', 'slug' => 'anh-xuong', 'position' => 1]));
    mfFile(1, 'a.png');

    $html = $this->actingAs($admin)->get('http://a.test/admin/media')->assertOk()->getContent();
    expect($html)->toContain('wire:model.live="folderPick"', 'data-fsheet="folder"', 'value="anh-xuong"', e(__('media.lib.manage_folders')))
        ->and($html)->not->toContain('id="media-fsel"');
    $this->actingAs($author)->get('http://a.test/admin/media')->assertOk()
        ->assertSee('wire:model.live="folderPick"', false)
        ->assertDontSee(__('media.lib.manage_folders'));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(Library::class)
            ->set('folderPick', 'anh-xuong')
            ->assertSet('folder', 'anh-xuong')
            ->assertSee(__('media.lib.chip_rm_folder', ['name' => 'Ảnh xưởng']));
        $page->set('folderPick', '~trash')->assertSet('status', 'trash')->assertSet('folder', '')->assertSet('folderPick', '~trash');
        $page->set('folderPick', '')->assertSet('status', 'live');
    });
});

it('says on each file where it is used, as its detail panel counts it, and nothing of another site', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $gallery = mfFile(1, 'gallery.png');
    $cover = mfFile(1, 'cover.png');
    $brand = mfFile(1, 'brand.png');
    $blocks = mfFile(1, 'blocks.png');
    $unused = mfFile(1, 'unused.png');
    $theirs = mfFile(2, 'theirs.png');

    app(Tenancy::class)->enter(1, function () use ($gallery, $cover, $brand, $blocks): void {
        foreach ([1, 2] as $i) {
            $product = Product::query()->create(['status' => 'published']);
            ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $gallery->id, 'position' => $i]);
        }
        Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => ['image_url' => $cover->storage_key]]);
        Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'cover_media_id' => $cover->id]);
        $profile = SiteProfile::query()->first() ?? SiteProfile::query()->create(['brand_name' => 'An Vinh']);
        $profile->update(['logo_media_id' => $brand->id, 'og_image_media_id' => $brand->id]);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'meta' => []]);
        $setting = SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => []]);
        Mediable::query()->create(['media_id' => $blocks->id, 'mediable_type' => 'entry', 'mediable_id' => $entry->id, 'collection' => BlockMediaUsage::COLLECTION]);
        Mediable::query()->create(['media_id' => $blocks->id, 'mediable_type' => 'site_setting', 'mediable_id' => $setting->id, 'collection' => BlockMediaUsage::COLLECTION]);
        Mediable::query()->create(['media_id' => $blocks->id, 'mediable_type' => 'site_setting', 'mediable_id' => $setting->id, 'collection' => BranchList::COLLECTION]);
    });
    // the other site uses its own file, and names this site's id: neither counts here
    app(Tenancy::class)->enter(2, function () use ($theirs, $gallery): void {
        $product = Product::query()->create(['status' => 'published']);
        ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $theirs->id, 'position' => 0]);
        Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'cover_media_id' => $gallery->id]);
    });

    app(Tenancy::class)->enter(1, function () use ($me, $gallery, $cover, $brand, $blocks, $unused): void {
        $locator = app(MediaUsageLocator::class);
        $files = Media::query()->whereIn('id', [$gallery->id, $cover->id, $brand->id, $blocks->id, $unused->id])->get();
        $counts = $locator->countsFor($files);
        foreach ($files as $file) {
            expect($counts[$file->id])->toBe(count($locator->usagesFor($file, 'vi')), $file->original_filename);
        }
        expect($counts)->toBe([$gallery->id => 2, $cover->id => 2, $brand->id => 2, $blocks->id => 3, $unused->id => 0]);

        $page = Livewire::actingAs($me)->test(Library::class);
        $page->assertSee(__('media.lib.use_n', ['n' => 3]))->assertSee(__('media.lib.use_unused'));
        $page->call('setView', 'list')->assertSee(__('media.lib.col_use'))->assertSee(__('media.lib.use_n', ['n' => 2]));
    });
});

it('keeps the filters and the use chips on a flat number of queries', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $other = mfMember('editor', 'An Khang');
    $first = mfFile(1, 'dau.png', ['created_by' => $me->id]);
    mfFile(1, 'hai.png', ['created_by' => $other->id]);
    app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'cover_media_id' => $first->id]));
    $this->actingAs($me)->get('http://a.test/admin/media')->assertOk(); // warm the per-process caches first
    $one = countQueries(fn () => $this->actingAs($me)->get('http://a.test/admin/media')->assertOk());

    foreach (range(1, 20) as $i) {
        $file = mfFile(1, "them-{$i}.png", ['created_by' => $i % 2 === 0 ? $me->id : $other->id]);
        app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => $i, 'cover_media_id' => $file->id]));
    }
    $many = countQueries(fn () => $this->actingAs($me)->get('http://a.test/admin/media')->assertOk());

    expect($many)->toBe($one)->and($many)->toBeLessThanOrEqual(30);
});
