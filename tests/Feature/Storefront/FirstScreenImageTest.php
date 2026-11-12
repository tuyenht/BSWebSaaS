<?php

use App\Modules\Tenancy\Context\Tenancy;
use Tests\Fixtures\StorefrontCards;

/*
 * A page asks for exactly one image early: the first-screen photo of whatever block leads it (a hero,
 * a slider, a title band, an about block, a gallery, a video poster, or the first card photo of a post
 * or product grid). Every other image on the page — further slides, cards, gallery photos, logos, a
 * hero lower down — is lazy and low priority, so on a phone it never shares the link with the main
 * image. Generic: any template, any site.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/**
 * The page's img tags that carry a loading attribute (the header logo has none: it is never lazy).
 *
 * @return list<string>
 */
function firstScreenImgs(string $html): array
{
    preg_match_all('~<img\b[^>]*\sloading="[^"]+"[^>]*>~', $html, $tags);

    return $tags[0];
}

it('gives the one early image to the first block\'s photo and makes every other image wait', function (string $lead, string $class): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($lead): void {
        $photo = StorefrontCards::media('anh-chinh');
        $other = StorefrontCards::media('anh-phu', 1200, 800);
        $news = StorefrontCards::news();
        foreach (['mot', 'hai', 'ba'] as $n) {
            StorefrontCards::post($news, 'Bài '.$n, 'bai-'.$n, null, ['image_url' => StorefrontCards::media('bai-'.$n, 960, 600)->storage_key], 'Tóm tắt.');
            StorefrontCards::product('Sản phẩm '.$n, 'san-pham-'.$n, null, StorefrontCards::media('sp-'.$n, 960, 640));
        }
        $first = match ($lead) {
            'hero' => ['type' => 'hero', 'props' => ['heading' => 'Gia công chính xác', 'image_url' => $photo->id]],
            'slider' => ['type' => 'hero_slider', 'props' => ['slides' => [
                ['image_url' => $photo->id, 'heading' => 'Một'], ['image_url' => $other->id, 'heading' => 'Hai'], ['image_url' => $other->id, 'heading' => 'Ba'],
            ]]],
            'band' => ['type' => 'page_header', 'props' => ['title' => 'Dải tiêu đề', 'image_url' => $photo->id]],
            'about' => ['type' => 'about', 'props' => ['heading' => 'Về chúng tôi', 'image_url' => $photo->id]],
            'gallery' => ['type' => 'gallery', 'props' => ['heading' => 'Hình ảnh', 'images' => [['image' => $photo->id], ['image' => $other->id], ['image' => $other->id]]]],
            'video' => ['type' => 'video', 'props' => ['heading' => 'Xưởng', 'url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'poster' => $photo->id]],
            'news cards' => ['type' => 'entry_grid', 'props' => ['heading' => 'Tin', 'taxonomy_slug' => 'tin-tuc', 'variant' => 'news-cards']],
            'zigzag' => ['type' => 'entry_grid', 'props' => ['heading' => 'Tin', 'taxonomy_slug' => 'tin-tuc', 'variant' => 'zigzag']],
            'post list' => ['type' => 'entry_grid', 'props' => ['heading' => 'Tin', 'taxonomy_slug' => 'tin-tuc']],
            'product grid' => ['type' => 'product_grid', 'props' => ['heading' => 'Sản phẩm', 'variant' => 'grid', 'layout' => 'grid']],
            default => throw new LogicException($lead),
        };
        StorefrontCards::page('/khoi', 'Các khối', [
            $first,
            ['type' => 'about', 'props' => ['heading' => 'Năng lực', 'image_url' => $other->id]],
            ['type' => 'gallery', 'props' => ['heading' => 'Xưởng', 'images' => [['image' => $other->id], ['image' => $photo->id]]]],
            ['type' => 'hero', 'props' => ['heading' => 'Liên hệ', 'image_url' => $other->id]],
            ['type' => 'product_grid', 'props' => ['heading' => 'Thêm sản phẩm', 'variant' => 'grid', 'layout' => 'grid']],
        ]);
    });

    $imgs = firstScreenImgs(test()->get('http://mau.platform.test/khoi')->assertOk()->getContent());
    $high = array_values(array_filter($imgs, fn (string $tag): bool => str_contains($tag, 'fetchpriority="high"')));
    $rest = array_values(array_filter($imgs, fn (string $tag): bool => ! str_contains($tag, 'fetchpriority="high"')));

    expect($high)->toHaveCount(1)
        ->and($high[0])->toContain('class="'.$class)
        ->and($high[0])->toContain('loading="eager"')
        ->and($rest)->not->toBeEmpty();
    foreach ($rest as $tag) {
        expect($tag)->toContain('loading="lazy"')->toContain('fetchpriority="low"');
    }
})->with([
    'a hero' => ['hero', 'hero__bg'],
    'a slider' => ['slider', 'sf-slide__img'],
    'a title band' => ['band', 'page-band__bg'],
    'an about block' => ['about', 'about__image'],
    'a gallery' => ['gallery', 'sf-gallery__img'],
    'a video' => ['video', 'sf-video__poster'],
    'a news card grid' => ['news cards', 'sf-card__img'],
    'a zigzag list' => ['zigzag', 'sf-zigzag__img'],
    'a plain post list' => ['post list', 'entry-grid__image'],
    'a product grid' => ['product grid', 'entry-grid__image'],
]);

