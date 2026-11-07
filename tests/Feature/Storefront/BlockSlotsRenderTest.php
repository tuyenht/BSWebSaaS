<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Validation\LayoutSlotInput;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SectionComponent;
use App\Modules\Theme\Rendering\ArticleToc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Tests\Fixtures\StorefrontCards;

/*
 * The blocks a site puts around its posts, lists and products (the block slots): the side column of
 * a post (the table of contents the layout builds, the newest posts of the root branch, the
 * categories, the consult box) and the bands at the end of a post, a list and a product. With no
 * slot set a page is the page it always was; an override beats the site's default, "none" hides, a
 * language without blocks takes the default language's; and every block is drawn in the page's one
 * render pass, so the lookups do not grow per block.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** The site the pinned post and product pages were captured on. */
function slotsGoldenSite(): void
{
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $photo = StorefrontCards::media('anh', 960, 720);
        $news = StorefrontCards::news();
        foreach (range(1, 6) as $n) {
            StorefrontCards::post($news, "Ống thép số {$n}", "ong-thep-{$n}", CarbonImmutable::parse('2026-09-01 08:00', 'UTC')->addDays($n), ['image_url' => $photo->storage_key], 'Tóm tắt ngắn.');
        }
        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham');
        StorefrontCards::catalog($catalog);
        StorefrontCards::product('Đồ gá kiểm', 'do-ga-kiem', $catalog, $photo, CarbonImmutable::parse('2026-10-02 08:00', 'UTC'), 'Đồ gá kiểm tra.');
        StorefrontCards::product('Khuôn dập', 'khuon-dap', $catalog, $photo, CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));
    });
}

/** Start the next request as Octane does: no scoped instance and no cached controller carried over. */
function slotsFresh(): void
{
    app()->forgetScopedInstances();
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
}

/** A page with the current year masked (the footer prints it). */
function slotsHtml(string $path, string $host = 'mau.platform.test'): string
{
    return str_replace(date('Y'), '{year}', test()->get('http://'.$host.$path)->assertOk()->getContent());
}

it('draws a post and a product with no slot set byte for byte as before', function (string $path, string $golden): void {
    slotsGoldenSite();
    // A Livewire component rendered earlier in the run makes the next full page carry its assets once; let that page go first.
    test()->get('http://mau.platform.test/');

    expect(slotsHtml($path))->toBe(file_get_contents(__DIR__.'/golden/'.$golden));
})->with([
    'a post' => ['/tin-tuc/ong-thep-3', 'post-default.html'],
    'a product' => ['/do-ga-kiem', 'product-default.html'],
]);

/**
 * The news branch (root "Tin tức", child "Tin nội bộ") with a post being read, six others in the root,
 * three in the child, and a recruitment branch whose posts must never show; a catalog with two products.
 *
 * @return array{read: Entry, news: Taxonomy, inner: Taxonomy, jobs: Taxonomy}
 */
function slotsSite(string $name = 'Xưởng Mẫu', string $host = 'mau.platform.test', string $prefix = ''): array
{
    $siteId = StorefrontCards::site($name, $host);

    return app(Tenancy::class)->enter($siteId, function () use ($prefix): array {
        $photo = StorefrontCards::media('anh-'.md5($prefix), 960, 720);
        $at = fn (int $day): CarbonImmutable => CarbonImmutable::parse('2026-09-01 08:00', 'UTC')->addDays($day);
        $news = StorefrontCards::news();
        $inner = StorefrontCards::category('Tin nội bộ', 'tin-noi-bo', $news, null, 1);
        $jobs = StorefrontCards::category('Tuyển dụng', 'tuyen-dung', null, null, 5);

        $read = StorefrontCards::post($news, $prefix.'Ống đang đọc', 'ong-dang-doc', $at(10), ['image_url' => $photo->storage_key]);
        foreach (range(1, 6) as $n) {
            StorefrontCards::post($news, "{$prefix}Tin gốc {$n}", "tin-goc-{$n}", $at($n), ['image_url' => $photo->storage_key]);
        }
        foreach (range(1, 3) as $n) {
            StorefrontCards::post($inner, "{$prefix}Tin con {$n}", "tin-con-{$n}", $at(10 + $n), ['image_url' => $photo->storage_key]);
        }
        StorefrontCards::post($jobs, $prefix.'Việc làm kỹ sư', 'viec-lam-ky-su', $at(30));

        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham');
        StorefrontCards::catalog($catalog);
        StorefrontCards::product($prefix.'Đồ gá kiểm', 'do-ga-kiem', $catalog, $photo, $at(20));
        StorefrontCards::product($prefix.'Khuôn dập', 'khuon-dap', $catalog, $photo, $at(19));

        return ['read' => $read, 'news' => $news, 'inner' => $inner, 'jobs' => $jobs];
    });
}

