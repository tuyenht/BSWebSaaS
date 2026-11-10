<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductIndexLink;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * The floating contact buttons (`storefront.floating_contact`): printed once per page after the
 * footer, as plain links (tel:, sms:, mailto:, https), only while the setting is on and at least
 * one channel passes its check. "vertical" = round buttons on the chosen side; "horizontal" = the
 * phone bottom bar (without the chat-window channels). Values are rebuilt from what passed the
 * check, never printed as typed.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, product_translations, products, taxonomy_translations, taxonomies, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

function flcSite(string $name = 'Xưởng Mẫu', string $host = 'mau.platform.test'): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $name, siteName: $name, hostname: $host,
        ownerEmail: 'owner@'.$host, ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $siteId = Site::query()->firstWhere('name', $name)->id;

    app(Tenancy::class)->enter($siteId, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'Về chúng tôi', 'paragraphs' => ['Một đoạn.']]]]]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $entry->id]);
    });

    return $siteId;
}

/** @param  array<string, mixed>  $value */
function flcSetting(int $siteId, array $value): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'floating_contact'],
        ['value' => $value],
    ));
}

/**
 * @param  list<array<string, mixed>>  $channels
 * @return array<string, mixed>
 */
function flcConfig(array $channels, array $extra = []): array
{
    return $extra + ['enabled' => true, 'theme' => 'vertical', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24, 'channels' => $channels];
}

/** The floating-contact markup of a page (the nav and its spacer). */
function flcPart(string $html): string
{
    preg_match_all('~<div class="sf-fc-ghost.*?</div></div>|<div class="sf-fc-bar sf-fc-bar--ghost".*?</ul></div>|<nav class="sf-fc[ -].*?</nav>~s', $html, $m);

    return implode("\n", $m[0]);
}

it('prints nothing without the setting, when it is off, or when no channel passes', function (?array $value): void {
    $siteId = flcSite();
    if ($value !== null) {
        flcSetting($siteId, $value);
    }

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->not->toContain('sf-fc');
})->with([
    'no setting' => [null],
    'switched off' => [flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']], ['enabled' => false])],
    'no channel on' => [flcConfig([['key' => 'phone', 'on' => false, 'value' => '0912345678']])],
    'only bad values' => [flcConfig([['key' => 'phone', 'on' => true, 'value' => '12345'], ['key' => 'email', 'on' => true, 'value' => 'not-an-email']])],
    'bar with chat channels only' => [flcConfig([['key' => 'messenger', 'on' => true, 'value' => 'trang.mau']], ['theme' => 'horizontal'])],
    'not an array' => [['enabled' => true, 'channels' => 'phone']],
]);

it('links each channel exactly', function (string $key, string $value, string $href): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([['key' => $key, 'on' => true, 'value' => $value]]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('href="'.$href.'"')
        ->and(substr_count($part, '<a '))->toBe(1);
})->with([
    'phone with spaces' => ['phone', '0912 345 678', 'tel:0912345678'],
    'phone +84' => ['phone', '+84912345678', 'tel:+84912345678'],
    'landline' => ['phone', '024 1234 5678', 'tel:02412345678'],
    'hotline' => ['phone', '1900 1234', 'tel:19001234'],
    'whatsapp abroad' => ['whatsapp', '+65 9123 4567', 'https://wa.me/6591234567'],
    'zalo number' => ['zalo', '0912345678', 'https://zalo.me/0912345678'],
    'zalo link' => ['zalo', 'https://zalo.me/0912345678', 'https://zalo.me/0912345678'],
    'messenger page' => ['messenger', 'm.me/trang.mau', 'https://m.me/trang.mau'],
    'email' => ['email', 'lienhe@mau.test', 'mailto:lienhe@mau.test'],
    'sms' => ['sms', '0912345678', 'sms:0912345678'],
    'whatsapp' => ['whatsapp', '0912345678', 'https://wa.me/84912345678'],
    'telegram' => ['telegram', '@mau_shop', 'https://t.me/mau_shop'],
    'viber' => ['viber', '0912345678', 'viber://chat?number=%2B84912345678'],
    'tiktok' => ['tiktok', 'https://tiktok.com/@mau', 'https://tiktok.com/@mau'],
    'maps' => ['maps', 'https://maps.google.com/?q=Ha+Noi', 'https://maps.google.com/?q=Ha+Noi'],
    'custom path' => ['c1', '/lien-he', '/lien-he'],
    'custom tel' => ['c1', 'tel:0912345678', 'tel:0912345678'],
]);

it('never prints a script or data link, even in a channel of the site’s own', function (string $value): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'c1', 'on' => true, 'value' => $value, 'icon' => 'ri-global-line', 'name' => 'X'],
        ['key' => 'maps', 'on' => true, 'value' => $value],
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
    ]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect(substr_count($part, '<a '))->toBe(1)
        ->and($part)->toContain('href="tel:0912345678"')
        ->and(strtolower($part))->not->toContain('javascript')->not->toContain('data:');
})->with(['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<b>x</b>', '//evil.test/x']);

