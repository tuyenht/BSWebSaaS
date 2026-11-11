<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * The technical SEO tags of the storefront <head>: Twitter card, og:site_name and a full og:locale,
 * FAQPage JSON-LD for the question blocks a page draws, the branches as the business's locations,
 * and a second list page whose "previous" link is the first page's own address (not ?page=1).
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** The JSON-LD blocks of a page, decoded. */
function techLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);
}

/** A site with a brand name and one plain page at /gioi-thieu (or the given blocks), plus optional extra settings. */
function techSite(array $blocks = [['type' => 'richtext', 'props' => ['paragraphs' => ['Nội dung.']]]], string $path = '/gioi-thieu', ?array $footer = null): int
{
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($blocks, $path, $footer): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Xưởng Mẫu', 'phone' => '0900000000']]);
        if ($footer !== null) {
            SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'footer'], ['value' => $footer]);
        }
        StorefrontCards::page($path, 'Trang thử', $blocks);
    });

    return $siteId;
}

it('prints a Twitter card, the site name and a full locale next to Open Graph', function (): void {
    techSite();

    $html = test()->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<meta name="twitter:card" content="summary">')
        ->toContain('<meta name="twitter:title" content="Trang thử">')
        ->toContain('<meta property="og:site_name" content="Xưởng Mẫu">')
        ->toContain('<meta property="og:locale" content="vi_VN">');
});

it('prints no share tags, Twitter card or FAQ data on the 404 page', function (): void {
    StorefrontCards::site();

    $html = test()->get('http://mau.platform.test/khong-co')->assertNotFound()->getContent();

    expect($html)->not->toContain('twitter:card')->not->toContain('og:site_name')->not->toContain('FAQPage');
});

it('describes the questions of a faq block as FAQPage JSON-LD, only those with an answer', function (): void {
    techSite([
        ['type' => 'faq', 'props' => ['heading' => 'Hỏi đáp', 'items' => [
            ['q' => 'Giao hàng bao lâu?', 'a' => 'Từ 3 đến 5 ngày.'],
            ['q' => 'Câu chưa có trả lời'],
            ['q' => 'Có bảo hành không?', 'a' => 'Có, 12 tháng.'],
        ]]],
    ]);

    $faq = collect(techLd(test()->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent()))->firstWhere('@type', 'FAQPage');

    expect($faq['mainEntity'])->toHaveCount(2)
        ->and($faq['mainEntity'][0])->toBe(['@type' => 'Question', 'name' => 'Giao hàng bao lâu?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Từ 3 đến 5 ngày.']])
        ->and($faq['mainEntity'][1]['name'])->toBe('Có bảo hành không?');
});

it('prints no FAQPage on a page without a faq block', function (): void {
    techSite();

    expect(test()->get('http://mau.platform.test/gioi-thieu')->getContent())->not->toContain('FAQPage');
});

it('lists the branches with an address or a position as locations of the business', function (): void {
    techSite(footer: ['factories' => [
        ['title' => 'Nhà máy 1', 'full_name' => 'Nhà máy số 1', 'locality' => 'Hà Nội', 'address' => 'Thôn Xuân Sơn', 'lat' => 21.3, 'lng' => 105.8],
        ['title' => 'Văn phòng'],
    ]]);

    $org = collect(techLd(test()->get('http://mau.platform.test/gioi-thieu')->getContent()))->firstWhere('@type', 'Organization');

    expect($org['location'])->toHaveCount(1)
        ->and($org['location'][0]['name'])->toBe('Nhà máy số 1')
        ->and($org['location'][0]['address']['streetAddress'])->toBe('Thôn Xuân Sơn')
        ->and($org['location'][0]['geo'])->toBe(['@type' => 'GeoCoordinates', 'latitude' => 21.3, 'longitude' => 105.8]);
});

it('points the previous link of the second list page at the first page itself', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $news = StorefrontCards::news();
        $at = CarbonImmutable::parse('2026-10-01 08:00', 'UTC');
        foreach (range(1, 14) as $n) {
            StorefrontCards::post($news, "Bài {$n}", "bai-{$n}", $at);
        }
    });

    $html = test()->get('http://mau.platform.test/tin-tuc?page=2')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="prev" href="http://mau.platform.test/tin-tuc">')
        ->not->toContain('?page=1');
});