it('keeps the slides after the first lazy, and on a page led by text gives the claim to the first block with an image', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $photo = StorefrontCards::media('anh-chinh');
        $slides = ['type' => 'hero_slider', 'props' => ['slides' => [
            ['image_url' => $photo->id, 'heading' => 'Một'], ['image_url' => $photo->id, 'heading' => 'Hai'], ['image_url' => $photo->id, 'heading' => 'Ba'],
        ]]];
        StorefrontCards::page('/truot', 'Trượt', [$slides]);
        StorefrontCards::page('/chu', 'Chữ', [['type' => 'richtext', 'props' => ['heading' => 'Giới thiệu']], ['type' => 'cta', 'props' => ['heading' => 'Liên hệ', 'button_label' => 'Gọi', 'button_url' => '/lien-he']], $slides,
            ['type' => 'about', 'props' => ['heading' => 'Về chúng tôi', 'image_url' => $photo->id]]]);
    });

    $slider = firstScreenImgs(test()->get('http://mau.platform.test/truot')->assertOk()->getContent());
    $text = firstScreenImgs(test()->get('http://mau.platform.test/chu')->assertOk()->getContent());

    expect($slider)->toHaveCount(3)
        ->and($slider[0])->toContain('fetchpriority="high"')
        ->and($slider[1])->toContain('loading="lazy"')->toContain('fetchpriority="low"')
        ->and($slider[2])->toContain('loading="lazy"')->toContain('fetchpriority="low"')
        ->and($text)->toHaveCount(4)
        ->and($text[0])->toContain('class="sf-slide__img"')->toContain('loading="eager" fetchpriority="high"')
        ->and(implode('', array_slice($text, 1)))->not->toContain('fetchpriority="high"');
});

it('offers phone-sized widths for the photos of a plain post list and a product grid', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        StorefrontCards::post(StorefrontCards::news(), 'Bài một', 'bai-mot', null, ['image_url' => StorefrontCards::media('bai-mot', 1600, 1000)->storage_key]);
        StorefrontCards::product('Sản phẩm một', 'san-pham-mot', null, StorefrontCards::media('sp-mot', 1600, 1000));
        StorefrontCards::page('/khoi', 'Các khối', [
            ['type' => 'entry_grid', 'props' => ['heading' => 'Tin', 'taxonomy_slug' => 'tin-tuc']],
            ['type' => 'product_grid', 'props' => ['heading' => 'Sản phẩm', 'variant' => 'grid', 'layout' => 'grid']],
        ]);
    });

    $imgs = array_values(array_filter(firstScreenImgs(test()->get('http://mau.platform.test/khoi')->assertOk()->getContent()), fn (string $tag): bool => str_contains($tag, 'entry-grid__image')));

    expect($imgs)->toHaveCount(2);
    foreach ($imgs as $tag) {
        expect($tag)->toMatch('~srcset="[^"]*\?w=320 320w, [^"]*\?w=640 640w~')->toContain('sizes="(min-width:1024px) 22rem');
    }
});

it('lets a first block with a background photo hold the claim through its wrapper', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $photo = StorefrontCards::media('nen');
        StorefrontCards::page('/nen', 'Nền', [
            ['type' => 'richtext', 'props' => ['heading' => 'Một', 'bg' => 'image', 'bg_image' => $photo->id]],
            ['type' => 'about', 'props' => ['heading' => 'Về chúng tôi', 'image_url' => $photo->id]],
        ]);
    });

    $imgs = firstScreenImgs(test()->get('http://mau.platform.test/nen')->assertOk()->getContent());

    expect($imgs)->toHaveCount(2)
        ->and($imgs[0])->toContain('class="sf-s__img"')->toContain('fetchpriority="high"')
        ->and($imgs[1])->toContain('loading="lazy"')->toContain('fetchpriority="low"');
});

it('keeps the claim on a post cover: a body led by text does not pass it to a later photo', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $post = StorefrontCards::post(StorefrontCards::news(), 'Bài có ảnh', 'bai-co-anh', null, ['image_url' => StorefrontCards::media('bia')->storage_key]);
        $post->translations()->update(['body' => ['blocks' => [
            ['type' => 'richtext', 'props' => ['heading' => 'Mở đầu']],
            ['type' => 'about', 'props' => ['heading' => 'Xưởng', 'image_url' => StorefrontCards::media('xuong')->id]],
        ]]]);
    });

    $imgs = firstScreenImgs(test()->get('http://mau.platform.test/tin-tuc/bai-co-anh')->assertOk()->getContent());
    $high = array_values(array_filter($imgs, fn (string $tag): bool => str_contains($tag, 'fetchpriority="high"')));
    $about = array_values(array_filter($imgs, fn (string $tag): bool => str_contains($tag, 'about__image')));

    expect($high)->toHaveCount(1)
        ->and($high[0])->toContain('class="post-cover__img"')
        ->and($about)->toHaveCount(1)
        ->and($about[0])->toContain('loading="lazy"')->toContain('fetchpriority="low"');
});
