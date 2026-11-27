<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Admin\Support\MediaLibraryDirectory;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            // in UTC: a query-builder write prints the time without its zone, so a site-zone time would land 7 hours late
            Media::query()->whereKey($file->id)->update(['created_at' => $at->utc()]);
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

    // the page offers the group; its people and counts are read once the filters open
    $html = $this->actingAs($me)->get('http://a.test/admin/media')->assertOk()->getContent();
    expect($html)->toContain(e(__('media.lib.f_by')), 'data-fsheet="by"')
        ->and($html)->not->toContain('value="'.$other->ulid.'"');

    app(Tenancy::class)->enter(1, function () use ($me, $other): void {
        $open = Livewire::actingAs($me)->test(Library::class)->set('tally', true);
        $html = $open->html();
        expect($html)->toContain('value="'.$other->ulid.'"', e(__('entries.f_mine', ['name' => 'Bảo Ngọc'])))
            ->and($html)->not->toContain('value="'.$other->id.'"');
        // the member comes first, then the others by name, each counted
        expect(strpos($html, e(__('entries.f_mine', ['name' => 'Bảo Ngọc']))))->toBeLessThan(strpos($html, '>An Khang<'));
        expect(array_column($open->viewData('uploaders'), 'count', 'value'))->toBe([$me->ulid => 1, $other->ulid => 2]);

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
            ->assertSet('by', [])
            ->assertDontSee('cua-site-b')
            ->assertDontSee('Người lạ')
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
        $page = Livewire::actingAs($me)->test(Library::class)->set('tally', true);
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
    // the other site uses its own file, which does not count here; it cannot name this site's id
    // (the same-site key refuses it)
    app(Tenancy::class)->enter(2, function () use ($theirs, $gallery): void {
        $product = Product::query()->create(['status' => 'published']);
        ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $theirs->id, 'position' => 0]);
        expect(fn () => DB::transaction(fn () => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'cover_media_id' => $gallery->id])))
            ->toThrow(QueryException::class, 'taxonomies_cover_media_id_same_site_foreign');
    });

    app(Tenancy::class)->enter(1, function () use ($me, $gallery, $cover, $brand, $blocks, $unused): void {
        $locator = app(MediaUsageLocator::class);
        $files = Media::query()->whereIn('id', [$gallery->id, $cover->id, $brand->id, $blocks->id, $unused->id])->get();
        $counts = $locator->countsFor($files);
        // as the drawer lists them, the post's featured image too (read from its "in use" row)
        foreach ($files as $file) {
            expect($counts[$file->id])->toBe(count($locator->usagesFor($file, 'vi')), $file->original_filename);
        }
        // read by key: the order of the map follows the planner's rows, not the files'
        expect(collect($counts)->sortKeys()->all())->toBe([$gallery->id => 2, $cover->id => 2, $brand->id => 2, $blocks->id => 3, $unused->id => 0])
            ->and(count($locator->usagesFor($cover, 'vi')))->toBe(2);

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
    // with the filters open: the uploaders, their counts and the «Thời gian» counts on top, one statement each
    $openOf = fn (): int => app(Tenancy::class)->enter(1, function () use ($me): int {
        $page = Livewire::actingAs($me)->test(Library::class);

        return countQueries(fn () => $page->set('tally', true));
    });
    $openOne = $openOf();

    foreach (range(1, 20) as $i) {
        $file = mfFile(1, "them-{$i}.png", ['created_by' => $i % 2 === 0 ? $me->id : $other->id]);
        app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => $i, 'cover_media_id' => $file->id]));
    }
    $many = countQueries(fn () => $this->actingAs($me)->get('http://a.test/admin/media')->assertOk());

    expect($many)->toBe($one)->and($many)->toBeLessThanOrEqual(30)
        ->and($openOf())->toBe($openOne);
});

/**
 * The statements a callback runs, as SQL text.
 *
 * @return list<string>
 */
