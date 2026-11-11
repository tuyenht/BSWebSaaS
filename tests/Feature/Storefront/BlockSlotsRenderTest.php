<?php

use App\Modules\Cms\Exceptions\LayoutBlocksRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Validation\LayoutSlotInput;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\ArticleToc;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
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
    // The post page prints its "Cập nhật" date (the row's updated_at), so the golden page holds the
    // day it was captured: pin the clock to that moment. The content's fixed dates share its year.
    $this->travelTo(CarbonImmutable::parse('2026-11-07 05:00', 'UTC'));
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
        // The post shows when its content last changed: pinned to the day the page was captured, not the day the test runs.
        EntryTranslation::query()->update(['updated_at' => '2026-11-07 10:00:00+00', 'content_changed_at' => '2026-11-07 10:00:00+00']);
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

/** A page with the year masked: the footer prints the machine clock's year, the content dates the test clock's. */
function slotsHtml(string $path, string $host = 'mau.platform.test'): string
{
    return str_replace(array_unique([date('Y'), now()->format('Y')]), '{year}', test()->get('http://'.$host.$path)->assertOk()->getContent());
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
 * The news branch (a root and a child "Bản tin nội bộ") with a post being read, six others in the root,
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
        $inner = StorefrontCards::category('Bản tin nội bộ', 'tin-noi-bo', $news, null, 1);
        $jobs = StorefrontCards::category('Tuyển dụng', 'tuyen-dung', null, null, 5);

        $read = StorefrontCards::post($news, $prefix.'Ống đang đọc', 'ong-dang-doc', $at(10), ['image_url' => $photo->storage_key]);
        foreach (range(1, 6) as $n) {
            StorefrontCards::post($news, "{$prefix}Bản tin gốc số {$n} mới", "tin-goc-{$n}", $at($n), ['image_url' => $photo->storage_key]);
        }
        foreach (range(1, 3) as $n) {
            StorefrontCards::post($inner, "{$prefix}Bản tin con số {$n} mới", "tin-con-{$n}", $at(10 + $n), ['image_url' => $photo->storage_key]);
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
    slotsDefault($id, 'after_post', [slotsCta('Mặc định của toàn bộ')]);

    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->toContain('Mặc định của toàn bộ');

    slotsCategory($id, $site['news'], ['after_post' => ['vi' => ['blocks' => [slotsCta('Riêng cho chuyên mục')]]]]);
    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->toContain('Riêng cho chuyên mục')->not->toContain('Mặc định của toàn bộ');

    slotsCategory($id, $site['news'], ['after_post' => 'none']);
    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->not->toContain('Riêng cho chuyên mục')->not->toContain('Mặc định của toàn bộ')->not->toContain('slot-band');

    // An English page of the same post takes the default language's blocks when it has none of its own.
    slotsCategory($id, $site['news'], []);
    app(Tenancy::class)->enter($id, function () use ($site): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $site['read']->translations()->create(['locale' => 'en', 'title' => 'The pipe being read', 'slug' => 'pipe-read', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/news/pipe-read', 'entry_id' => $site['read']->id]);
    });
    expect(slotsHtml('/en/news/pipe-read'))->toContain('Mặc định của toàn bộ');

    slotsDefault($id, 'after_post', [slotsCta('The English band')], 'en');
    expect(slotsHtml('/en/news/pipe-read'))->toContain('The English band')->not->toContain('Mặc định của toàn bộ');
});

it('keeps the post one column with no side blocks and fewer than two headings, and makes two columns with a slot', function (): void {
    slotsSite();
    $id = slotsSiteId();

    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->not->toContain('post-aside')->not->toContain('<aside');

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
        ->and($recent)->toContain('Bản tin con số 3 mới')->toContain('Bản tin con số 2 mới')->toContain('Bản tin con số 1 mới')
        ->and($recent)->not->toContain('Ống đang đọc')
        ->and($recent)->not->toContain('Việc làm kỹ sư');
    // The related posts (the three newest of the same category: the root's sixth, fifth and fourth) are in the page's related section, not twice.
    foreach (['Bản tin gốc số 6 mới', 'Bản tin gốc số 5 mới', 'Bản tin gốc số 4 mới'] as $related) {
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
    expect($recent)->toContain('Ống đang đọc')->not->toContain('Việc làm kỹ sư')->not->toContain('Bản tin con số 1 mới');
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
        expect($mine)->toContain('Bản tin con số 3 mới')->not->toContain('Khác Bản')
            ->and($theirs)->toContain('Khác Bản tin con số 3 mới')->not->toContain('>Bản tin con số 3 mới<');
    }
});

it('draws the categories of the root branch with no counts', function (): void {
    slotsSite();
    $id = slotsSiteId();
    slotsDefault($id, 'post_aside', [['type' => 'category_list', 'props' => []]]);

    $html = slotsHtml('/tin-tuc/ong-dang-doc');
    preg_match('#<section class="slot-categories".*?</section>#s', $html, $match);
    $list = $match[0] ?? '';

    expect($list)->toContain('Chuyên mục')->toContain('Bản tin nội bộ')->toContain('href="/tin-noi-bo"')
        ->and($list)->not->toContain('Tuyển dụng')
        ->and($list)->not->toMatch('#<span[^>]*>\s*\d+\s*</span>#');
});

it('hides the empty lines of the consult box and takes hotline and email from the site settings', function (): void {
    slotsSite();
    $id = slotsSiteId();
    $consult = ['type' => 'consult', 'props' => ['eyebrow' => 'Tư vấn kỹ thuật', 'heading' => 'Cần tư vấn hoặc báo giá?', 'text' => 'Gửi bản vẽ để nhận báo giá.', 'button_label' => 'Gửi yêu cầu', 'button_url' => '/lien-he']];
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

it('makes anchors for the formatted-text headings from two up, unique and Vietnamese-safe, and leaves a body with fewer untouched', function (): void {
    $two = '<section class="sf-prose">'."\n".'<h2>Đồ gá kiểm</h2><p>x</p><h2>Lớp phủ bề mặt</h2><h3>Con</h3><h2>Đồ gá kiểm</h2>'."\n".'</section>';
    $toc = ArticleToc::of($two);

    expect($toc['items'])->toBe([
        ['id' => 'muc-do-ga-kiem', 'label' => 'Đồ gá kiểm'],
        ['id' => 'muc-lop-phu-be-mat', 'label' => 'Lớp phủ bề mặt'],
        ['id' => 'muc-do-ga-kiem-2', 'label' => 'Đồ gá kiểm'],
    ])->and($toc['html'])->toContain('<h2 id="muc-do-ga-kiem">Đồ gá kiểm</h2>')->toContain('<h2 id="muc-do-ga-kiem-2">')->toContain('<h3>Con</h3>');

    $one = '<section class="sf-prose"><h2>Một đề mục</h2></section>';
    $outside = '<h2>Tiêu đề khối</h2><section class="richtext"><h2>Khác</h2></section><section class="sf-prose"><h2>Chỉ một</h2></section>';
    expect(ArticleToc::of($one))->toBe(['html' => $one, 'items' => []])
        ->and(ArticleToc::of($outside))->toBe(['html' => $outside, 'items' => []])
        ->and(ArticleToc::of('<p>Không có tiêu đề</p>')['items'])->toBe([]);
});

it('lists the headings of gallery, video and FAQ blocks with the text headings, in page order', function (): void {
    $block = fn (string $kind, string $heading): string => '<section class="sf-band sf-band--none '.$kind.'">'."\n".'    <div class="sf-container">'."\n"
        .'<div class="sf-section-head">'."\n".'    <h2 class="sf-section-head__title">'.$heading.'</h2>'."\n".'</div>'."\n".'<p>…</p>'."\n".'    </div>'."\n".'</section>';
    $html = '<section class="sf-prose">'."\n".'<h2>Tổng quan</h2><p>a</p>'."\n".'</section>'
        .$block('sf-gallery', 'Hình ảnh')
        .'<section class="sf-prose"><h2>Thông số</h2></section>'
        .$block('sf-video-block', 'Video &amp; quy trình')
        .$block('sf-faq', 'Câu hỏi thường gặp')
        // other blocks' heads (a call to action, a process) are not part of the article's contents
        .$block('sf-process', 'Quy trình')
        .'<section class="cta"><h2 class="cta__title">Liên hệ</h2></section>';

    $toc = ArticleToc::of($html);

    expect($toc['items'])->toBe([
        ['id' => 'muc-tong-quan', 'label' => 'Tổng quan'],
        ['id' => 'muc-hinh-anh', 'label' => 'Hình ảnh'],
        ['id' => 'muc-thong-so', 'label' => 'Thông số'],
        ['id' => 'muc-video-quy-trinh', 'label' => 'Video & quy trình'],
        ['id' => 'muc-cau-hoi-thuong-gap', 'label' => 'Câu hỏi thường gặp'],
    ])->and($toc['html'])->toContain('<h2 class="sf-section-head__title" id="muc-hinh-anh">Hình ảnh</h2>')
        ->toContain('<h2 class="sf-section-head__title" id="muc-video-quy-trinh">Video &amp; quy trình</h2>')
        ->toContain('<h2 class="sf-section-head__title">Quy trình</h2>')->toContain('<h2 class="cta__title">Liên hệ</h2>');

    // One text heading and one FAQ heading make two: the contents are drawn.
    $two = '<section class="sf-prose"><h2>Một</h2></section>'.$block('sf-faq', 'Hỏi đáp');
    expect(array_column(ArticleToc::of($two)['items'], 'id'))->toBe(['muc-mot', 'muc-hoi-dap'])
        // a lone block heading is not a table of contents, and the body comes back as it went in
        ->and(ArticleToc::of($block('sf-faq', 'Hỏi đáp')))->toBe(['html' => $block('sf-faq', 'Hỏi đáp'), 'items' => []]);
});

it('never makes an anchor equal to an id the page already uses', function (): void {
    $html = '<section class="sf-prose"><h2>Liên quan</h2><h2>Liên quan</h2></section>';

    $toc = ArticleToc::of($html, ['muc-lien-quan', 'muc-lien-quan-2']);

    expect(array_column($toc['items'], 'id'))->toBe(['muc-lien-quan-3', 'muc-lien-quan-4']);
});

it('draws the table of contents in the side column from the body headings, on desktop only', function (): void {
    app()->setLocale('vi');
    $toc = [['id' => 'nhom-thep', 'label' => 'Bản tin nhóm thép'], ['id' => 'lop-phu', 'label' => 'Lớp phủ bề mặt']];
    $draw = fn (array $toc, string $aside): string => view('theme::partials.post-aside', ['toc' => $toc, 'html' => $aside])->render();

    expect($draw($toc, ''))->toContain('post-aside post-aside--toc-only')->toContain('href="#nhom-thep"')->toContain('Bản tin nhóm thép')->toContain('Mục lục')
        ->and($draw($toc, '<p>khối</p>'))->not->toContain('toc-only')->toContain('<p>khối</p>')
        ->and($draw([], '<p>khối</p>'))->not->toContain('post-toc');
});

/** Store a post's body the way the editor does: one formatted-text block. */
function slotsBody(int $siteId, Entry $read, string $html): void
{
    app(Tenancy::class)->enter($siteId, function () use ($read, $html): void {
        $translation = $read->translations()->firstOrFail();
        $translation->body = ['blocks' => [['type' => 'richtext_html', 'props' => ['html' => $html]]]];
        $translation->save();
    });
    slotsFresh();
}

it('builds both tables of contents of a post from its stored formatted text, with working anchors', function (): void {
    $site = slotsSite();
    $id = slotsSiteId();
    slotsBody($id, $site['read'], '<p>Mở đầu</p><h2>Nhóm thép</h2><p>a</p><h2>Lớp phủ bề mặt</h2><p>b</p><h2>Nhóm thép</h2><p>c</p>');

    $html = slotsHtml('/tin-tuc/ong-dang-doc');

    expect($html)->toContain('<h2 id="muc-nhom-thep">Nhóm thép</h2>')->toContain('<h2 id="muc-lop-phu-be-mat">')->toContain('<h2 id="muc-nhom-thep-2">')
        ->toContain('<aside class="post-aside post-aside--toc-only"')
        ->toContain('<a class="post-toc__link" href="#muc-nhom-thep-2">Nhóm thép</a>')
        // the phone's disclosure carries the same entries
        ->toContain('<details class="post-toc-phone">')->toContain('Mục lục bài viết');
    preg_match('#<details class="post-toc-phone">.*?</details>#s', $html, $phone);
    preg_match('#<nav class="post-toc".*?</nav>#s', $html, $desktop);
    preg_match_all('#href="\#(muc-[a-z0-9-]+)"#', $phone[0] ?? '', $phoneLinks);
    preg_match_all('#href="\#(muc-[a-z0-9-]+)"#', $desktop[0] ?? '', $desktopLinks);
    expect($phoneLinks[1])->toBe(['muc-nhom-thep', 'muc-lop-phu-be-mat', 'muc-nhom-thep-2'])->and($desktopLinks[1])->toBe($phoneLinks[1]);
    // every link lands on exactly one heading
    foreach ($phoneLinks[1] as $anchor) {
        expect(substr_count($html, 'id="'.$anchor.'"'))->toBe(1);
    }
});

it('builds the contents of a post from its text and its gallery, video and FAQ blocks, and loads the reading marker', function (): void {
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
        }
    });
    $site = slotsSite();
    $id = slotsSiteId();
    $photo = app(Tenancy::class)->enter($id, fn (): int => StorefrontCards::media('anh-bai')->id);
    app(Tenancy::class)->enter($id, function () use ($site, $photo): void {
        $translation = $site['read']->translations()->firstOrFail();
        $translation->body = ['blocks' => [
            ['type' => 'richtext_html', 'props' => ['html' => '<h2>Tổng quan</h2><p>a</p>']],
            ['type' => 'gallery', 'props' => ['heading' => 'Hình ảnh', 'images' => [['image' => $photo, 'caption' => 'Xưởng']]]],
            ['type' => 'video', 'props' => ['heading' => 'Video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']],
            ['type' => 'faq', 'props' => ['heading' => 'Câu hỏi thường gặp', 'items' => [['q' => 'Bao lâu?', 'a' => 'Một tuần.']]]],
            // a FAQ with no heading adds nothing to the contents
            ['type' => 'faq', 'props' => ['items' => [['q' => 'Ở đâu?', 'a' => 'Hà Nội.']]]],
            ['type' => 'cta', 'props' => ['heading' => 'Cần báo giá?', 'button_label' => 'Gửi', 'button_url' => '/lien-he']],
        ]];
        $translation->save();
    });
    slotsFresh();

    $html = slotsHtml('/tin-tuc/ong-dang-doc');

    preg_match('#<nav class="post-toc".*?</nav>#s', $html, $desktop);
    preg_match('#<details class="post-toc-phone">.*?</details>#s', $html, $phone);
    preg_match_all('#href="\#(muc-[a-z0-9-]+)"#', $desktop[0] ?? '', $desktopLinks);
    preg_match_all('#href="\#(muc-[a-z0-9-]+)"#', $phone[0] ?? '', $phoneLinks);
    expect($desktopLinks[1])->toBe(['muc-tong-quan', 'muc-hinh-anh', 'muc-video', 'muc-cau-hoi-thuong-gap'])
        ->and($phoneLinks[1])->toBe($desktopLinks[1])
        ->and($html)->toContain('<h2 class="sf-section-head__title" id="muc-cau-hoi-thuong-gap">Câu hỏi thường gặp</h2>')
        ->and($html)->toContain('<script type="module" src="/resources/js/sf-toc.js"></script>')
        // plain links: nothing is marked as being read until the script runs
        ->and($desktop[0] ?? '')->not->toContain('aria-current');
    foreach ($desktopLinks[1] as $anchor) {
        expect(substr_count($html, 'id="'.$anchor.'"'))->toBe(1);
    }

    // A post with no contents loads no marker.
    slotsBody($id, $site['read'], '<h2>Chỉ một đề mục</h2><p>Nội dung</p>');
    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->not->toContain('sf-toc.js');
});

