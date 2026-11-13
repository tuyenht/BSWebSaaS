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
    });

    $page = dfDescription($this->get(DF_HOST.'/gioi-thieu')->assertOk()->getContent());
    $list = dfDescription($this->get(DF_HOST.'/ban-tin-xuong')->assertOk()->getContent());
    $product = dfDescription($this->get(DF_HOST.'/khuon-ep-nhua')->assertOk()->getContent());
    $bare = dfDescription($this->get(DF_HOST.'/do-ga-kiem')->assertOk()->getContent());

    expect($page)->toBe($intro)
        ->and($list)->toStartWith('Bản tin xưởng: ')->toContain('Khai trương nhà xưởng mới')->toContain('Đạt chứng nhận ISO 9001')
        ->and($product)->toBe('Khuôn ép nhựa nhiều lòng khuôn, thép P20, tuổi thọ 500.000 lần ép.')
        // A product with neither summary nor description is still named.
        ->and($bare)->toContain('Đồ gá kiểm')
        ->and(array_unique([$page, $list, $product, $bare]))->toHaveCount(4);
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
        ->and(DescriptionFallback::forList('Dự án', []))->toBe('Dự án');
});