/**
 * Set the site's default blocks for one slot.
 *
 * @param  list<array<string, mixed>>  $blocks
 */
function slotsDefault(int $siteId, string $slot, array $blocks, string $locale = 'vi'): void
{
    app(Tenancy::class)->enter($siteId, function () use ($slot, $blocks, $locale): void {
        $row = SiteSetting::query()->where('group_name', 'storefront')->where('key', 'layout_blocks')->first();
        $value = $row !== null && is_array($row->value) ? $row->value : [];
        $value[$slot][$locale] = ['blocks' => $blocks];
        SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'layout_blocks'], ['value' => $value]);
    });
    slotsFresh();
}

/** @param array<string, mixed> $choice slot → NONE or locale → blocks */
function slotsCategory(int $siteId, Taxonomy $category, array $choice): void
{
    app(Tenancy::class)->enter($siteId, fn () => Taxonomy::query()->whereKey($category->id)->update(['meta' => json_encode(['layout_blocks' => $choice])]));
    slotsFresh();
}

function slotsCta(string $heading): array
{
    return ['type' => 'cta', 'props' => ['heading' => $heading]];
}

function slotsSiteId(): int
{
    return (int) DB::table('sites')->orderByDesc('id')->value('id');
}

it('draws the blocks of the end-of-page bands on a post, a list and a product, and nothing when a band is empty', function (): void {
    slotsSite();
    $id = slotsSiteId();

    foreach (['/tin-tuc/ong-dang-doc', '/tin-tuc', '/do-ga-kiem', '/san-pham'] as $path) {
        expect(slotsHtml($path))->not->toContain('slot-band');
    }

    slotsDefault($id, 'after_post', [slotsCta('Báo giá sau bài')]);
    slotsDefault($id, 'after_list', [slotsCta('Báo giá sau danh sách')]);
    slotsDefault($id, 'after_product', [slotsCta('Báo giá sau sản phẩm')]);

    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->toContain('Báo giá sau bài')->not->toContain('Báo giá sau danh sách')
        ->and(slotsHtml('/tin-tuc'))->toContain('<div class="slot-band">')->toContain('Báo giá sau danh sách')
        ->and(slotsHtml('/san-pham'))->toContain('Báo giá sau danh sách')
        ->and(slotsHtml('/do-ga-kiem'))->toContain('Báo giá sau sản phẩm')->not->toContain('Báo giá sau bài');
});

