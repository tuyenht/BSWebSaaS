<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteProfileTranslation;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Seo\DescriptionFallback;
use Tests\Fixtures\StorefrontCards;

/*
 * A site just created has no brand name, no brand description and no default description, and its
 * pages have no Google description yet: every page still gets a description of its own, taken from
 * its own words (a page's first sentence-long paragraph, a list's name and first titles, a
 * product's summary or description), and no two pages share one.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

const DF_HOST = 'http://mau.platform.test';

/** A site with nothing that describes it: no brand name, no brand description, no default description. */
function dfBareSite(): int
{
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->delete();
        SiteSetting::query()->where('group_name', 'seo')->where('key', 'config')->delete();
        SiteProfile::query()->update(['brand_name' => null, 'legal_name' => null]);
        SiteProfileTranslation::query()->update(['brand_name' => null, 'legal_name' => null, 'short_description' => null, 'tagline' => null]);
    });

    return $site;
}

function dfDescription(string $html): ?string
{
    return preg_match('/<meta name="description" content="([^"]*)">/', $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : null;
}

it('describes every kind of page from its own words when the site says nothing about itself', function (): void {
    $site = dfBareSite();
    $intro = 'Chúng tôi gia công cơ khí chính xác cho ngành ô tô, điện tử và thiết bị y tế từ năm 2010.';
    app(Tenancy::class)->enter($site, function () use ($intro): void {
        StorefrontCards::page('/gioi-thieu', 'Giới thiệu', [
            ['type' => 'richtext', 'props' => ['heading' => 'Về chúng tôi', 'paragraphs' => ['Ngắn.', $intro, 'Đoạn sau không được dùng vì đoạn trước đã đủ dài.']]],
        ]);
        $news = StorefrontCards::category('Bản tin xưởng', 'ban-tin-xuong');
        StorefrontCards::post($news, 'Khai trương nhà xưởng mới', 'khai-truong');
        StorefrontCards::post($news, 'Đạt chứng nhận ISO 9001', 'iso-9001');
        StorefrontCards::product('Khuôn ép nhựa', 'khuon-ep-nhua', summary: 'Khuôn ép nhựa nhiều lòng khuôn, thép P20, tuổi thọ 500.000 lần ép.');
        StorefrontCards::product('Đồ gá kiểm', 'do-ga-kiem');
        StorefrontCards::product('Khuôn dập liên hoàn', 'khuon-dap-lien-hoan')->translations()
            ->update(['description' => '<ul><li>Thép SKD11</li></ul><p>Khuôn dập liên hoàn nhiều trạm cho chi tiết ô tô.</p>']);
    });

    $page = dfDescription($this->get(DF_HOST.'/gioi-thieu')->assertOk()->getContent());
    $list = dfDescription($this->get(DF_HOST.'/ban-tin-xuong')->assertOk()->getContent());
    $product = dfDescription($this->get(DF_HOST.'/khuon-ep-nhua')->assertOk()->getContent());
    $bare = dfDescription($this->get(DF_HOST.'/do-ga-kiem')->assertOk()->getContent());
    $described = dfDescription($this->get(DF_HOST.'/khuon-dap-lien-hoan')->assertOk()->getContent());

    expect($page)->toBe($intro)
        ->and($list)->toStartWith('Bản tin xưởng: ')->toContain('Khai trương nhà xưởng mới')->toContain('Đạt chứng nhận ISO 9001')
        ->and($product)->toBe('Khuôn ép nhựa nhiều lòng khuôn, thép P20, tuổi thọ 500.000 lần ép.')
        // A product with neither summary nor description is still named.
        ->and($bare)->toBe('Đồ gá kiểm')
        // Without a summary, the product's own description (its first paragraph, not its spec list).
        ->and($described)->toBe('Khuôn dập liên hoàn nhiều trạm cho chi tiết ô tô.')
        ->and(array_unique([$page, $list, $product, $bare, $described]))->toHaveCount(5);
});

it('keeps the brand description ahead of the page’s own words', function (): void {
    $site = dfBareSite();
    app(Tenancy::class)->enter($site, function (): void {
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => ['description' => 'Câu mô tả thương hiệu']]);
        StorefrontCards::page('/gioi-thieu', 'Giới thiệu', [
            ['type' => 'richtext', 'props' => ['paragraphs' => ['Chúng tôi gia công cơ khí chính xác cho ngành ô tô và điện tử.']]],
        ]);
    });

    expect(dfDescription($this->get(DF_HOST.'/gioi-thieu')->assertOk()->getContent()))->toBe('Câu mô tả thương hiệu');
});