function mfSql(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();

        return array_map(fn (array $q): string => strtolower((string) $q['query']), DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

it('reads the groups’ own counts only while the filters are open, never on a page turn or a drawer', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $other = mfMember('editor', 'An Khang');
    foreach (range(1, 25) as $i) {
        mfFile(1, "f-{$i}.png", ['created_by' => $i === 1 ? $me->id : $other->id]);
    }
    $groupCounts = fn (array $sql): array => array_values(array_filter($sql, fn (string $q): bool => str_contains($q, 'grouping(created_by)')
        || str_contains($q, 'group by "created_by"') || str_contains($q, 'count(*) filter (where created_at')));

    app(Tenancy::class)->enter(1, function () use ($me, $groupCounts): void {
        $page = Livewire::actingAs($me)->test(Library::class);
        // a page turn: «Người tạo» is still offered (the counts statement says two people uploaded), with no count read
        expect($groupCounts(mfSql(fn () => $page->call('older', $page->viewData('olderUlid')))))->toBe([])
            ->and($page->get('pageNo'))->toBe(2)
            ->and($page->viewData('manyUploaders'))->toBeTrue()
            ->and($page->viewData('whenCounts'))->toBe([])
            ->and($page->html())->toContain('data-fsheet="by"');
        $first = $page->viewData('rows')->first();
        expect($groupCounts(mfSql(fn () => $page->call('openDetail', $first->ulid))))->toBe([]);
        $page->call('closeDetail');

        // the Filter sheet or a panel opens: the uploaders, their counts and the «Thời gian» counts, once each
        $open = $groupCounts(mfSql(fn () => $page->set('tally', true)));
        expect($open)->toHaveCount(3)
            ->and(array_sum(array_column($page->viewData('uploaders'), 'count')))->toBe(25)
            ->and($page->viewData('whenCounts')[''])->toBe(25);
        // and closed again (the browser drops the flag with the next request): no count any more
        expect($groupCounts(mfSql(fn () => $page->set('tally', false))))->toBe([]);
    });
});

it('counts the use chips from the page’s own media ids, a featured image too, never reading the entries’ meta', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $file = mfFile(1, 'anh.png');
    app(Tenancy::class)->enter(1, fn () => Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => ['image_url' => $file->storage_key]]));

    app(Tenancy::class)->enter(1, function () use ($me, $file): void {
        $files = Media::query()->whereKey($file->id)->get();
        $sql = mfSql(fn () => expect(app(MediaUsageLocator::class)->countsFor($files))->toBe([$file->id => 1]));
        expect($sql)->toHaveCount(1)
            ->and($sql[0])->not->toContain('image_url')->not->toContain('"entries"."meta"');

        $page = Livewire::actingAs($me)->test(Library::class);
        expect(implode(' ', mfSql(fn () => $page->call('setSort', 'old'))))->not->toContain('image_url');
        // the drawer still names the post that shows it
        expect(count(app(MediaUsageLocator::class)->usagesFor($file, 'vi')))->toBe(1);
    });
});

it('says «Chưa dùng» on the tile, in the filter and in the drawer alike for a file only used in the Trash', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $byProduct = mfFile(1, 'cua-san-pham-da-xoa.png');
    $byPost = mfFile(1, 'cua-bai-da-xoa.png');
    $live = mfFile(1, 'dang-dung.png');

    app(Tenancy::class)->enter(1, function () use ($byProduct, $byPost, $live): void {
        $gone = Product::query()->create(['status' => 'published']);
        ProductImage::query()->create(['product_id' => $gone->id, 'media_id' => $byProduct->id, 'position' => 0]);
        $gone->delete();
        $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'meta' => []]);
        Mediable::query()->create(['media_id' => $byPost->id, 'mediable_type' => 'entry', 'mediable_id' => $post->id, 'collection' => BlockMediaUsage::COLLECTION]);
        $post->delete();
        $kept = Product::query()->create(['status' => 'published']);
        ProductImage::query()->create(['product_id' => $kept->id, 'media_id' => $live->id, 'position' => 0]);
    });

    app(Tenancy::class)->enter(1, function () use ($me, $byProduct, $byPost, $live): void {
        $locator = app(MediaUsageLocator::class);
        expect(collect($locator->countsFor(Media::query()->whereIn('id', [$byProduct->id, $byPost->id, $live->id])->get()))->sortKeys()->all())
            ->toBe([$byProduct->id => 0, $byPost->id => 0, $live->id => 1])
            ->and($locator->usagesFor($byProduct, 'vi'))->toBe([])
            ->and($locator->usagesFor($byPost, 'vi'))->toBe([]);

        Livewire::actingAs($me)->test(Library::class)
            ->set('uses', ['unused'])->assertSee('cua-san-pham-da-xoa')->assertSee('cua-bai-da-xoa')->assertDontSee('dang-dung')
            ->set('uses', ['used'])->assertDontSee('cua-san-pham-da-xoa')->assertDontSee('cua-bai-da-xoa')->assertSee('dang-dung');

        // who may send a file to the Trash still reads it as used: restoring that product finds its picture
        foreach ([$byProduct, $byPost] as $file) {
            $kept = Media::query()->whereKey($file->id);
            $locator->constrain($kept, false);
            expect($kept->exists())->toBeFalse($file->original_filename);
        }
    });
});