it('puts the buttons on the chosen side with the offsets, each named and drawn readable', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678', 'tips' => ['vi' => 'Chat ngay']],
        ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
    ], ['position' => 'left', 'offset_x' => 40, 'offset_y' => 100]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('<nav class="sf-fc sf-fc--left" aria-label="Liên hệ nhanh" style="--fc-ox:40px;--fc-oy:100px">')
        ->toContain('sf-fc__list--left')
        ->toContain('class="sf-fc__btn sf-fc__btn--pulse" href="tel:0912345678" aria-label="Gọi điện"')
        // a word printed on the button is part of its name, so speech input can reach it
        ->toContain('aria-label="Chat ngay Zalo"')
        ->toContain('<span class="sf-fc__word" aria-hidden="true">Zalo</span>')
        ->toContain('href="sms:0912345678" aria-label="Nhắn tin SMS"')
        // the spacer copies the buttons (same list classes), hidden from assistive tech
        ->toContain('<div class="sf-fc-ghost sf-fc-ghost--side" aria-hidden="true" style="--fc-ox:40px;--fc-oy:100px"><div class="sf-fc__list sf-fc__list--left">')
        ->and(substr_count($part, 'sf-fc__btn"></span>'))->toBe(3)
        ->and($part)->not->toContain('sf-fc-bar--ghost');
});

it('keeps a side stack’s room under the footer on a phone only, and the bottom row’s at every width', function (): void {
    // From 768px the side stack sits over the page's edge, as the design draws it: an empty dark
    // band under the footer would only push the page's end away. Below it, the room stays, with
    // the safe-area inset, so the stack never covers the last footer line.
    $css = (string) file_get_contents(resource_path('css/storefront.css'));

    expect($css)->toContain("@media (min-width: 768px) {\n    .sf-fc-ghost--side {\n        display: none;")
        ->toContain('padding: 0.625rem var(--fc-ox, 20px) calc(var(--fc-oy, 24px) + env(safe-area-inset-bottom, 0px));')
        ->not->toContain('--fc-n')
        ->and(preg_match('~\.sf-fc-ghost--row\s*\{[^}]*display:\s*none~', $css))->toBe(0);
});

it('reserves the bottom row’s room as a row', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']], ['position' => 'bottom']));

    expect(flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent()))
        ->toContain('<div class="sf-fc-ghost sf-fc-ghost--row" aria-hidden="true"')
        ->toContain('<div class="sf-fc__list sf-fc__list--bottom"><span class="sf-fc__btn"></span></div>');
});

it('falls back to the default side and offsets on unknown or odd values', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']], ['position' => 'top', 'offset_x' => 31, 'offset_y' => 999]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('<nav class="sf-fc sf-fc--right" aria-label="Liên hệ nhanh" style="--fc-ox:20px;--fc-oy:24px">');
});

it('draws the phone bottom bar with at most three channels and without the chat-window ones', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'messenger', 'on' => true, 'value' => 'trang.mau'],
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'email', 'on' => true, 'value' => 'lienhe@mau.test'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
        ['key' => 'whatsapp', 'on' => true, 'value' => '0912345678'],
    ], ['theme' => 'horizontal']));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc-bar".*?</nav>~s', $html, $bar);

    expect($bar[0] ?? '')->toContain('href="tel:0912345678"')
        ->toContain('href="https://zalo.me/0912345678"')
        ->toContain('href="sms:0912345678"')
        ->not->toContain('m.me')->not->toContain('mailto:')->not->toContain('wa.me')
        ->and(substr_count($bar[0] ?? '', '<a '))->toBe(3)
        ->and($html)->not->toContain('<nav class="sf-fc sf-fc--')
        ->toContain('sf-fc-bar sf-fc-bar--ghost');
});

/** A product list for the site at /san-pham: a products section on a category, one published product. */
function flcShop(int $siteId): void
{
    app(Tenancy::class)->enter($siteId, function (): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham']);
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'san-pham');
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm 1', 'slug' => 'sp-1']);
    });
}

/** @return array<string, mixed> */
function flcBarWithBuy(string $color = '#0ab39c'): array
{
    return flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
    ], ['theme' => 'horizontal', 'buy' => ['enabled' => true, 'color' => $color]]);
}

