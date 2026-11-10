<?php

use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * A site that opts into none of the new block looks, list layouts or tokens renders the same bytes
 * as before they existed: every existing block with its existing props, a page led by a title band,
 * the default category list (two pages), the product index and the home page. The pinned files were
 * captured before the card layouts, the slider and the block variants were added.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    config(['cms.map_hosts' => ['www.google.com']]);
});

/** Seeds the golden site: every default block on /khoi, a band-led page, 14 news posts, 2 products. */
function unchangedSite(): void
{
    $siteId = StorefrontCards::site();

    app(Tenancy::class)->enter($siteId, function (): void {
        $hero = StorefrontCards::media('hero');
        $about = StorefrontCards::media('about', 1200, 800);
        $logo = StorefrontCards::media('logo', 320, 160);
        $band = StorefrontCards::media('band', 1600, 400);
        $card = StorefrontCards::media('card', 960, 720);

        $services = StorefrontCards::category('Dịch vụ', 'dich-vu');
        StorefrontCards::post($services, 'Gia công CNC', 'gia-cong-cnc', CarbonImmutable::parse('2026-10-02 08:00', 'UTC'), ['image_url' => 'shield'], 'Phay, tiện chính xác.');
        StorefrontCards::post($services, 'Đồ gá hàn', 'do-ga-han', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), ['image_url' => $card->storage_key], 'Đồ gá cho dây chuyền.');

        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham');
        StorefrontCards::catalog($catalog);
        StorefrontCards::product('Jig kiểm VF7', 'jig-vf7', $catalog, $card, CarbonImmutable::parse('2026-10-02 08:00', 'UTC'), 'Đồ gá kiểm tra.');
        StorefrontCards::product('Khuôn dập', 'khuon-dap', $catalog, null, CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));

        $news = StorefrontCards::news();
        foreach (range(1, 14) as $n) {
            StorefrontCards::post($news, "Tin số {$n}", "tin-so-{$n}", CarbonImmutable::parse('2026-09-01 08:00', 'UTC')->addDays($n));
        }

        StorefrontCards::page('/khoi', 'Các khối', [
            ['type' => 'hero', 'props' => ['heading' => 'Cơ khí chính xác', 'subheading' => 'Từ bản vẽ đến sản phẩm', 'image_url' => $hero->id,
                'cta_label' => 'Báo giá', 'cta_url' => '/lien-he', 'cta2_label' => 'Sản phẩm', 'cta2_url' => '/san-pham']],
            ['type' => 'richtext', 'props' => ['heading' => 'Giới thiệu', 'paragraphs' => ['Đoạn một.', 'Đoạn hai.']]],
            ['type' => 'features', 'props' => ['heading' => 'Năng lực', 'items' => [['title' => 'Nhà xưởng', 'text' => '12.000 m²'], ['title' => 'Máy CNC']]]],
            ['type' => 'about', 'props' => ['heading' => 'Về chúng tôi', 'paragraphs' => ['Thành lập 2010.'], 'image_url' => $about->id, 'cta_label' => 'Xem thêm', 'cta_url' => '/gioi-thieu']],
            ['type' => 'partners', 'props' => ['heading' => 'Đối tác', 'logos' => [['image_url' => $logo->id, 'name' => 'Đối tác A', 'url' => 'https://a.example'], ['image_url' => $logo->id, 'name' => 'Đối tác B']]]],
            ['type' => 'cta', 'props' => ['heading' => 'Cần báo giá?', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he']],
            ['type' => 'contact', 'props' => ['heading' => 'Liên hệ', 'address' => 'Số 1 Đường Thử', 'phone' => '0901234567', 'email' => 'lienhe@mau.test',
                'map_embed' => 'https://www.google.com/maps/embed?pb=1']],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Dịch vụ', 'taxonomy_slug' => 'dich-vu', 'limit' => 6]],
            ['type' => 'product_grid', 'props' => ['heading' => 'Sản phẩm', 'limit' => 6]],
            ['type' => 'latest_posts', 'props' => ['heading' => 'Tin mới', 'limit' => 3]],
            ['type' => 'form', 'props' => ['form_key' => 'contact']],
            ['type' => 'page_header', 'props' => ['title' => 'Dải tiêu đề', 'subtitle' => 'Phụ đề', 'image_url' => $band->id, 'overlay' => 60]],
        ]);

        StorefrontCards::page('/ve-chung-toi', 'Về chúng tôi', [
            ['type' => 'page_header', 'props' => ['subtitle' => 'Hơn 15 năm', 'image_url' => $band->id]],
            ['type' => 'richtext', 'props' => ['paragraphs' => ['Nội dung.']]],
        ]);
    });
}

