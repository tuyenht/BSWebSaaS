<?php

use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * A page's structured data is one @graph in one script: the business (Organization) and the website
 * are described in full on the home page only and pointed to by @id everywhere else; each page has a
 * WebPage node (a list a CollectionPage, a product an ItemPage) that is part of the website and names its
 * breadcrumb; the 404 prints none.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

const SD_HOST = 'http://mau.platform.test';
const SD_ORG = SD_HOST.'/#organization';
const SD_SITE = SD_HOST.'/#website';

function sdSite(): int
{
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => [
            'name' => 'Xưởng Mẫu', 'legal_name' => 'Công ty TNHH Xưởng Mẫu', 'phone' => '0900 00 00 00', 'email' => 'lienhe@mau.test',
            'logo' => 'https://files.test/logo.png', 'address_line' => 'Số 1 Đường Thử', 'province' => 'TP Hà Nội',
            'social_links' => ['facebook' => 'https://facebook.com/xuongmau'],
        ]]);
        StorefrontCards::page('/gioi-thieu', 'Giới thiệu', []);
        $news = StorefrontCards::news();
        StorefrontCards::post($news, 'Bài một', 'bai-mot', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));
    });

    return $site;
}

/** @param list<array<string, mixed>> $nodes @return list<string> */
function sdTypes(array $nodes): array
{
    return array_map(fn (array $node): string => (string) $node['@type'], $nodes);
}

it('prints exactly one valid JSON-LD script per page, with the business described in full on the home page', function (): void {
    sdSite();

    $html = $this->get(SD_HOST.'/')->assertOk()->getContent();
    preg_match_all('~<script type="application/ld\+json">~', $html, $scripts);
    $nodes = ldNodes($html);
    $orgs = array_values(array_filter($nodes, fn (array $n): bool => in_array($n['@type'], ['Organization', 'LocalBusiness'], true)));

    expect($scripts[0])->toHaveCount(1)
        ->and($orgs)->toHaveCount(1)
        ->and($orgs[0]['@id'])->toBe(SD_ORG)
        ->and($orgs[0])->toHaveKeys(['name', 'url', 'logo', 'address', 'contactPoint', 'sameAs', 'telephone', 'email'])
        ->and($orgs[0]['contactPoint'])->toBe(['@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '0900 00 00 00', 'email' => 'lienhe@mau.test'])
        ->and(ldNode($html, 'WebSite')['@id'])->toBe(SD_SITE)
        ->and(ldNode($html, 'WebSite')['publisher'])->toBe(['@id' => SD_ORG])
        ->and(ldNode($html, 'WebPage')['isPartOf'])->toBe(['@id' => SD_SITE]);
});

it('points to the business by @id on every other page instead of repeating it', function (): void {
    sdSite();

    foreach (['/gioi-thieu', '/tin-tuc', '/tin-tuc/bai-mot'] as $path) {
        $html = $this->get(SD_HOST.$path)->assertOk()->getContent();
        $nodes = ldNodes($html);

        expect(sdTypes($nodes))->not->toContain('Organization')->not->toContain('LocalBusiness')->not->toContain('WebSite');
        $page = collect($nodes)->first(fn (array $n): bool => str_ends_with((string) ($n['@id'] ?? ''), '#webpage'));
        expect($page['isPartOf'])->toBe(['@id' => SD_SITE])
            ->and($page['@id'])->toBe($page['url'].'#webpage');
    }
});

it('makes a list a CollectionPage that lists what it shows, and a post an Article published by the business', function (): void {
    sdSite();

    $list = $this->get(SD_HOST.'/tin-tuc')->assertOk()->getContent();
    $post = $this->get(SD_HOST.'/tin-tuc/bai-mot')->assertOk()->getContent();
    $collection = ldNode($list, 'CollectionPage');
    $article = ldNode($post, 'Article');

    expect($collection['mainEntity']['@type'])->toBe('ItemList')
        ->and($collection['mainEntity']['itemListElement'][0]['name'])->toBe('Bài một')
        ->and($collection['breadcrumb'])->toBe(['@id' => $collection['url'].'#breadcrumb'])
        ->and(ldNode($list, 'BreadcrumbList')['@id'])->toBe($collection['url'].'#breadcrumb')
        ->and($article['publisher'])->toBe(['@id' => SD_ORG])
        ->and($article['headline'])->toBe('Bài một')
        ->and(ldNode($post, 'WebPage'))->not->toBeNull();
});

it('describes a product with its category, its specification rows and the business, and never invents an offer', function (): void {
    $site = sdSite();
    app(Tenancy::class)->enter($site, function (): void {
        $category = StorefrontCards::category('Đồ gá', 'do-ga');
        StorefrontCards::catalog($category);
        $product = StorefrontCards::product('Đồ gá kiểm', 'do-ga-kiem', $category, StorefrontCards::media('do-ga'));
        ProductTranslation::query()->where('product_id', $product->id)->update(['description' => '<p>Đồ gá.</p><table><tbody><tr><th>Chất liệu</th><td>Thép SKD11</td></tr><tr><th>Độ cứng</th><td>58 HRC</td></tr></tbody></table>']);
    });

    $html = $this->get(SD_HOST.'/do-ga-kiem')->assertOk()->getContent();
    $product = ldNode($html, 'Product');

    expect($product['category'])->toBe('Đồ gá')
        ->and($product['brand'])->toBe(['@id' => SD_ORG])
        ->and($product['manufacturer'])->toBe(['@id' => SD_ORG])
        ->and($product['additionalProperty'])->toBe([
            ['@type' => 'PropertyValue', 'name' => 'Chất liệu', 'value' => 'Thép SKD11'],
            ['@type' => 'PropertyValue', 'name' => 'Độ cứng', 'value' => '58 HRC'],
        ])
        ->and($product)->not->toHaveKey('offers')
        ->and(ldNode($html, 'ItemPage'))->not->toBeNull()
        ->and(sdTypes(ldNodes($html)))->not->toContain('Organization');
});

it('leaves out the property list of a product that has no specification table', function (): void {
    $site = sdSite();
    app(Tenancy::class)->enter($site, fn () => StorefrontCards::product('Đồ gá trơn', 'do-ga-tron'));

    $product = ldNode($this->get(SD_HOST.'/do-ga-tron')->assertOk()->getContent(), 'Product');

    expect($product)->not->toHaveKey('additionalProperty')->not->toHaveKey('offers');
});

it('prints no structured data on a 404', function (): void {
    sdSite();

    $this->get(SD_HOST.'/khong-ton-tai')->assertNotFound()->assertDontSee('application/ld+json', false);
});