it('leaves a post with one heading byte for byte as the body was stored, with no contents', function (): void {
    $site = slotsSite();
    $id = slotsSiteId();
    slotsBody($id, $site['read'], '<h2>Chỉ một đề mục</h2><p>Nội dung</p>');

    $html = slotsHtml('/tin-tuc/ong-dang-doc');

    expect($html)->toContain("<section class=\"sf-prose\">\n<h2>Chỉ một đề mục</h2><p>Nội dung</p>\n</section>")
        ->not->toContain('id="muc-')->not->toContain('post-toc')->not->toContain('post-aside');
});

it('keeps the slot-only blocks out of a page body and accepts them in a slot', function (string $type): void {
    slotsSite();
    $body = [['type' => $type, 'props' => []]];
    $page = Validator::make(['blocks' => $body], ['blocks' => [app(ValidPageJson::class)]]);
    $slot = Validator::make(['blocks' => $body], ['blocks' => [app(ValidPageJson::class)->forSlots()]]);

    expect($page->fails())->toBeTrue()->and($slot->fails())->toBeFalse();
    app(Tenancy::class)->enter(slotsSiteId(), function () use ($body, $type): void {
        $checked = app(LayoutSlotInput::class)->check(['post_aside' => ['vi' => ['blocks' => $body]]], false);
        expect($checked['post_aside']['vi']['blocks'][0]['type'])->toBe($type);
    });
})->with(['recent_posts', 'category_list', 'consult']);