/** The page with the current year masked (the footer prints it). */
function unchangedHtml(string $html): string
{
    return str_replace(date('Y'), '{year}', $html);
}

it('renders every existing block, a band-led page, the lists and the home page byte for byte', function (string $path, string $golden): void {
    unchangedSite();

    $html = unchangedHtml($this->get('http://mau.platform.test'.$path)->assertOk()->getContent());

    expect($html)->toBe(file_get_contents(__DIR__.'/golden/'.$golden));
})->with([
    'every default block' => ['/khoi', 'blocks-default.html'],
    'a page led by a title band' => ['/ve-chung-toi', 'band-led-page.html'],
    'the default category list' => ['/tin-tuc', 'category-default.html'],
    'its second page' => ['/tin-tuc?page=2', 'category-default-page-2.html'],
    'the product index' => ['/san-pham', 'product-index-default.html'],
    'the home page' => ['/', 'home.html'],
]);

/** The number of images that ask for high priority (the attribute on an img tag, never the bare text). */
function highImages(string $html): int
{
    return (int) preg_match_all('#<img\b[^>]*\sfetchpriority="high"#', $html);
}

it('prints exactly one high-priority image on the page of every default block', function (): void {
    unchangedSite();

    $html = test()->get('http://mau.platform.test/khoi')->assertOk()->getContent();

    expect(highImages($html))->toBe(1)
        ->and(preg_match('#<img class="hero__bg"[^>]*fetchpriority="high"#', $html))->toBe(1);
});

it('gives the one high-priority image to the first block only, whatever follows', function (array $order, int $high, ?string $class): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($order): void {
        $photo = StorefrontCards::media('anh');
        $blocks = array_map(fn (string $type): array => match ($type) {
            'hero' => ['type' => 'hero', 'props' => ['heading' => 'Ống thép cỡ nhỏ', 'image_url' => $photo->id]],
            'about' => ['type' => 'about', 'props' => ['heading' => 'Ống thép cỡ nhỏ', 'image_url' => $photo->id]],
            'band' => ['type' => 'page_header', 'props' => ['title' => 'Dải tiêu đề', 'image_url' => $photo->id]],
            'slider' => ['type' => 'hero_slider', 'props' => ['slides' => [['image_url' => $photo->id, 'heading' => 'Ống thép cỡ nhỏ'], ['image_url' => $photo->id, 'heading' => 'Ống đồng nhỏ']]]],
            default => ['type' => 'richtext', 'props' => ['heading' => 'Ống thép cỡ nhỏ']],
        }, $order);
        StorefrontCards::page('/khoi', 'Các khối', $blocks);
    });

    $html = test()->get('http://mau.platform.test/khoi')->assertOk()->getContent();

    expect(highImages($html))->toBe($high);
    if ($class !== null) {
        expect(preg_match('#<img class="'.$class.'"[^>]*\sfetchpriority="high"#', $html))->toBe(1);
    }
})->with([
    'a hero then an about block' => [['hero', 'about'], 1, 'hero__bg'],
    'an about block then a hero' => [['about', 'hero'], 1, 'about__image'],
    'a title band then a hero' => [['band', 'hero'], 1, 'page-band__bg'],
    'a hero then a title band' => [['hero', 'band'], 1, 'hero__bg'],
    'a slider that is not first' => [['text', 'slider'], 0, null],
    'a slider that is first' => [['slider'], 1, null],
]);
