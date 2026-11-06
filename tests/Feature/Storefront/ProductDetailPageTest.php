<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Storefront\Support\ProductDetail;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * The product page: the breadcrumb through the product's group, the group's name above the h1, a
 * photo switcher whose first photo is the page's one high-priority image and whose thumbnails are
 * plain links to the photos, and the other products of the same group (this site's only, by their
 * routes, never an id).
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** A site whose catalog has two groups; the main product has three photos. Returns the product. */
function productSite(string $name = 'Xưởng Mẫu', string $host = 'mau.platform.test', string $prefix = ''): Product
{
    $siteId = StorefrontCards::site($name, $host);

    return app(Tenancy::class)->enter($siteId, function () use ($prefix, $name): Product {
        $at = fn (int $day): CarbonImmutable => CarbonImmutable::parse('2026-10-01 08:00', 'UTC')->addDays($day);
        $photos = [
            StorefrontCards::media('anh-mot-'.md5($name), 1600, 1200),
            StorefrontCards::media('anh-hai-'.md5($name), 1600, 1200),
            StorefrontCards::media('anh-ba-'.md5($name), 1600, 1200),
        ];
        $catalog = StorefrontCards::category('Ấn phẩm cơ khí', 'an-pham', null, 'grid-product');
        StorefrontCards::catalog($catalog);
        $jigs = StorefrontCards::category('Đồ gá', 'do-ga', $catalog, 'grid-product', 1);
        $shafts = StorefrontCards::category('Ống và trục phụ', 'ong-truc', $catalog, 'grid-product', 2);

        $main = StorefrontCards::product($prefix.'Đồ gá kiểm độ', 'do-ga-kiem', $jigs, $photos[0], $at(5), 'Ấm áp, chắc chắn và bền bỉ.');
        $main->images()->create(['media_id' => $photos[1]->id, 'position' => 1]);
        $main->images()->create(['media_id' => $photos[2]->id, 'position' => 2]);
        StorefrontCards::product($prefix.'Đế gá máy cũ', 'de-ga-may', $jigs, $photos[1], $at(4));
        StorefrontCards::product($prefix.'Ống kẹp cỡ bé', 'ong-kep', $jigs, $photos[2], $at(3));
        StorefrontCards::product($prefix.'Ụ đỡ trục phụ', 'u-do-truc', $shafts, $photos[0], $at(6));

        return $main;
    });
}

function productHtml(string $host = 'mau.platform.test'): string
{
    return test()->get('http://'.$host.'/do-ga-kiem')->assertOk()->getContent();
}

it('leads with the breadcrumb through the group and the group name above the one h1', function (): void {
    productSite();
    $html = productHtml();

    preg_match('#<nav class="sf-crumbs sf-crumbs--dark product__crumbs".*?</nav>#s', $html, $crumbs);
    expect($crumbs[0] ?? '')->toContain('>Ấn phẩm cơ khí</a>')->toContain('>Đồ gá</a>')->toContain('Đồ gá kiểm độ')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<p class="product__group">Đồ gá</p>')
        ->and($html)->toContain('<h1 class="product__title">Đồ gá kiểm độ</h1>')
        ->and($html)->toContain('<p class="product__summary">Ấm áp, chắc chắn và bền bỉ.</p>')
        // The BreadcrumbList names the same levels in the same order.
        ->and($html)->toMatch('#"name":"Ấn phẩm cơ khí".*"name":"Đồ gá".*"name":"Đồ gá kiểm độ"#s');
});

it('prints exactly one high-priority image: the first photo, with its srcset; the rest load lazily', function (): void {
    productSite();
    preg_match_all('#<img\b[^>]*>#', productHtml(), $images);
    $high = array_values(array_filter($images[0], fn (string $img): bool => str_contains($img, 'fetchpriority="high"')));

    expect($high)->toHaveCount(1)
        ->and($high[0])->toContain('class="product__image"')->toContain('srcset="')->toContain('loading="eager"');
    $thumbs = array_filter($images[0], fn (string $img): bool => str_contains($img, 'product__thumb-img'));
    expect($thumbs)->toHaveCount(3);
    foreach ($thumbs as $thumb) {
        expect($thumb)->toContain('loading="lazy"')->not->toContain('fetchpriority');
    }
});