it('lets the category override the site default, hides the slot on "none" and falls back to the default language', function (): void {
    $site = slotsSite();
    $id = slotsSiteId();
    slotsDefault($id, 'after_post', [slotsCta('Mặc định của website')]);

    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->toContain('Mặc định của website');

    slotsCategory($id, $site['news'], ['after_post' => ['vi' => ['blocks' => [slotsCta('Riêng cho chuyên mục')]]]]);
    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->toContain('Riêng cho chuyên mục')->not->toContain('Mặc định của website');

    slotsCategory($id, $site['news'], ['after_post' => 'none']);
    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->not->toContain('Riêng cho chuyên mục')->not->toContain('Mặc định của website')->not->toContain('slot-band');

    // An English page of the same post takes the default language's blocks when it has none of its own.
    slotsCategory($id, $site['news'], []);
    app(Tenancy::class)->enter($id, function () use ($site): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $site['read']->translations()->create(['locale' => 'en', 'title' => 'The pipe being read', 'slug' => 'pipe-read', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/news/pipe-read', 'entry_id' => $site['read']->id]);
    });
    expect(slotsHtml('/en/news/pipe-read'))->toContain('Mặc định của website');

    slotsDefault($id, 'after_post', [slotsCta('The English band')], 'en');
    expect(slotsHtml('/en/news/pipe-read'))->toContain('The English band')->not->toContain('Mặc định của website');
});

it('keeps the post one column with no side blocks and fewer than two headings, and makes two columns with a slot', function (): void {
    slotsSite();
    $id = slotsSiteId();

    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->not->toContain('post-columns')->not->toContain('<aside');

    slotsDefault($id, 'post_aside', [['type' => 'recent_posts', 'props' => []]]);

    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->toContain('post-columns')->toContain('<aside class="post-aside"')->toContain('slot-recent');
});

it('lists the newest posts of the root branch in the side column, never the one being read or its related posts', function (): void {
    slotsSite();
    $id = slotsSiteId();
    slotsDefault($id, 'post_aside', [['type' => 'recent_posts', 'props' => ['heading' => 'Bài mới đăng']]]);

    $html = slotsHtml('/tin-tuc/ong-dang-doc');
    preg_match('#<section class="slot-recent".*?</section>#s', $html, $match);
    $recent = $match[0] ?? '';

    expect($recent)->toContain('Bài mới đăng')
        // the newest four of the root branch that are not the related three: the child category's posts and the older root ones
        ->and($recent)->toContain('Tin con 3')->toContain('Tin con 2')->toContain('Tin con 1')
        ->and($recent)->not->toContain('Ống đang đọc')
        ->and($recent)->not->toContain('Việc làm kỹ sư');
    // The related posts (the three newest of the same category, "Tin gốc 6, 5, 4") are in the page's related section, not twice.
    foreach (['Tin gốc 6', 'Tin gốc 5', 'Tin gốc 4'] as $related) {
        expect($recent)->not->toContain($related);
    }
    expect(substr_count($recent, '<li>'))->toBe(4);
});

it('stays in the root branch when the post is in a child category', function (): void {
    $site = slotsSite();
    $id = slotsSiteId();
    slotsDefault($id, 'post_aside', [['type' => 'recent_posts', 'props' => []]]);

    $html = slotsHtml('/tin-noi-bo/tin-con-1');
    preg_match('#<section class="slot-recent".*?</section>#s', $html, $match);
    $recent = $match[0] ?? '';

    // The root's own posts count too (the whole branch), the other branch's never.
    expect($recent)->toContain('Ống đang đọc')->not->toContain('Việc làm kỹ sư')->not->toContain('Tin con 1');
    expect($site['jobs']->id)->not->toBe($site['news']->id);
});

it('reads the recent posts with the related posts: no extra query', function (): void {
    slotsSite();
    $id = slotsSiteId();
    $count = function (): int {
        slotsFresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('http://mau.platform.test/tin-tuc/ong-dang-doc')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    test()->get('http://mau.platform.test/tin-tuc/ong-dang-doc')->assertOk();
    $without = $count();

    slotsDefault($id, 'post_aside', [['type' => 'recent_posts', 'props' => []]]);
    test()->get('http://mau.platform.test/tin-tuc/ong-dang-doc')->assertOk();
    $with = $count();

    expect($with)->toBe($without);
});

it('never shows another site\'s posts in the recent list, with two sites interleaved', function (): void {
    slotsSite('Xưởng Mẫu', 'mau.platform.test');
    $first = slotsSiteId();
    slotsSite('Xưởng Khác', 'khac.platform.test', 'Khác ');
    $second = slotsSiteId();
    slotsDefault($first, 'post_aside', [['type' => 'recent_posts', 'props' => []]]);
    slotsDefault($second, 'post_aside', [['type' => 'recent_posts', 'props' => []]]);

    foreach (range(1, 2) as $_) {
        $mine = slotsHtml('/tin-tuc/ong-dang-doc', 'mau.platform.test');
        $theirs = slotsHtml('/tin-tuc/ong-dang-doc', 'khac.platform.test');
        expect($mine)->toContain('Tin con 3')->not->toContain('Khác Tin')
            ->and($theirs)->toContain('Khác Tin con 3')->not->toContain('>Tin con 3<');
    }
});

it('draws the categories of the root branch with no counts', function (): void {
    slotsSite();
    $id = slotsSiteId();
    slotsDefault($id, 'post_aside', [['type' => 'category_list', 'props' => []]]);

    $html = slotsHtml('/tin-tuc/ong-dang-doc');
    preg_match('#<section class="slot-categories".*?</section>#s', $html, $match);
    $list = $match[0] ?? '';

    expect($list)->toContain('Chuyên mục')->toContain('Tin nội bộ')->toContain('href="/tin-noi-bo"')
        ->and($list)->not->toContain('Tuyển dụng')
        ->and($list)->not->toMatch('#<span[^>]*>\s*\d+\s*</span>#');
});

it('hides the empty lines of the consult box and takes hotline and email from the site settings', function (): void {
    slotsSite();
    $id = slotsSiteId();
    $consult = ['type' => 'contact', 'props' => ['variant' => 'aside', 'eyebrow' => 'Tư vấn kỹ thuật', 'heading' => 'Cần tư vấn hoặc báo giá?', 'text' => 'Gửi bản vẽ để nhận báo giá.', 'button_label' => 'Gửi yêu cầu', 'button_url' => '/lien-he']];
    slotsDefault($id, 'post_aside', [$consult]);

    $setProfile = fn (array $values) => app(Tenancy::class)->enter($id, function () use ($values): void {
        SiteProfile::query()->update($values);
        slotsFresh();
    });

    $setProfile(['hotline' => '0915 66 83 83', 'email' => 'quybv@ckanvinh.vn']);
    $both = slotsHtml('/tin-tuc/ong-dang-doc');
    expect($both)->toContain('slot-consult__call')->toContain('Hotline 0915 66 83 83')->toContain('href="mailto:quybv@ckanvinh.vn"')
        ->toContain('Cần tư vấn hoặc báo giá?')->toContain('Gửi yêu cầu');

    $setProfile(['hotline' => null, 'email' => null]);
    $none = slotsHtml('/tin-tuc/ong-dang-doc');
    expect($none)->toContain('Cần tư vấn hoặc báo giá?')->not->toContain('slot-consult__call')->not->toContain('slot-consult__mail');
});

it('lists the headings that carry an id as the table of contents, and none under two', function (): void {
    $html = '<h2 id="nhom-thep">1. Ba nhóm thép</h2><p>x</p><h2>Không id</h2><h2 class="a" id="lop-phu">3. Lớp phủ &amp; xử lý</h2><h3 id="con">Con</h3>';

    expect(ArticleToc::of($html))->toBe([['id' => 'nhom-thep', 'label' => '1. Ba nhóm thép'], ['id' => 'lop-phu', 'label' => '3. Lớp phủ & xử lý']])
        ->and(ArticleToc::of('<h2 id="mot">Một</h2><h2>Hai</h2>'))->toBe([])
        ->and(ArticleToc::of('<p>Không có tiêu đề</p>'))->toBe([])
        ->and(ArticleToc::of('<h2 id="a">A</h2><h2 id="a">B</h2>'))->toBe([]);
});

it('draws the table of contents in the side column from the body headings, on desktop only', function (): void {
    app()->setLocale('vi');
    $toc = [['id' => 'nhom-thep', 'label' => 'Ba nhóm thép'], ['id' => 'lop-phu', 'label' => 'Lớp phủ PVD']];
    $draw = fn (array $toc, string $aside): string => view('theme::partials.post-aside', ['toc' => $toc, 'html' => $aside])->render();

    expect($draw($toc, ''))->toContain('post-aside post-aside--toc-only')->toContain('href="#nhom-thep"')->toContain('Ba nhóm thép')->toContain('Mục lục')
        ->and($draw($toc, '<p>khối</p>'))->not->toContain('toc-only')->toContain('<p>khối</p>')
        ->and($draw([], '<p>khối</p>'))->not->toContain('post-toc');
});

it('builds the table of contents of a post from the h2 headings with an id in its drawn body', function (): void {
    $site = slotsSite();
    $id = slotsSiteId();
    // A block whose view prints headings with ids (the sanitizer for formatted text strips ids, so a probe view stands in).
    $dir = sys_get_temp_dir().'/slot-toc-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/probe.blade.php', '<h2 id="nhom-thep">1. Ba nhóm thép</h2><p>a</p><h2 id="lop-phu">2. Lớp phủ PVD</h2>');
    View::addNamespace('probe', $dir);
    app(ComponentRegistry::class)->register('toc_probe', new SectionComponent('probe::probe', []));
    app(Tenancy::class)->enter($id, fn () => $site['read']->translations()->update(['body' => ['blocks' => [['type' => 'toc_probe', 'props' => []]]]]));

    $html = slotsHtml('/tin-tuc/ong-dang-doc');

    expect($html)->toContain('post-columns')->toContain('<aside class="post-aside post-aside--toc-only"')
        ->toContain('<a class="post-toc__link" href="#nhom-thep">1. Ba nhóm thép</a>')->toContain('href="#lop-phu"');

    file_put_contents($dir.'/single.blade.php', '<h2 id="nhom-thep">Chỉ một đề mục</h2>');
    app(ComponentRegistry::class)->register('toc_single', new SectionComponent('probe::single', []));
    app(Tenancy::class)->enter($id, fn () => $site['read']->translations()->update(['body' => ['blocks' => [['type' => 'toc_single', 'props' => []]]]]));
    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->not->toContain('post-columns')->not->toContain('post-toc');
});

it('keeps the slot-only blocks out of a page body and accepts them in a slot', function (): void {
    slotsSite();
    $body = [['type' => 'recent_posts', 'props' => []]];
    $page = Validator::make(['blocks' => $body], ['blocks' => [app(ValidPageJson::class)]]);
    $slot = Validator::make(['blocks' => $body], ['blocks' => [app(ValidPageJson::class)->forSlots()]]);

    expect($page->fails())->toBeTrue()->and($slot->fails())->toBeFalse();
    app(Tenancy::class)->enter(slotsSiteId(), function () use ($body): void {
        $checked = app(LayoutSlotInput::class)->check(['post_aside' => ['vi' => ['blocks' => $body]]], false);
        expect($checked['post_aside']['vi']['blocks'][0]['type'])->toBe('recent_posts');
    });
});

it('draws a slot\'s pages and files in the page\'s one lookup per kind, whatever the number of blocks', function (): void {
    slotsSite();
    $id = slotsSiteId();
    $buttons = fn (int $n): array => array_map(fn (int $i): array => ['type' => 'cta', 'props' => ['heading' => "Nút {$i}", 'button_label' => 'Liên hệ', 'button_url' => '/lien-he']], range(1, $n));
    $measure = function (): int {
        slotsFresh();
        test()->get('http://mau.platform.test/tin-tuc/ong-dang-doc')->assertOk();
        slotsFresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('http://mau.platform.test/tin-tuc/ong-dang-doc')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    slotsDefault($id, 'after_post', $buttons(1));
    $one = $measure();
    slotsDefault($id, 'after_post', $buttons(4));
    slotsDefault($id, 'post_aside', $buttons(2));
    $many = $measure();

    expect($many)->toBe($one);
});

it('stays within the query budget of the page with a filled slot, per page kind', function (string $path, array $slots, int $extra): void {
    slotsSite();
    $id = slotsSiteId();
    $measure = function (string $path): int {
        slotsFresh();
        test()->get('http://mau.platform.test'.$path)->assertOk();
        slotsFresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('http://mau.platform.test'.$path)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $base = $measure($path);
    foreach ($slots as $slot => $blocks) {
        slotsDefault($id, $slot, $blocks);
    }

    expect($measure($path))->toBeLessThanOrEqual($base + $extra);
})->with([
    // Pages and files are looked up once per kind when the slot's buttons name any; "category_list" reads names and routes.
    'a post' => ['/tin-tuc/ong-dang-doc', ['post_aside' => [['type' => 'recent_posts', 'props' => []], ['type' => 'category_list', 'props' => []], ['type' => 'contact', 'props' => ['variant' => 'aside', 'button_label' => 'Gửi', 'button_url' => '/lien-he']]], 'after_post' => [['type' => 'cta', 'props' => ['heading' => 'Báo giá', 'button_label' => 'Gửi', 'button_url' => '/lien-he']]]], 3],
    'a product' => ['/do-ga-kiem', ['after_product' => [['type' => 'cta', 'props' => ['heading' => 'Báo giá', 'button_label' => 'Gửi', 'button_url' => '/lien-he']]]], 1],
    'a category list' => ['/tin-tuc', ['after_list' => [['type' => 'cta', 'props' => ['heading' => 'Báo giá', 'button_label' => 'Gửi', 'button_url' => '/lien-he']]]], 1],
]);