it('cuts at a word, at most 160 characters, and never takes script or style text', function (): void {
    $long = trim(str_repeat('chính xác ', 40));
    $cut = DescriptionFallback::fromHtml('<style>p{color:red}</style><script>var a = "một đoạn mã rất dài không phải chữ của trang";</script><p>'.$long.'</p>', 'Tiêu đề');

    expect(mb_strlen($cut))->toBeLessThanOrEqual(160)
        ->and($cut)->toEndWith('xác')->not->toContain('color')->not->toContain('var a')
        ->and(DescriptionFallback::fromHtml('<div>Ngắn</div>', 'Tiêu đề trang'))->toBe('Tiêu đề trang')
        ->and(DescriptionFallback::fromHtml('<p>Ngắn</p><p>Cơ khí &amp; tự động hoá cho nhà máy.</p>', 'X'))->toBe('Cơ khí & tự động hoá cho nhà máy.')
        // A form's hint, the breadcrumb and a button are not the page's words; loose text is not a paragraph.
        ->and(DescriptionFallback::fromHtml('<nav><p>Trang chủ › Liên hệ với chúng tôi</p></nav><form><p>Để lại điện thoại hoặc email – ít nhất một.</p></form><button><p>Gửi yêu cầu báo giá ngay</p></button><div>Chữ rời không nằm trong đoạn văn nào cả.</div>', 'Liên hệ'))->toBe('Liên hệ')
        ->and(DescriptionFallback::forList('Dự án', []))->toBe('Dự án')
        // Titles are plain text: a "<" in them is a character, not the start of a tag.
        ->and(DescriptionFallback::forList('Đồ gá', ["Dung sai <0,01 mm\n thép P20"]))->toBe('Đồ gá: Dung sai <0,01 mm thép P20');
});

it('never takes another entry’s card or a list for the page’s words, nested lists included', function (): void {
    $html = '<section><h2>Tin</h2><ul class="sf-grid"><li><article class="sf-card"><p class="sf-card__text">Đoạn trích của một bài viết khác trên website.</p>'
        .'<ul><li><p>Một dòng trong danh sách lồng bên trong thẻ.</p></li></ul></article></li>'
        .'<li><p>Mục thứ hai của danh sách, sau danh sách lồng.</p></li></ul></section>'
        .'<p>Đoạn văn riêng của trang nằm sau danh sách.</p>';

    expect(DescriptionFallback::fromHtml($html, 'Trang'))->toBe('Đoạn văn riêng của trang nằm sau danh sách.');
});

it('keeps every description the page already has ahead of its own words', function (): void {
    $site = dfBareSite();
    app(Tenancy::class)->enter($site, function (): void {
        $words = [['type' => 'richtext', 'props' => ['paragraphs' => ['Chúng tôi gia công cơ khí chính xác cho ngành ô tô và điện tử.']]]];
        StorefrontCards::page('/rieng', 'Riêng', $words)->translations()->update(['seo_description' => 'Mô tả Google riêng']);
        StorefrontCards::page('/tom-tat', 'Tóm tắt', $words)->translations()->update(['excerpt' => 'Tóm tắt của trang']);
        StorefrontCards::page('/mac-dinh', 'Mặc định', $words);
        SiteSetting::query()->create(['group_name' => 'seo', 'key' => 'config', 'value' => ['descriptions' => ['vi' => 'Mô tả mặc định của website']]]);
    });

    expect(dfDescription($this->get(DF_HOST.'/rieng')->assertOk()->getContent()))->toBe('Mô tả Google riêng')
        ->and(dfDescription($this->get(DF_HOST.'/tom-tat')->assertOk()->getContent()))->toBe('Tóm tắt của trang')
        ->and(dfDescription($this->get(DF_HOST.'/mac-dinh')->assertOk()->getContent()))->toBe('Mô tả mặc định của website');
});