it('accepts the recent-posts block in the post slots only', function (): void {
    app()->setLocale('vi');
    slotsSite();
    $blocks = ['vi' => ['blocks' => [['type' => 'recent_posts', 'props' => []]]]];

    app(Tenancy::class)->enter(slotsSiteId(), function () use ($blocks): void {
        $input = app(LayoutSlotInput::class);
        foreach (['post_aside', 'after_post'] as $slot) {
            expect($input->check([$slot => $blocks], false)[$slot]['vi']['blocks'][0]['type'])->toBe('recent_posts');
        }
        foreach (['after_list', 'after_product'] as $slot) {
            expect(fn () => $input->check([$slot => $blocks], false))->toThrow(LayoutBlocksRejectedException::class, 'chỉ đặt được ở cột bên hoặc cuối bài viết');
        }
        // A block that reads no post goes in any slot.
        expect($input->check(['after_list' => ['vi' => ['blocks' => [slotsCta('Báo giá cuối danh sách')]]]], false))->toHaveKey('after_list');
    });
});

it('draws the end-of-list band on every grid list template, and nothing when the band is empty', function (string $template): void {
    slotsSite();
    $id = slotsSiteId();
    app(Tenancy::class)->enter($id, fn () => StorefrontCards::category('Ấn phẩm số hóa', 'an-pham-so-hoa', listTemplate: $template));

    expect(slotsHtml('/an-pham-so-hoa'))->not->toContain('slot-band');

    slotsDefault($id, 'after_list', [slotsCta('Báo giá cuối danh sách')]);

    expect(slotsHtml('/an-pham-so-hoa'))->toContain('<div class="slot-band">')->toContain('Báo giá cuối danh sách');
})->with(['grid-news', 'grid-product', 'grid-project', 'grid-job']);

