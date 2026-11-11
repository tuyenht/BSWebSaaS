<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\BlockRenderer;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * The head tags that describe a page to browsers and share cards (theme colour, share-image size and
 * alt text, the other languages' locales, a post's publish and change times), the media-host preconnect
 * for a page's main image, the page's single high-priority image, and small partner logos that offer
 * small widths. All from data the page already holds.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** The page's `<img>` tags that ask for high priority. */
function headHighImages(string $html): int
{
    return preg_match_all('~<img\b[^>]*\sfetchpriority="high"~', $html);
}

it('draws a partner logo with a small-width srcset, and none when the master is already small', function (): void {
    $siteId = StorefrontCards::site();

    $html = app(Tenancy::class)->enter($siteId, function (): string {
        $wide = StorefrontCards::media('logo-wide', 1328, 240);
        $small = StorefrontCards::media('logo-small', 300, 120);

        return app(BlockRenderer::class)->render(['blocks' => [['type' => 'partners', 'props' => ['logos' => [
            ['image_url' => $wide->id, 'name' => 'Rộng'],
            ['image_url' => $small->id, 'name' => 'Nhỏ'],
        ]]]]]);
    });

    expect(preg_match_all('~<img class="partners__logo"[^>]*>~', $html, $tags))->toBe(2);
    expect($tags[0][0])->toMatch('~srcset="[^"]*\?w=320 320w, [^"]*\?w=640 640w" sizes="210px"~')
        ->and($tags[0][0])->not->toContain('2048w')
        ->and($tags[0][1])->not->toContain('srcset=');
});

it('prints the brand colour as theme-color, and no apple-touch-icon', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'theme', 'key' => 'tokens'], ['value' => ['primary' => '#0ea5e9']]));

    $html = test()->get('http://mau.platform.test/')->assertOk()->getContent();

    expect($html)->toContain('<meta name="theme-color" content="#0ea5e9">')
        ->and($html)->not->toContain('apple-touch-icon');
});

it('prints the size and alt text of the site share image, and no size for a page image', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $share = StorefrontCards::media('share', 2400, 1260);
        SiteProfile::query()->firstOrFail()->update(['og_image_media_id' => $share->id]);
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Xưởng Mẫu']]);
        StorefrontCards::post(StorefrontCards::news(), 'Bài có ảnh', 'bai-co-anh', null, ['image_url' => StorefrontCards::media('cover')->storage_key]);
    });

    $home = test()->get('http://mau.platform.test/')->assertOk()->getContent();
    $post = test()->get('http://mau.platform.test/tin-tuc/bai-co-anh')->assertOk()->getContent();

    expect($home)->toContain('<meta property="og:image:width" content="1280">')
        ->and($home)->toContain('<meta property="og:image:height" content="672">')
        ->and($home)->toContain('<meta property="og:image:alt" content="Xưởng Mẫu">')
        ->and($home)->toContain('<meta name="twitter:image:alt" content="Xưởng Mẫu">')
        ->and($post)->toContain('<meta property="og:image:alt" content="Bài có ảnh">')
        ->and($post)->not->toContain('og:image:width');
});

it('lists the locales of the other languages the page is published in', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        SiteLocale::create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $entry->id]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/about', 'entry_id' => $entry->id]);
    });

    $html = test()->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:locale" content="vi_VN">')
        ->and($html)->toContain('<meta property="og:locale:alternate" content="en_US">')
        ->and($html)->not->toContain('og:locale:alternate" content="vi_VN"');
});

it('names no alternate locale the page has no translation in', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        SiteLocale::create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        StorefrontCards::post(StorefrontCards::news(), 'Chỉ tiếng Việt', 'chi-tieng-viet');
    });

    expect(test()->get('http://mau.platform.test/tin-tuc/chi-tieng-viet')->assertOk()->getContent())
        ->not->toContain('og:locale:alternate');
});

it('prints no alternate locale on a one-language site', function (): void {
    StorefrontCards::site();

    expect(test()->get('http://mau.platform.test/')->assertOk()->getContent())->not->toContain('og:locale:alternate');
});

it('prints a post\'s publish and change times, and no article times on a page', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        StorefrontCards::post(StorefrontCards::news(), 'Bài viết', 'bai-viet', CarbonImmutable::parse('2026-10-01 08:00:00', 'UTC'));
        StorefrontCards::page('/gioi-thieu', 'Giới thiệu', [['type' => 'richtext', 'props' => ['paragraphs' => ['Nội dung.']]]]);
    });

    $post = test()->get('http://mau.platform.test/tin-tuc/bai-viet')->assertOk()->getContent();
    $page = test()->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($post)->toMatch('~<meta property="article:published_time" content="2026-10-01T[^"]+">~')
        ->and($post)->toMatch('~<meta property="article:modified_time" content="[^"]+">~')
        ->and($page)->not->toContain('article:published_time');
});

it('preconnects to the media host and asks for one high-priority image on a post with a cover', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        StorefrontCards::post(StorefrontCards::news(), 'Bài có ảnh', 'bai-co-anh', null, ['image_url' => StorefrontCards::media('cover')->storage_key]);
        StorefrontCards::post(StorefrontCards::news(), 'Bài không ảnh', 'bai-khong-anh');
    });

    $with = test()->get('http://mau.platform.test/tin-tuc/bai-co-anh')->assertOk()->getContent();
    $without = test()->get('http://mau.platform.test/tin-tuc/bai-khong-anh')->assertOk()->getContent();

    expect($with)->toContain('<link rel="preconnect" href="https://files.test">')
        ->and(headHighImages($with))->toBe(1)
        ->and($with)->not->toMatch('~<img\b[^>]*fetchpriority="high"[^>]*loading="lazy"|<img\b[^>]*loading="lazy"[^>]*fetchpriority="high"~')
        ->and($without)->not->toContain('rel="preconnect"');
});

it('keeps one high-priority image on a page led by a title band, loaded eagerly', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $band = StorefrontCards::media('band', 1600, 400);
        StorefrontCards::page('/ve-chung-toi', 'Về chúng tôi', [
            ['type' => 'page_header', 'props' => ['subtitle' => 'Hơn 15 năm', 'image_url' => $band->id]],
            ['type' => 'richtext', 'props' => ['paragraphs' => ['Nội dung.']]],
        ]);
    });

    $html = test()->get('http://mau.platform.test/ve-chung-toi')->assertOk()->getContent();

    expect(headHighImages($html))->toBe(1)
        ->and($html)->toMatch('~<img\b[^>]*loading="eager"[^>]*fetchpriority="high"~');
});