it('links each thumbnail and the main photo to the real photo, in gallery order, with the counter', function (): void {
    productSite();
    $html = productHtml();
    preg_match_all('#<a class="product__thumb" href="([^"]+)"#', $html, $links);
    preg_match('#<a class="product__main" href="([^"]+)"#', $html, $main);

    expect($links[1])->toHaveCount(3)
        ->and(array_unique($links[1]))->toHaveCount(3)
        ->and($main[1])->toBe($links[1][0])
        ->and($html)->toContain('<span class="product__count" aria-live="polite" data-sf-gallery-count>1 / 3</span>')
        ->and(substr_count($html, 'data-sf-gallery-step'))->toBe(2);
    foreach ($links[1] as $index => $link) {
        expect($link)->toStartWith('https://files.test/')->toContain('anh-'.['mot', 'hai', 'ba'][$index]);
    }
});

it('shows a single photo without thumbnails, arrows or the counter', function (): void {
    $product = productSite();
    app(Tenancy::class)->enter(1, fn () => $product->images()->where('position', '>', 0)->delete());
    $html = productHtml();

    expect($html)->toContain('class="product__image"')
        ->not->toContain('product__thumbs')->not->toContain('product__nav')->not->toContain('data-sf-gallery-count');
});

it('lists the other products of the same group only, never another site’s, by their routes', function (): void {
    $product = productSite();
    productSite('Xưởng Khác', 'khac.platform.test', 'Ấn bản lạ ');
    $html = productHtml();

    preg_match('#<section class="post-related post-related--plain".*?</section>#s', $html, $related);
    $section = $related[0] ?? '';
    preg_match_all('#href="([^"]+)"#', $section, $hrefs);

    expect($section)->toContain('Sản phẩm cùng nhóm')
        ->toContain('>Đế gá máy cũ</a>')->toContain('>Ống kẹp cỡ bé</a>')
        ->not->toContain('Ụ đỡ trục phụ')        // another group
        ->not->toContain('Đồ gá kiểm độ</a>')    // the page's own product
        ->and($html)->not->toContain('Ấn bản lạ')  // another site's products
        ->and($hrefs[1])->toBe(['/de-ga-may', '/ong-kep']);
    foreach ($hrefs[1] as $href) {
        expect($href)->not->toMatch('#/\d+(/|$)#')->not->toContain((string) $product->id.'"');
    }
});

it('builds the contact box from the hotline (else the phone) and the Zalo contact, or none', function (): void {
    expect(ProductDetail::contact(['hotline' => '0915 66 83 83', 'phone' => '024 1234 5678', 'social_links' => ['zalo' => '0915668383']]))
        ->toMatchArray(['call' => 'tel:0915668383', 'number' => '0915 66 83 83'])
        ->and(ProductDetail::contact(['hotline' => '0915 66 83 83']))->toMatchArray(['zalo' => null])
        ->and(ProductDetail::contact(['phone' => '0915668383'])['call'] ?? null)->toBe('tel:0915668383')
        ->and((string) (ProductDetail::contact(['social_links' => ['zalo' => '0915668383']])['zalo'] ?? ''))->toContain('zalo.me/')
        ->and(ProductDetail::contact(['name' => 'Ấn phẩm cơ khí']))->toBeNull()
        ->and(ProductDetail::contact(null))->toBeNull();
});

it('loads the gallery script with three photos, not with one, and renders a product with no photo', function (): void {
    // Print each Vite entry as a module script so the head's choices are visible.
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
        }
    });
    $product = productSite();
    expect(productHtml())->toContain('<script type="module" src="/resources/js/sf-gallery.js"></script>');

    app(Tenancy::class)->enter(1, fn () => $product->images()->where('position', '>', 0)->delete());
    expect(productHtml())->not->toContain('resources/js/sf-gallery.js');

    app(Tenancy::class)->enter(1, fn () => $product->images()->delete());
    $html = productHtml();
    expect($html)->not->toContain('resources/js/sf-gallery.js')->not->toContain('product__image')
        ->not->toContain('product__main')->not->toContain('product__gallery')
        ->toContain('<h1 class="product__title">Đồ gá kiểm độ</h1>');
});