it('gives a block in the post body its own contact lines, not the site\'s that a slot block shows', function (): void {
    $site = slotsSite();
    $id = slotsSiteId();
    app(Tenancy::class)->enter($id, function () use ($site): void {
        SiteProfile::query()->update(['hotline' => '0915 66 83 83', 'email' => 'quybv@ckanvinh.vn']);
        $translation = $site['read']->translations()->firstOrFail();
        $translation->body = ['blocks' => [['type' => 'contact', 'props' => ['heading' => 'Liên hệ riêng', 'phone' => '0901 234 567', 'email' => 'rieng@xuong.vn']]]];
        $translation->save();
    });
    slotsDefault($id, 'post_aside', [['type' => 'consult', 'props' => ['heading' => 'Cần tư vấn?']]]);

    $html = slotsHtml('/tin-tuc/ong-dang-doc');
    preg_match('#<section class="slot-consult">.*?</section>#s', $html, $aside);
    // The post's own body: from its column to the side column (the footer prints the site's lines too).
    $from = strpos($html, 'post__body');
    $body = substr($html, $from, strpos($html, '<aside', $from) - $from);

    expect($body)->toContain('0901 234 567')->toContain('rieng@xuong.vn')->not->toContain('quybv@ckanvinh.vn')->not->toContain('0915 66 83 83')
        ->and($aside[0] ?? '')->toContain('quybv@ckanvinh.vn')->not->toContain('rieng@xuong.vn');
});

