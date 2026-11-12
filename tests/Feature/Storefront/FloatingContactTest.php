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
use App\Modules\Theme\Theming\ReadableColor;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCss;

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
        ->toContain('<div class="sf-fc-ghost sf-fc-ghost--side" aria-hidden="true" style="--fc-ox:40px;--fc-oy:100px;--fc-n:3"><div class="sf-fc__list sf-fc__list--left">')
        ->and(substr_count($part, 'sf-fc__btn"></span>'))->toBe(3)
        ->and($part)->not->toContain('sf-fc-bar--ghost');
});

it('keeps a side stack’s room under the footer wherever it can cover the footer’s text, and the bottom row’s at every width', function (): void {
    // Below 768px the room always stays. From 768px it stays while the stack reaches into the 80rem
    // page (its offset + a 50px button > the margin beside the page's 2rem padding: up to about
    // 1357px at a 16px root and the default offset, 1440px at An Vinh's 17px root, measured) or wraps into a
    // second column; elsewhere the stack sits in the margin and no empty band is left. With the
    // safe-area inset, so the stack never covers the last footer line.
    $css = (string) StorefrontCss::all();
    preg_match('/@media \(min-width: 768px\) \{\n    \.sf-fc-ghost--side \{(.*?)\n    \}\n\}/s', $css, $side);

    expect($css)->toContain('padding: 0.625rem var(--fc-ox, 20px) calc(var(--fc-oy, 24px) + env(safe-area-inset-bottom, 0px));')
        ->and($side[1] ?? '')->toContain('--fc-over: calc(var(--fc-n, 0) * (max(50px, 3.125rem) + 0.75rem) - 0.75rem - (100dvh - var(--fc-oy, 24px) - 5rem) + 1px);')
        ->toContain('--fc-cover: calc(var(--fc-ox, 20px) + max(50px, 3.125rem) + 38rem - 50vw + 1px);')
        ->toContain('max-height: clamp(0px, max(var(--fc-over), var(--fc-cover)) * 9999, 300vh);')
        ->toContain('padding-bottom: clamp(0px, max(var(--fc-over), var(--fc-cover)) * 9999, calc(var(--fc-oy, 24px) + env(safe-area-inset-bottom, 0px)));')
        ->not->toContain('display: none')
        // the page the cover term measures against: 80rem with 2rem side padding from lg
        ->and($css)->toContain(".sf-container {\n    max-width: 80rem;")
        ->and(preg_match('~\.sf-fc-ghost--row\s*\{[^}]*display:\s*none~', $css))->toBe(0)
        ->and(preg_match('~\.sf-fc-ghost--side\s*\{[^}]*display:\s*none~', $css))->toBe(0);
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

it('draws every channel in the phone bottom bar, chat channels included, with a rank class from the fourth place', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'messenger', 'on' => true, 'value' => 'trang.mau'],
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'email', 'on' => true, 'value' => 'lienhe@mau.test'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
        ['key' => 'whatsapp', 'on' => true, 'value' => '0912345678'],
        ['key' => 'telegram', 'on' => true, 'value' => 'trangmau'],
        ['key' => 'viber', 'on' => true, 'value' => '0912345678'],
    ], ['theme' => 'horizontal']));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc-bar".*?</nav>~s', $html, $bar);
    preg_match('~<div class="sf-fc-bar sf-fc-bar--ghost".*?</ul></div>~s', $html, $ghost);

    expect($bar[0] ?? '')->toContain('href="https://m.me/trang.mau"')->toContain('href="mailto:lienhe@mau.test"')
        ->toContain('<li class="sf-fc-bar__item sf-fc-bar__item--r4"><a class="sf-fc-bar__link" href="https://zalo.me/')
        ->toContain('<li class="sf-fc-bar__item sf-fc-bar__item--r8"><a class="sf-fc-bar__link" href="viber:')
        ->not->toContain('sf-fc-bar__item--r9')->not->toContain('sf-fc-bar__item--rx')
        ->and(substr_count($bar[0] ?? '', '<a '))->toBe(8)
        ->and(substr_count($bar[0] ?? '', '<li class="sf-fc-bar__item">'))->toBe(3)
        ->and(substr_count($ghost[0] ?? '', 'sf-fc-bar__item--r'))->toBe(5);
});

