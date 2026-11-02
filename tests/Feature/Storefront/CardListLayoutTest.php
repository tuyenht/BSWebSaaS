<?php

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * Category lists as card grids (list templates grid-news / grid-project / grid-product): the title
 * band is the page's one h1 and card titles are h2; the first photo is high priority, the next two
 * eager; child-category chips link to the children's own pages; a parent lists its whole subtree;
 * a category under the catalog root lists its products on either layout; dates are the site's
 * local day; empty lists say so; another site's rows never show.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** A site with a news tree (2 routed children + 1 unrouted) and a catalog with a child category. */
function cardSite(string $name = 'Xưởng Mẫu', string $host = 'mau.platform.test', ?string $newsTemplate = 'grid-news', ?string $catalogTemplate = 'grid-product'): int
{
    $siteId = StorefrontCards::site($name, $host);
    app(Tenancy::class)->enter($siteId, function () use ($newsTemplate, $catalogTemplate, $name): void {
        $photo = StorefrontCards::media('anh-'.md5($name), 960, 600);
        $news = StorefrontCards::news();
        $news->update(['list_template' => $newsTemplate]);
        $internal = StorefrontCards::category('Tin nội bộ', 'tin-noi-bo', $news, $newsTemplate, 1);
        $industry = StorefrontCards::category('Tin ngành', 'tin-nganh', $news, $newsTemplate, 2);
        $hidden = StorefrontCards::category('Lưu trữ', 'luu-tru', $news, $newsTemplate, 3, false);

        $at = fn (int $day): CarbonImmutable => CarbonImmutable::parse('2026-10-01 08:00', 'UTC')->addDays($day);
        StorefrontCards::post($news, 'Tin gốc một', 'tin-goc-mot', $at(1), ['image_url' => $photo->storage_key], 'Tóm tắt gốc.');
        StorefrontCards::post($news, 'Tin gốc hai', 'tin-goc-hai', $at(2), ['image_url' => $photo->storage_key]);
        StorefrontCards::post($internal, 'Họp toàn xưởng', 'hop-toan-xuong', CarbonImmutable::parse('2026-10-09 23:30', 'UTC'), ['image_url' => $photo->storage_key]);
        StorefrontCards::post($internal, 'Đào tạo an toàn', 'dao-tao-an-toan', $at(4), ['image_url' => $photo->storage_key]);
        StorefrontCards::post($industry, 'Xu hướng CNC', 'xu-huong-cnc', $at(5), ['image_url' => $photo->storage_key]);
        StorefrontCards::post($hidden, 'Tin cũ', 'tin-cu', $at(0));

        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham', null, $catalogTemplate);
        StorefrontCards::catalog($catalog);
        $jigs = StorefrontCards::category('Đồ gá', 'do-ga', $catalog, $catalogTemplate, 1);
        $welding = StorefrontCards::category('Đồ gá hàn', 'do-ga-han', $jigs, $catalogTemplate, 1);
        StorefrontCards::category('Khuôn', 'khuon', $catalog, $catalogTemplate, 2);
        StorefrontCards::product('Jig kiểm VF7', 'jig-vf7', $jigs, $photo, $at(3));
        StorefrontCards::product('Gá hàn sườn', 'ga-han-suon', $welding, $photo, $at(2));
        StorefrontCards::product('Bàn máp', 'ban-map', $catalog, $photo, $at(1));
    });

    return $siteId;
}

function cardPage(string $path, string $host = 'mau.platform.test'): string
{
    return test()->get('http://'.$host.$path)->assertOk()->getContent();
}

it('makes the title band the one h1 and card titles h2', function (string $path): void {
    cardSite();
    $html = cardPage($path);

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toMatch('#<h1 class="page-band__title">[^<]+</h1>#')
        ->and($html)->toContain('<h2 class="sf-card__title"><a class="sf-card__link"')
        ->and($html)->not->toContain('<h3 class="sf-card__title"');
})->with(['/tin-tuc', '/san-pham']);

it('loads the first photo with high priority, the next two eagerly and the rest lazily', function (): void {
    cardSite();
    preg_match_all('#<img class="sf-card__img"[^>]*>#', cardPage('/tin-tuc'), $images);

    expect($images[0])->toHaveCount(5)
        ->and($images[0][0])->toContain('loading="eager" fetchpriority="high"')
        ->and($images[0][1])->toContain('loading="eager" decoding="async"')
        ->and($images[0][2])->toContain('loading="eager" decoding="async"')
        ->and($images[0][3])->toContain('loading="lazy"')
        ->and($images[0][4])->toContain('loading="lazy"');
});