it('loads the slider script for a rotating slider in a list or product slot', function (string $path, string $slot): void {
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
        }
    });
    slotsSite();
    $id = slotsSiteId();
    $slide = fn (string $heading): array => ['heading' => $heading, 'image_url' => ''];

    slotsDefault($id, $slot, [['type' => 'hero_slider', 'props' => ['slides' => [$slide('Tấm một'), $slide('Tấm đầu tiên')]]]]);
    expect(slotsHtml($path))->toContain('<script type="module" src="/resources/js/sf-slider.js"></script>');

    slotsDefault($id, $slot, [['type' => 'hero_slider', 'props' => ['slides' => [$slide('Tấm duy nhất')]]]]);
    expect(slotsHtml($path))->not->toContain('sf-slider.js');
})->with([
    'a product' => ['/do-ga-kiem', 'after_product'],
    'a list' => ['/tin-tuc', 'after_list'],
]);

it('loads the photo viewer for a gallery in a post, list or product slot', function (string $path, string $slot): void {
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
        }
    });
    slotsSite();
    $id = slotsSiteId();
    $photo = app(Tenancy::class)->enter($id, fn (): int => StorefrontCards::media('anh-thu-vien')->id);
    expect(slotsHtml($path))->not->toContain('sf-viewer');

    slotsDefault($id, $slot, [['type' => 'gallery', 'props' => ['images' => [['image' => $photo, 'caption' => 'Xưởng']]]]]);
    $html = slotsHtml($path);

    expect($html)->toContain('<script type="module" src="/resources/js/sf-viewer.js"></script>')
        ->and($html)->toContain('<template id="sf-viewer">')
        ->and($html)->toContain('data-sf-zoom="gallery"');
})->with([
    'a post' => ['/tin-tuc/ong-dang-doc', 'after_post'],
    'a product' => ['/do-ga-kiem', 'after_product'],
    'a list' => ['/tin-tuc', 'after_list'],
]);