it('gives the twelfth place of the bar and every later one a class that never shows', function (): void {
    $siteId = flcSite();
    $keys = ['phone', 'zalo', 'messenger', 'email', 'sms', 'whatsapp', 'viber', 'telegram', 'tiktok', 'youtube', 'instagram', 'maps'];
    flcSetting($siteId, flcConfig(array_map(
        static fn (string $key): array => ['key' => $key, 'on' => true, 'value' => match (true) {
            $key === 'email' => 'lienhe@mau.test',
            $key === 'messenger' => 'trang.mau',
            $key === 'telegram' => 'trangmau',
            in_array($key, ['tiktok', 'youtube', 'instagram', 'maps'], true) => 'https://example.com/'.$key,
            default => '0912345678',
        }],
        $keys,
    ), ['theme' => 'horizontal']));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc-bar".*?</nav>~s', $html, $bar);

    expect(substr_count($bar[0] ?? '', '<a '))->toBe(12)
        ->and($bar[0] ?? '')->toContain('sf-fc-bar__item--r11"')->toContain('sf-fc-bar__item sf-fc-bar__item--rx"><a')
        ->and(substr_count($bar[0] ?? '', 'sf-fc-bar__item--rx'))->toBe(1);
});

it('prints the same channels as round buttons from 768px in the bar theme, each ghost for its own width only', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'email', 'on' => true, 'value' => 'lienhe@mau.test'],
    ], ['theme' => 'horizontal', 'position' => 'left', 'offset_x' => 40, 'offset_y' => 100]));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc sf-fc--left sf-fc--wide".*?</nav>~s', $html, $nav);
    preg_match('~<div class="sf-fc-ghost sf-fc-ghost--side sf-fc-ghost--wide".*?</div></div>~s', $html, $wideGhost);

    expect($nav[0] ?? '')->toContain('style="--fc-ox:40px;--fc-oy:100px"')->toContain('href="tel:0912345678"')->toContain('href="mailto:lienhe@mau.test"')
        ->and($wideGhost[0] ?? '')->toContain('--fc-n:2')
        // the bar and its ghost are the phone's; no second ghost without the wide class
        ->and($html)->toContain('<nav class="sf-fc-bar"')->toContain('sf-fc-bar sf-fc-bar--ghost')
        ->and(substr_count($html, 'sf-fc-ghost sf-fc-ghost--'))->toBe(1);

    $css = (string) file_get_contents(resource_path('css/sf-floating.css'));
    expect(preg_match('~\.sf-fc--wide,\s*\.sf-fc-ghost--wide\s*\{[^}]*display:\s*none~', $css))->toBe(1)
        ->and(preg_match('~@media \(min-width: 768px\)\s*\{\s*\.sf-fc--wide\s*\{[^}]*display:\s*block~', $css))->toBe(1)
        ->and($css)->not->toContain('overflow-wrap: anywhere');
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

it('keeps “Mua hàng” within the first three places of the bar however many channels there are', function (): void {
    $siteId = flcSite();
    flcShop($siteId);
    flcSetting($siteId, array_replace(flcBarWithBuy(), ['channels' => array_map(
        static fn (string $key): array => ['key' => $key, 'on' => true, 'value' => '0912345678'],
        ['phone', 'zalo', 'sms', 'whatsapp', 'viber'],
    )]));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc-bar".*?</nav>~s', $html, $bar);

    // Two channels and the buy cell are the always-shown places; the rest start at rank 4, in order.
    expect($bar[0] ?? '')->toContain('sf-fc-bar__item--r4"><a class="sf-fc-bar__link" href="sms:')
        ->toContain('sf-fc-bar__item--r5"><a class="sf-fc-bar__link" href="https://wa.me/')
        ->toContain('sf-fc-bar__item--r6"><a class="sf-fc-bar__link" href="viber:')
        ->toContain('<li class="sf-fc-bar__item"><a class="sf-fc-bar__link" href="/san-pham"')
        ->and(substr_count($bar[0] ?? '', '<li class="sf-fc-bar__item">'))->toBe(3);
});