it('drops a chosen uploader nobody here is, swaps a reversed range and shows the upload day by the site’s calendar', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $other = mfMember('editor', 'An Khang');
    $nobody = User::factory()->create(['name' => 'Chưa tải gì']);
    // 20:30 UTC on 10/03 is already 11/03 in the site's own zone (Asia/Ho_Chi_Minh)
    mfFile(1, 'dem-khuya.png', ['created_by' => $me->id], CarbonImmutable::parse('2026-03-10 20:30:00', 'UTC'));
    mfFile(1, 'cua-khang.png', ['created_by' => $other->id], CarbonImmutable::parse('2026-03-01 08:00:00', 'UTC'));

    app(Tenancy::class)->enter(1, function () use ($me, $nobody): void {
        expect(app(SiteTime::class)->zone())->toBe('Asia/Ho_Chi_Minh');
        $page = Livewire::actingAs($me)->test(Library::class)
            // a member of no file here (and a made-up ulid) is no condition: no «Lọc 1», nothing hidden
            ->set('by', [$nobody->ulid, (string) Str::ulid()])
            ->assertSet('by', [])
            ->assertSee('cua-khang')
            ->assertDontSee('Chưa tải gì');
        expect($page->html())->not->toContain('data-lf-count');

        // the list shows the upload day in the site's calendar: 11/03, the day «Thời gian» files it under
        $page->call('setView', 'list')->assertSee('11/03/2026')->assertDontSee('10/03/2026');
        $page->set('when', 'range')->set('from', '2026-03-11')->set('to', '2026-03-11')->assertSee('dem-khuya')->assertDontSee('cua-khang');

        // «Từ» after «Đến»: the two swap and the days between are listed
        $page->set('to', '2026-03-01')
            ->assertSet('from', '2026-03-01')->assertSet('to', '2026-03-11')
            ->assertSee('dem-khuya')->assertSee('cua-khang');
        expect(MediaLibraryDirectory::window('range', '2026-03-11', '2026-03-01'))->toEqual(MediaLibraryDirectory::window('range', '2026-03-01', '2026-03-11'));
    });
});

it('lays each list row out on two lines while the folder column sits beside a files area of 600–899, as board 12', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    mfFile(1, 'anh.png');
    $band = '@min-[48rem]/tv:@min-[37.5rem]/tvm:@max-[56.25rem]/tvm:';

    $html = app(Tenancy::class)->enter(1, fn (): string => Livewire::actingAs($me)->test(Library::class)->call('setView', 'list')->html());
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    foreach (['//thead/tr/th', '//tbody/tr/td'] as $cells) {
        $row = $xpath->query($cells.'/..')->item(0);
        expect($row?->attributes?->getNamedItem('class')?->nodeValue)->toContain($band.'grid')
            ->toContain($band.'grid-cols-[2.75rem_2.75rem_repeat(3,minmax(0,1fr))_max-content_minmax(0,1fr)_auto]');
        $areas = [];
        foreach ($xpath->query($cells) as $cell) {
            preg_match('#'.preg_quote($band, '#').'\[grid-area:([0-9/]+)\]#', (string) $cell->attributes?->getNamedItem('class')?->nodeValue, $m);
            $areas[] = $m[1] ?? '';
        }
        // tick, picture | the name across line 1 | type, dimensions, size, where used, upload day on line 2 | «Chi tiết»
        expect($areas)->toBe(['1/1/3/2', '1/2/3/3', '1/3/2/8', '2/3/3/4', '2/4/3/5', '2/5/3/6', '2/6/3/7', '2/7/3/8', '1/8/3/9']);
    }
    // the dimensions and the upload day come back as columns there, so the name's sub line stops carrying them
    $dims = $xpath->query('//tbody/tr/td')->item(4);
    expect((string) $dims?->attributes?->getNamedItem('class')?->nodeValue)->toContain($band.'block')
        ->and($html)->toContain('@min-[56.25rem]/tvm:hidden '.$band.'hidden!');
});