it('orders an undated product first among the related ones, the same as in the list', function (): void {
    $product = productSite();
    app(Tenancy::class)->enter(1, function () use ($product): void {
        $undated = StorefrontCards::product('Mẫu chưa ngày', 'mau-chua-ngay', $product->primaryCategory());
        Product::query()->whereKey($undated->id)->update(['published_at' => null]);
    });
    preg_match('#<section class="post-related post-related--plain".*?</section>#s', productHtml(), $related);
    preg_match_all('#href="([^"]+)"#', $related[0] ?? '', $hrefs);

    expect($hrefs[1][0] ?? null)->toBe('/mau-chua-ngay');
});

/** The badges' labels as printed in the main image's stage. */
function productBadges(string $html): array
{
    preg_match('#<ul class="product__badges">(.*?)</ul>#s', $html, $list);
    preg_match_all('#<li class="product__badge">([^<]+)</li>#', $list[1] ?? '', $labels);

    return $labels[1];
}

it('prints a badge on the main photo for each switch that is on, and none when both are off', function (bool $featured, bool $bestseller, array $expected): void {
    $product = productSite();
    app(Tenancy::class)->enter($product->site_id, fn () => $product->update(['is_featured' => $featured, 'is_bestseller' => $bestseller]));
    $html = productHtml();

    expect(productBadges($html))->toBe($expected);
    if ($expected === []) {
        expect($html)->not->toContain('product__badges');
    }
    // The badge text sits beside the photo link, never inside its accessible name.
    preg_match('#<a class="product__main".*?</a>#s', $html, $link);
    expect($link[0] ?? '')->not->toContain('product__badge');
})->with([
    'both on' => [true, true, ['Nổi bật', 'Bán chạy']],
    'featured only' => [true, false, ['Nổi bật']],
    'bestseller only' => [false, true, ['Bán chạy']],
    'neither' => [false, false, []],
]);

it('adds the quote button first in the contact box when the site has a contact page, built through its path', function (): void {
    productSite();
    $html = productHtml();

    expect($html)->toContain('<a class="product__quote" href="/lien-he">Yêu cầu báo giá</a>')
        ->and(substr_count($html, 'product__quote"'))->toBe(1)
        ->and($html)->not->toMatch('#/lien-he/[0-9]#')
        ->and(strpos($html, 'product__quote"'))->toBeLessThan(strpos($html, 'product__zalo') ?: PHP_INT_MAX);
    preg_match_all('#<img\b[^>]*\sfetchpriority="high"#', $html, $high);
    expect($high[0])->toHaveCount(1);
});

it('leaves the quote button out when the site has no published contact page', function (): void {
    $product = productSite();
    app(Tenancy::class)->enter($product->site_id, function (): void {
        $entry = SiteRoute::query()->where('path', '/lien-he')->firstOrFail()->entry;
        $entry->update(['status' => 'draft']);
    });
    expect(productHtml())->not->toContain('product__quote');

    app(Tenancy::class)->enter($product->site_id, fn () => SiteRoute::query()->where('path', '/lien-he')->delete());
    expect(productHtml())->not->toContain('product__quote');
});

it('never uses another site’s contact page for the quote button', function (): void {
    $mine = productSite();
    $other = productSite('Xưởng Khác', 'khac.platform.test', 'Khác ');
    app(Tenancy::class)->enter($mine->site_id, fn () => SiteRoute::query()->where('path', '/lien-he')->delete());

    expect(productHtml())->not->toContain('product__quote')
        ->and(productHtml('khac.platform.test'))->toContain('product__quote');
    expect($mine->site_id)->not->toBe($other->site_id);
});