it('ends the phone bar with “Mua hàng” opening the product list, and keeps its room in the spacer', function (): void {
    $siteId = flcSite();
    flcShop($siteId);
    flcSetting($siteId, flcBarWithBuy());

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc-bar".*?</nav>~s', $html, $bar);
    preg_match('~<div class="sf-fc-bar sf-fc-bar--ghost".*?</ul></div>~s', $html, $ghost);

    expect($bar[0] ?? '')->toContain('href="tel:0912345678"')->toContain('href="https://zalo.me/0912345678"')
        ->toContain('<li class="sf-fc-bar__item sf-fc-bar__item--r4"><a class="sf-fc-bar__link" href="sms:0912345678"')
        ->toContain('href="/san-pham" style="--fc-fill:')
        ->toContain(';--fc-ink:#ffffff"')
        ->toContain('<span class="sf-fc-bar__label">Mua hàng</span>')
        ->and(substr_count($bar[0] ?? '', '<a '))->toBe(4)
        ->and(substr_count($ghost[0] ?? '', '<li class="sf-fc-bar__item">'))->toBe(3)
        ->and(substr_count($ghost[0] ?? '', '<li class="sf-fc-bar__item sf-fc-bar__item--r4">'))->toBe(1)
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

it('swaps the round buttons for the dark phone bar below 768px when the site switches it on, and reserves no band under the footer', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678', 'tips' => ['vi' => 'Chat Zalo']],
        ['key' => 'phone', 'on' => true, 'value' => '0912345678', 'color' => '#f59e0b', 'tips' => ['vi' => 'Gọi']],
    ], ['phone_bar' => ['enabled' => true, 'cta' => ['label' => ['vi' => 'Yêu cầu báo giá'], 'url' => '/gioi-thieu']]]));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc sf-fc--right sf-fc--md".*?</nav>~s', $html, $round);
    preg_match('~<nav class="sf-fc-bar sf-fc-bar--board".*?</nav>~s', $html, $bar);
    preg_match('~<div class="sf-fc-bar sf-fc-bar--board sf-fc-bar--ghost".*?</ul></div>~s', $html, $ghost);

    expect($round[0] ?? '')->toContain('href="tel:0912345678"')->not->toContain('--fc-fill:#f59e0b')->toContain('--fc-ink:#ffffff')
        ->and($bar[0] ?? '')->toContain('--fc-fill:#f59e0b;--fc-ink:#0f172a')
        ->and($bar[0] ?? '')->toContain('href="https://zalo.me/0912345678"')->toContain('>Chat Zalo<')
        ->toContain('href="tel:0912345678"')->toContain('>Gọi<')
        ->toContain('sf-fc-bar__item--cta')->toContain('href="/gioi-thieu"')->toContain('>Yêu cầu báo giá<')
        // every cell draws its mark above its label, the quote cell too
        ->and(substr_count($bar[0] ?? '', '<span class="sf-fc-bar__dot'))->toBe(3)
        ->and($bar[0] ?? '')->toMatch('~sf-fc-bar__item--cta.*?<span class="sf-fc-bar__dot" aria-hidden="true"><svg~s')
        ->and($ghost[0] ?? '')->toContain('Yêu cầu báo giá')
        // the call leads, the quote cell is last
        ->and(strpos($bar[0] ?? '', '>Gọi<'))->toBeLessThan(strpos($bar[0] ?? '', 'Chat Zalo'))
        ->and(strpos($bar[0] ?? '', 'Chat Zalo'))->toBeLessThan(strpos($bar[0] ?? '', 'Yêu cầu báo giá'))
        // no invisible copy of the side stack: nothing is reserved at the wider widths
        ->and($html)->not->toContain('sf-fc-ghost');

    // Without the switch the page keeps the side stack and its reserved room, and no bar.
    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']]));
    $plain = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    expect($plain)->toContain('sf-fc-ghost')->not->toContain('sf-fc-bar')->not->toContain('sf-fc--md');
});

it('draws the phone bar with the channels alone when its own cell has no safe link', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']],
        ['phone_bar' => ['enabled' => true, 'cta' => ['label' => ['vi' => 'Báo giá'], 'url' => 'javascript:alert(1)']]]));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->toContain('sf-fc-bar--board')->not->toContain('sf-fc-bar__item--cta')->not->toContain('javascript:');
});

it('prints the readable ink on every link of the horizontal bar, a light colour of the site included', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678', 'color' => '#f59e0b']], ['theme' => 'horizontal']));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('~<nav class="sf-fc-bar".*?</nav>~s', $html, $bar);
    preg_match('~href="tel:0912345678" style="--fc-fill:(#[0-9a-f]{6});--fc-ink:(#[0-9a-f]{6})"~', $bar[0] ?? '', $m);

    expect($m)->not->toBe([])
        ->and($m[2])->toBe('#ffffff')
        ->and(ReadableColor::ratio($m[1], $m[2]))->toBeGreaterThanOrEqual(3.0);
});

it('prints no button for a channel switched on but not filled in, in either look', function (string $theme): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => ''],
        ['key' => 'sms', 'on' => true, 'value' => '   '],
        ['key' => 'whatsapp', 'on' => true, 'value' => 'abc'],
    ], ['theme' => $theme]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('href="tel:0912345678"')
        ->not->toContain('zalo.me')->not->toContain('sms:')->not->toContain('wa.me')
        ->not->toContain('pending')->not->toContain('href=""');
})->with(['vertical', 'horizontal']);