it('ends the phone bar with “Mua hàng” opening the product list, and keeps its room in the spacer', function (): void {
    $siteId = flcSite();
    flcShop($siteId);
    flcSetting($siteId, flcBarWithBuy());

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc-bar".*?</nav>~s', $html, $bar);
    preg_match('~<div class="sf-fc-bar sf-fc-bar--ghost".*?</ul></div>~s', $html, $ghost);

    expect($bar[0] ?? '')->toContain('href="tel:0912345678"')->toContain('href="https://zalo.me/0912345678"')
        ->not->toContain('sms:')
        ->toContain('href="/san-pham" style="--fc-fill:')
        ->toContain(';--fc-ink:#ffffff"')
        ->toContain('<span class="sf-fc-bar__label">Mua hàng</span>')
        ->and(substr_count($bar[0] ?? '', '<a '))->toBe(3)
        ->and(substr_count($ghost[0] ?? '', '<li class="sf-fc-bar__item">'))->toBe(3)
        ->and($ghost[0] ?? '')->toContain('Mua hàng');

    // The product list itself is reachable at that address.
    $this->get('http://mau.platform.test/san-pham')->assertOk()->assertSee('Sản phẩm 1');
});

it('uses the site’s brand fill and ink for a brand-coloured “Mua hàng”', function (): void {
    $siteId = flcSite();
    flcShop($siteId);
    flcSetting($siteId, flcBarWithBuy('brand'));

    expect($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent())
        ->toContain('href="/san-pham" style="--fc-fill:var(--color-primary);--fc-ink:var(--color-on-primary)"');
});

it('shows no “Mua hàng” and three channels while the site has no product list', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcBarWithBuy());

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc-bar".*?</nav>~s', $html, $bar);

    expect($bar[0] ?? '')->toContain('href="sms:0912345678"')->not->toContain('Mua hàng')
        ->and(substr_count($bar[0] ?? '', '<a '))->toBe(3);
});

it('prints the hover label of the page’s language and escapes every label', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678', 'tips' => ['vi' => '<script>alert(1)</script>"x']],
        ['key' => 'c3', 'on' => true, 'value' => 'https://example.com/a?b=1&c=2', 'icon' => 'ri-not-allowed', 'name' => 'Kênh <b>riêng</b>'],
    ]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->not->toContain('<script>')->not->toContain('<b>')
        ->toContain('aria-label="&lt;script&gt;alert(1)&lt;/script&gt;&quot;x"')
        ->toContain('aria-label="Kênh &lt;b&gt;riêng&lt;/b&gt;"')
        ->toContain('href="https://example.com/a?b=1&amp;c=2"');
});

it('shows a site only its own buttons', function (): void {
    $a = flcSite('Xưởng A', 'a.platform.test');
    $b = flcSite('Xưởng B', 'b.platform.test');
    flcSetting($a, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0911111111']]));
    flcSetting($b, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0922222222']]));

    expect($this->get('http://a.platform.test/gioi-thieu')->getContent())->toContain('tel:0911111111')->not->toContain('0922222222')
        ->and($this->get('http://b.platform.test/gioi-thieu')->getContent())->toContain('tel:0922222222')->not->toContain('0911111111');
});

it('adds no query to a page, and for “Mua hàng” only the product list lookup, once', function (): void {
    $siteId = flcSite();
    $this->get('http://mau.platform.test/gioi-thieu')->assertOk();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('http://mau.platform.test/gioi-thieu')->assertOk();
    $without = count(DB::getQueryLog());

    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']]));
    $this->get('http://mau.platform.test/gioi-thieu')->assertOk();
    DB::flushQueryLog();
    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    $with = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($html)->toContain('tel:0912345678')
        ->and($with)->toBe($without);

    // The phone bar with "Mua hàng": the product list's address is looked up once per page —
    // entitlement (2, remembered for the request), the products section, a published product and
    // its address — and a category page reuses the section and the product check it reads anyway.
    flcShop($siteId);
    $count = function (string $path): int {
        // What one request remembers starts empty, as on a fresh request.
        app()->forgetInstance(ProductIndexLink::class);
        app()->forgetInstance(Entitlement::class);
        $this->get('http://mau.platform.test'.$path)->assertOk();
        DB::flushQueryLog();
        app()->forgetInstance(ProductIndexLink::class);
        app()->forgetInstance(Entitlement::class);
        $this->get('http://mau.platform.test'.$path)->assertOk();

        return count(DB::getQueryLog());
    };
    DB::enableQueryLog();
    $bar = flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']], ['theme' => 'horizontal']);
    flcSetting($siteId, $bar);
    $page = $count('/gioi-thieu');
    $list = $count('/san-pham');
    flcSetting($siteId, ['buy' => ['enabled' => true, 'color' => '#0ab39c']] + $bar);
    $pageBuy = $count('/gioi-thieu');
    $listBuy = $count('/san-pham');
    $html = $this->get('http://mau.platform.test/gioi-thieu')->getContent();
    DB::disableQueryLog();

    expect($html)->toContain('href="/san-pham" style="--fc-fill:')
        ->and($pageBuy - $page)->toBe(5)
        ->and($listBuy - $list)->toBe(3);
});