it('counts each «Loại tệp», «Định dạng» and «Tình trạng» choice with every other condition on, only while the filters are open', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    $folder = app(Tenancy::class)->enter(1, fn () => MediaFolder::query()->create(['name' => 'Công trình', 'slug' => 'cong-trinh', 'position' => 1]));
    $used = mfFile(1, 'dung.png', ['folder_id' => $folder->id]);
    mfFile(1, 'chua-dung.jpg', ['mime' => 'image/jpeg', 'folder_id' => $folder->id]);
    mfFile(1, 'bang-gia.pdf', ['mime' => 'application/pdf', 'width' => null, 'height' => null, 'folder_id' => $folder->id]);
    mfFile(1, 'ngoai.webp', ['mime' => 'image/webp']);
    $binned = mfFile(1, 'da-xoa.gif', ['mime' => 'image/gif']);
    app(Tenancy::class)->enter(1, fn () => $binned->delete());
    mfFile(2, 'cua-site-b.png', ['mime' => 'application/pdf']);
    app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'cover_media_id' => $used->id]));

    app(Tenancy::class)->enter(1, function () use ($me): void {
        $page = Livewire::actingAs($me)->test(Library::class);
        // closed: no counts read
        expect($page->viewData('groupCounts'))->toBe([]);

        $page->set('tally', true);
        expect($page->viewData('groupCounts'))->toBe([
            'kinds' => ['img' => 3, 'pdf' => 1],
            'formats' => ['jpg' => 1, 'png' => 1, 'webp' => 1, 'gif' => 0, 'pdf' => 1],
            'uses' => ['used' => 1, 'unused' => 3],
        ]);

        // in a folder, with a format chosen: «Định dạng» still counts every format there, the other groups only the PNG
        $page->call('setFolder', 'cong-trinh')->set('formats', ['png']);
        expect($page->viewData('groupCounts'))->toBe([
            'kinds' => ['img' => 1, 'pdf' => 0],
            'formats' => ['jpg' => 1, 'png' => 1, 'webp' => 0, 'gif' => 0, 'pdf' => 1],
            'uses' => ['used' => 1, 'unused' => 0],
        ]);
        // shown beside each choice in the Filter sheet
        $countAfter = fn (string $label, string $n): string => '/>'.preg_quote(e($label), '/').'<\/span>\s*(?:<!--.*?-->\s*)*<span class="shrink-0 text-small font-normal text-text-muted">'.$n.'<\/span>/';
        expect($page->html())->toMatch($countAfter(__('media.lib.kind_pdf'), '0'))
            ->toMatch($countAfter('JPG', '1'))
            ->toMatch($countAfter(__('media.lib.use_used'), '1'));

        // the Trash counts its own files and has no «Tình trạng»
        $page->set('formats', [])->call('setFolder', '~trash');
        expect($page->viewData('groupCounts'))->toBe([
            'kinds' => ['img' => 1, 'pdf' => 0],
            'formats' => ['jpg' => 0, 'png' => 0, 'webp' => 0, 'gif' => 1, 'pdf' => 0],
        ]);

        $page->set('tally', false);
        expect($page->viewData('groupCounts'))->toBe([]);
    });
});

it('reads the type, format and use counts in one statement each, and none while the filters are shut', function (): void {
    $me = mfMember('admin', 'Bảo Ngọc');
    foreach (range(1, 30) as $i) {
        mfFile(1, "f-{$i}.png", ['mime' => $i % 3 === 0 ? 'application/pdf' : 'image/png']);
    }
    $ours = fn (array $sql): array => array_values(array_filter($sql, fn (string $q): bool => str_contains($q, "count(*) filter (where mime <> 'application/pdf')")
        || str_contains($q, 'count(*) filter (where mime in (') || str_starts_with($q, 'select count(*) as n, (select count(*) from "media"')));

    app(Tenancy::class)->enter(1, function () use ($me, $ours): void {
        $page = Livewire::actingAs($me)->test(Library::class);
        expect($ours(mfSql(fn () => $page->call('setView', 'list'))))->toBe([]);
        expect($ours(mfSql(fn () => $page->set('tally', true))))->toHaveCount(3);
        expect($ours(mfSql(fn () => $page->call('older', (string) $page->viewData('olderUlid')))))->toHaveCount(3); // still open
        expect($ours(mfSql(fn () => $page->set('tally', false))))->toBe([]);
    });
});