it('frames photos by kind: 8:5 for news and projects, 4:3 for products', function (): void {
    cardSite();
    expect(cardPage('/tin-tuc'))->toContain('sf-card--news')->toContain('sf-card__frame--8x5')->not->toContain('sf-card__frame--4x3')
        ->and(cardPage('/san-pham'))->toContain('sf-card--product')->toContain('sf-card__frame--4x3');

    app(Tenancy::class)->enter(1, fn () => StorefrontCards::news()->update(['list_template' => 'grid-project']));
    expect(cardPage('/tin-tuc'))->toContain('sf-card--project')->toContain('<span class="sf-card__shade" aria-hidden="true"></span>');
});

it('links chips to the routed children, marks the current one and skips a child without a route', function (): void {
    cardSite();
    $parent = cardPage('/tin-tuc');
    $child = cardPage('/tin-noi-bo');

    expect($parent)->toContain('<nav class="sf-chips" aria-label="Chuyên mục">')
        ->and($parent)->toContain('<a class="sf-chips__chip" href="/tin-tuc" aria-current="page">Tất cả</a>')
        ->and($parent)->toContain('<a class="sf-chips__chip" href="/tin-noi-bo">Tin nội bộ</a>')
        ->and($parent)->toContain('<a class="sf-chips__chip" href="/tin-nganh">Tin ngành</a>')
        ->and($parent)->not->toMatch('#class="sf-chips__chip"[^>]*>Lưu trữ#')
        ->and(strpos($parent, 'Tin nội bộ</a>'))->toBeLessThan(strpos($parent, 'Tin ngành</a>'))
        ->and($child)->toContain('<a class="sf-chips__chip" href="/tin-tuc">Tất cả</a>')
        ->and($child)->toContain('<a class="sf-chips__chip" href="/tin-noi-bo" aria-current="page">Tin nội bộ</a>');
});

it('lists the whole subtree on a card layout and only its own posts on the titles layout', function (): void {
    cardSite();
    $cards = cardPage('/tin-tuc');
    expect($cards)->toContain('Họp toàn xưởng')->toContain('Xu hướng CNC')->toContain('Tin gốc một')->toContain('Tin cũ')
        ->and($cards)->toContain('<span class="sf-card__chip">Tin nội bộ</span>');

    app(Tenancy::class)->enter(1, fn () => StorefrontCards::news()->update(['list_template' => null]));
    $titles = cardPage('/tin-tuc');
    expect($titles)->toContain('Tin gốc một')->toContain('Tin gốc hai')
        ->and($titles)->not->toContain('Họp toàn xưởng')->not->toContain('Xu hướng CNC');
});

it('lists a child product category’s products on either layout', function (?string $template, array $shown, array $hidden): void {
    cardSite(catalogTemplate: $template);
    $html = cardPage('/do-ga');

    foreach ($shown as $name) {
        expect($html)->toContain($name);
    }
    foreach ($hidden as $name) {
        expect($html)->not->toContain($name);
    }
    expect($html)->not->toContain(__('storefront.no_posts'));
})->with([
    'card layout: the subtree' => ['grid-product', ['Jig kiểm VF7', 'Gá hàn sườn'], ['Bàn máp']],
    'titles layout: its own' => [null, ['Jig kiểm VF7'], ['Gá hàn sườn', 'Bàn máp']],
]);

it('dates news cards by the site’s local day', function (): void {
    cardSite();

    expect(cardPage('/tin-noi-bo'))->toContain('<time class="sf-card__date" datetime="2026-10-10">10/10/2026</time>');
});

it('says so when a list is empty, with a way back to all', function (): void {
    cardSite();
    expect(cardPage('/khuon'))->toContain('<div class="sf-empty-box">')->toContain('Chưa có sản phẩm nào.')
        ->toContain('<a class="sf-empty-box__link" href="/san-pham">Tất cả</a>');

    app(Tenancy::class)->enter(1, fn () => StorefrontCards::category('Tin trống', 'tin-trong', StorefrontCards::news(), 'grid-news', 4));
    expect(cardPage('/tin-trong'))->toContain('Chưa có bài viết nào.')->toContain('href="/tin-tuc">Tất cả</a>');
});

it('never shows another site’s categories or products', function (): void {
    cardSite();
    $other = cardSite('Xưởng Khác', 'khac.platform.test');
    app(Tenancy::class)->enter($other, function (): void {
        $catalog = Taxonomy::query()->whereHas('translations', fn ($q) => $q->where('slug', 'do-ga'))->firstOrFail();
        StorefrontCards::category('Chỉ ở site khác', 'chi-o-site-khac', $catalog, 'grid-product', 5);
        StorefrontCards::product('Sản phẩm site khác', 'san-pham-site-khac', $catalog, null);
    });

    $html = cardPage('/do-ga').cardPage('/san-pham').cardPage('/tin-tuc');

    expect($html)->not->toContain('Chỉ ở site khác')->not->toContain('Sản phẩm site khác')
        ->and(cardPage('/do-ga', 'khac.platform.test'))->toContain('Sản phẩm site khác')->toContain('Chỉ ở site khác');
});