it('gives every drawing of the recent-posts and category blocks its own heading id', function (): void {
    slotsSite();
    $id = slotsSiteId();
    slotsDefault($id, 'post_aside', [['type' => 'recent_posts', 'props' => []], ['type' => 'category_list', 'props' => []]]);
    slotsDefault($id, 'after_post', [['type' => 'recent_posts', 'props' => []], ['type' => 'category_list', 'props' => []]]);

    $html = slotsHtml('/tin-tuc/ong-dang-doc');
    preg_match_all('#<section class="slot-(?:recent|categories)" aria-labelledby="([^"]+)">\s*<h2 class="slot-title" id="([^"]+)"#', $html, $found, PREG_SET_ORDER);

    expect($found)->toHaveCount(4);
    foreach ($found as $one) {
        expect($one[1])->toBe($one[2])->and(substr_count($html, 'id="'.$one[2].'"'))->toBe(1);
    }
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

it('adds exactly the expected queries to a page for its filled slots, and stays under the page\'s ceiling', function (string $path, array $slots, int $delta, int $ceiling): void {
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
    $with = $measure($path);

    expect($with - $base)->toBe($delta)->and($with)->toBeLessThanOrEqual($ceiling);
})->with([
    // The slot settings are read in one go; pages and files named by buttons are looked up once per kind;
    // "category_list" reads the branch and the names of its categories.
    'a post' => ['/tin-tuc/ong-dang-doc', ['post_aside' => [['type' => 'recent_posts', 'props' => []], ['type' => 'category_list', 'props' => []], ['type' => 'consult', 'props' => ['button_label' => 'Gửi', 'button_url' => '/lien-he']]], 'after_post' => [['type' => 'cta', 'props' => ['heading' => 'Báo giá', 'button_label' => 'Gửi', 'button_url' => '/lien-he']]]], 2, 22],
    'a product' => ['/do-ga-kiem', ['after_product' => [['type' => 'cta', 'props' => ['heading' => 'Báo giá', 'button_label' => 'Gửi', 'button_url' => '/lien-he']]]], 0, 23],
    'a product, every slot' => ['/do-ga-kiem', [
        'product_before_action' => [['type' => 'richtext_html', 'props' => ['html' => '<p>Giá theo bản vẽ.</p>']]],
        'product_after_action' => [['type' => 'richtext_html', 'props' => ['html' => '<ul><li>Hoá đơn VAT</li></ul>']]],
        'product_tabs' => [['type' => 'richtext_html', 'props' => ['html' => '<h2>Bảo hành</h2><p>12 tháng.</p>']], ['type' => 'faq', 'props' => ['heading' => 'Hỏi đáp', 'items' => [['q' => 'Có?', 'a' => 'Có.']]]]],
        'after_product' => [['type' => 'cta', 'props' => ['heading' => 'Báo giá', 'button_label' => 'Gửi', 'button_url' => '/lien-he']]],
    ], 0, 23],
    'a category list' => ['/tin-tuc', ['after_list' => [['type' => 'cta', 'props' => ['heading' => 'Báo giá', 'button_label' => 'Gửi', 'button_url' => '/lien-he']]]], 0, 17],
]);

it('prints the post count after each category when the block asks for counts', function (): void {
    slotsSite();
    $id = slotsSiteId();
    slotsDefault($id, 'post_aside', [['type' => 'category_list', 'props' => ['counts' => 'show']]]);

    preg_match('#<section class="slot-categories.*?</section>#s', slotsHtml('/tin-tuc/ong-dang-doc'), $match);
    $list = $match[0] ?? '';

    // The branch root holds the post being read and six others; the child holds three.
    expect($list)->toContain('slot-categories--counts')->toContain('slot-categories__count')
        ->and($list)->toMatch('#Bản tin nội bộ.*?slot-categories__count">\s*3#s');
});

it('lists the posts of the page category, the one being read marked, when the source is posts', function (): void {
    slotsSite();
    $id = slotsSiteId();
    slotsDefault($id, 'post_aside', [['type' => 'category_list', 'props' => ['source' => 'posts', 'heading' => 'Cùng nhóm']]]);

    preg_match('#<section class="slot-categories.*?</section>#s', slotsHtml('/tin-tuc/ong-dang-doc'), $match);
    $list = $match[0] ?? '';

    expect($list)->toContain('slot-categories--posts')->toContain('Cùng nhóm')->toContain('Ống đang đọc')->toContain('aria-current="page"')
        ->and($list)->not->toContain('Việc làm kỹ sư');
});

it('draws a document card only with a title and a link, and nothing otherwise', function (): void {
    slotsSite();
    $id = slotsSiteId();
    slotsDefault($id, 'post_aside', [['type' => 'file_card', 'props' => ['title' => 'Hồ sơ năng lực', 'text' => 'Tải xuống', 'url' => '/ho-so']]]);
    $with = slotsHtml('/tin-tuc/ong-dang-doc');
    expect($with)->toContain('class="slot-file"')->toContain('href="/ho-so"')->toContain('Hồ sơ năng lực')->toContain('>PDF<');

    slotsDefault($id, 'post_aside', [['type' => 'file_card', 'props' => ['title' => 'Không có liên kết']]]);
    expect(slotsHtml('/tin-tuc/ong-dang-doc'))->not->toContain('slot-file');
});
