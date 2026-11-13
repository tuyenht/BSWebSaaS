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
use App\Modules\Theme\Chrome\ContactIcons;
use App\Modules\Theme\Chrome\FloatingContact;
use App\Modules\Theme\Theming\ReadableColor;
use Illuminate\Support\Facades\DB;

/*
 * The floating contact (`storefront.floating_contact`): printed once per page after the footer, as
 * plain links (tel:, sms:, mailto:, https), only while the setting is on and at least one channel
 * passes its check. Layouts: combo = round buttons on a computer + the bottom bar on a phone;
 * vertical = round buttons everywhere (folded into one "Liên hệ" button on a phone with many
 * channels); horizontal = the bottom bar on phones only. Values are rebuilt from what passed the
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
    // The raised call is off here so the tests of the bar's order keep the saved order; its own tests switch it on.
    return $extra + ['bar_raise_call' => false, 'enabled' => true, 'theme' => 'vertical', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24, 'channels' => $channels];
}

/** The floating-contact markup of a page (the nav and its spacer). */
function flcPart(string $html): string
{
    preg_match_all('~<div class="sf-fc-ghost.*?</div></div>|<div class="sf-fc-bar sf-fc-bar--ghost".*?</ul></div>|<div class="sf-fc sf-fc--.*?</nav>\s*</div>~s', $html, $m);

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

it('never prints the admin preview’s unfinished slot for a channel switched on but not filled in', function (string $layout): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => ''],
        ['key' => 'messenger', 'on' => true, 'value' => ''],
        ['key' => 'email', 'on' => true, 'value' => 'not-an-email'],
        ['key' => 'sms', 'on' => true, 'value' => ''],
    ], ['layout' => $layout]));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->toContain('data-fc-root')->toContain('href="tel:0912345678"')->not->toContain('data-fc-pending')
        ->and(flcPart($html))->not->toContain('href="#"');
})->with(['combo', 'vertical', 'horizontal']);

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

/** The five channels a new site lists, all filled in. */
function flcFive(array $extra = []): array
{
    return flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'messenger', 'on' => true, 'value' => 'trang.mau'],
        ['key' => 'email', 'on' => true, 'value' => 'lienhe@mau.test'],
        ['key' => 'sms', 'on' => true, 'value' => '0987654321'],
    ], $extra);
}

/** @return list<string> the labels of the bar's cells, in order */
function flcBarLabels(string $part): array
{
    preg_match('~<nav class="sf-fc-bar sf-fc-bar--(?:light|dark)".*?<ul class="sf-fc-bar__list".*?</ul>~s', $part, $bar);
    preg_match_all('~<span class="sf-fc-bar__label">([^<]*)</span>~', $bar[0] ?? '', $m);

    return $m[1];
}

it('draws each layout for the widths it belongs to, reading an older setting as the layout it meant', function (array $extra, string $layout, bool $round, bool $bar): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']], $extra));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('<div class="sf-fc sf-fc--'.$layout.'" data-fc-root')
        // The copy code's address only where its "Chép số" button is printed (the round call button).
        ->and(str_contains($part, 'data-fc-js="'))->toBe($round)
        ->and(str_contains($part, '<nav class="sf-fc__float'))->toBe($round)
        ->and(str_contains($part, '<nav class="sf-fc-bar'))->toBe($bar)
        ->and(str_contains($part, 'sf-fc-ghost--wide'))->toBe($layout === 'combo');
})->with([
    'combo' => [['layout' => 'combo'], 'combo', true, true],
    'vertical' => [['layout' => 'vertical'], 'vertical', true, false],
    'horizontal' => [['layout' => 'horizontal'], 'horizontal', false, true],
    'older horizontal theme' => [['theme' => 'horizontal'], 'combo', true, true],
    'older round buttons with the phone bar' => [['phone_bar' => ['enabled' => true]], 'combo', true, true],
    'older plain round buttons' => [[], 'vertical', true, false],
]);

it('folds a phone’s round buttons into one “Liên hệ” button only for a vertical site with four ready channels and the switch on', function (array $extra, bool $dial): void {
    $siteId = flcSite();
    flcSetting($siteId, flcFive($extra));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect(str_contains($part, 'popovertarget="sf-fc-dial"'))->toBe($dial)
        ->and(str_contains($part, 'id="sf-fc-dial" popover'))->toBe($dial);
    if ($dial) {
        expect($part)->toContain('Liên hệ · 5')->toContain('>Đóng</span>')->toContain('sf-fc-ghost--dial');
    }
})->with([
    'vertical, five channels' => [['layout' => 'vertical'], true],
    'vertical, switched off' => [['layout' => 'vertical', 'dial' => false], false],
    'combo' => [['layout' => 'combo'], false],
]);

it('puts Email, Zalo and the call on the bar with no “Thêm” for a site like An Vinh', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'email', 'on' => true, 'value' => 'lienhe@mau.test'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
    ], ['layout' => 'combo']));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect(flcBarLabels($part))->toBe(['Gửi Email', 'Zalo', 'Gọi điện'])
        ->and($part)->not->toContain('popovertarget')->not->toContain('sf-fc-sheet');
});

it('fills the bar with the call, Zalo and SMS and opens Messenger and Email from “Thêm”, “Mua hàng” last', function (bool $shop): void {
    $siteId = flcSite();
    if ($shop) {
        flcShop($siteId);
    }
    flcSetting($siteId, flcFive(['theme' => 'horizontal', 'layout' => 'combo', 'buy' => ['enabled' => true, 'color' => '#0ab39c']]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());
    preg_match('~<div class="sf-fc-sheet" id="sf-fc-more" popover>.*?</div>~s', $part, $sheet);

    expect(flcBarLabels($part))->toBe($shop ? ['Gọi điện', 'Zalo', 'Nhắn tin', 'Thêm', 'Mua hàng'] : ['Gọi điện', 'Zalo', 'Nhắn tin', 'Thêm'])
        ->and($sheet[0])->toContain('Kênh liên hệ khác')->toContain('href="https://m.me/trang.mau"')->toContain('href="mailto:lienhe@mau.test"')
        ->and(substr_count($sheet[0], '<li class="sf-fc-sheet__item">'))->toBe(2)
        // The bar cells a narrow phone lets go (fourth place on, "Thêm" and "Mua hàng" counted) wait in the sheet too.
        ->and(substr_count($sheet[0], '<li class="sf-fc-sheet__item sf-fc-sheet__item--r'))->toBe($shop ? 2 : 1)
        ->and($part)->toContain('<li class="sf-fc-bar__item sf-fc-bar__item--more"><button type="button" class="sf-fc-bar__link" popovertarget="sf-fc-more">');
})->with(['with a product list' => true, 'without one' => false]);

/** @return list<string> each bar cell as "label" or "label:place class" (r4/r5/m4/m5), in order */
function flcBarCells(string $part): array
{
    preg_match('~<nav class="sf-fc-bar sf-fc-bar--(?:light|dark)".*?<ul class="sf-fc-bar__list".*?</ul>~s', $part, $bar);
    preg_match_all('~<li class="sf-fc-bar__item([^"]*)">.*?<span class="sf-fc-bar__label">([^<]*)</span>~s', $bar[0] ?? '', $m, PREG_SET_ORDER);

    return array_map(static function (array $cell): string {
        preg_match('~--([rm][45])~', $cell[1], $place);

        return $cell[2].(isset($place[1]) ? ':'.$place[1] : '');
    }, $m);
}

/** @return list<string> the sheet's rows as "label" or "label:place class", in order; [] without a sheet */
function flcSheetRows(string $part): array
{
    preg_match('~<div class="sf-fc-sheet" id="sf-fc-more" popover>.*?</ul>~s', $part, $sheet);
    preg_match_all('~<li class="sf-fc-sheet__item(?: sf-fc-sheet__item--(r[45]))?">.*?<span class="sf-fc-sheet__l">([^<]*)</span>~s', $sheet[0] ?? '', $m, PREG_SET_ORDER);

    return array_map(static fn (array $row): string => $row[2].($row[1] !== '' ? ':'.$row[1] : ''), $m);
}

it('keeps every channel one tap away on a narrow phone: the cells it lets go wait in the sheet behind a “Thêm” shown just there', function (array $keys, bool $shop, array $cells, array $rows): void {
    $siteId = flcSite();
    if ($shop) {
        flcShop($siteId);
    }
    $values = ['phone' => '0912345678', 'zalo' => '0912345678', 'sms' => '0912345678', 'whatsapp' => '0912345678', 'telegram' => '@mau_shop', 'email' => 'lienhe@mau.test'];
    flcSetting($siteId, flcConfig(
        array_map(static fn (string $key): array => ['key' => $key, 'on' => true, 'value' => $values[$key]], $keys),
        ['theme' => 'horizontal', 'layout' => 'horizontal'] + ($shop ? ['buy' => ['enabled' => true, 'color' => '#0ab39c']] : []),
    ));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    // r5 goes below 22.5rem (four cells), r4 below 18rem (three); "Thêm" (m5/m4) shows exactly there.
    expect(flcBarCells($part))->toBe($cells)
        ->and(flcSheetRows($part))->toBe($rows)
        ->and($part)->toContain('popovertarget="sf-fc-more"')
        ->and(substr_count($part, 'id="sf-fc-more"'))->toBe(1);
    // The ghost keeps the same cells, so the room it reserves matches the bar at every width.
    preg_match('~<div class="sf-fc-bar sf-fc-bar--ghost".*?</ul></div>~s', $part, $ghost);
    expect(substr_count($ghost[0], 'sf-fc-bar__item--'))->toBe(count(array_filter($cells, static fn (string $c): bool => str_contains($c, ':'))) + 1);
})->with([
    'five quick channels, no “Mua hàng”' => [['phone', 'zalo', 'sms', 'whatsapp', 'telegram'], false,
        ['Gọi điện', 'Zalo', 'Nhắn tin:r4', 'WhatsApp:r5', 'Telegram:r5', 'Thêm:m5'], ['Nhắn tin:r4', 'WhatsApp:r5', 'Telegram:r5']],
    'four quick channels and “Mua hàng”' => [['phone', 'zalo', 'sms', 'whatsapp'], true,
        ['Gọi điện', 'Zalo:r4', 'Nhắn tin:r5', 'WhatsApp:r5', 'Thêm:m5', 'Mua hàng'], ['Zalo:r4', 'Nhắn tin:r5', 'WhatsApp:r5']],
    'three quick channels and one more joining the bar' => [['phone', 'zalo', 'sms', 'email'], false,
        ['Gọi điện', 'Zalo', 'Nhắn tin:r4', 'Gửi Email:r4', 'Thêm:m4'], ['Nhắn tin:r4', 'Gửi Email:r4']],
    'four quick channels and one more joining the bar' => [['phone', 'zalo', 'sms', 'whatsapp', 'email'], false,
        ['Gọi điện', 'Zalo', 'Nhắn tin:r4', 'WhatsApp:r5', 'Gửi Email:r5', 'Thêm:m5'], ['Nhắn tin:r4', 'WhatsApp:r5', 'Gửi Email:r5']],
    'three quick channels, one more joining, and “Mua hàng”' => [['phone', 'zalo', 'sms', 'email'], true,
        ['Gọi điện', 'Zalo:r4', 'Nhắn tin:r5', 'Gửi Email:r5', 'Thêm:m5', 'Mua hàng'], ['Zalo:r4', 'Nhắn tin:r5', 'Gửi Email:r5']],
    'a full bar with its own “Thêm” and “Mua hàng”' => [['phone', 'zalo', 'sms', 'whatsapp', 'telegram', 'email'], true,
        ['Gọi điện', 'Zalo:r4', 'Nhắn tin:r5', 'Thêm', 'Mua hàng'], ['Zalo:r4', 'Nhắn tin:r5', 'WhatsApp', 'Telegram', 'Gửi Email']],
]);

it('prints no “Thêm” and no sheet for a bar of three cells, which every phone fits', function (): void {
    $siteId = flcSite();
    flcShop($siteId);
    flcSetting($siteId, flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
    ], ['theme' => 'horizontal', 'layout' => 'combo', 'buy' => ['enabled' => true, 'color' => '#0ab39c']]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect(flcBarCells($part))->toBe(['Gọi điện', 'Zalo', 'Mua hàng'])
        ->and($part)->not->toContain('sf-fc-sheet')->not->toContain('popovertarget')->not->toContain('sf-fc-bar__item--');
});

it('lists in the sheet the bar channels a narrow phone lets go, and gives them the same place class', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
        ['key' => 'whatsapp', 'on' => true, 'value' => '0912345678'],
        ['key' => 'telegram', 'on' => true, 'value' => '@mau_shop'],
        ['key' => 'email', 'on' => true, 'value' => 'lienhe@mau.test'],
    ], ['layout' => 'horizontal']));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    // Five places: four channels and "Thêm"; WhatsApp is fourth after "Thêm" is counted → r5.
    expect(flcBarLabels($part))->toBe(['Gọi điện', 'Zalo', 'Nhắn tin', 'WhatsApp', 'Thêm'])
        ->and($part)->toContain('sf-fc-bar__item sf-fc-bar__item--r4"><a class="sf-fc-bar__link" href="sms:')
        ->toContain('sf-fc-bar__item sf-fc-bar__item--r5"><a class="sf-fc-bar__link" href="https://wa.me/')
        ->toContain('sf-fc-sheet__item sf-fc-sheet__item--r5"><a class="sf-fc-sheet__row" href="https://wa.me/');
});

it('turns an older phone bar’s own cell into a channel at the end with its label, and never prints an unsafe one', function (string $url, ?string $href): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']], [
        'phone_bar' => ['enabled' => true, 'cta' => ['label' => ['vi' => 'Yêu cầu báo giá', 'en' => 'Get a quote'], 'url' => $url]],
    ]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    if ($href === null) {
        expect($part)->not->toContain('Yêu cầu báo giá')->and(strtolower($part))->not->toContain('javascript');

        return;
    }
    expect(flcBarLabels($part))->toBe(['Gọi điện', 'Yêu cầu báo giá'])
        ->and($part)->toContain('<a class="sf-fc__btn" href="'.$href.'" aria-label="Yêu cầu báo giá"');
})->with([
    'a page of the site' => ['/lien-he', '/lien-he'],
    'a web link' => ['https://mau.test/bao-gia', 'https://mau.test/bao-gia'],
    'a script link' => ['javascript:alert(1)', null],
]);

it('adds the older phone bar’s own cell only where older code drew it, never from a phone bar left under the bar theme', function (): void {
    $siteId = flcSite();
    $cta = ['enabled' => true, 'cta' => ['label' => ['vi' => 'Yêu cầu báo giá'], 'url' => '/lien-he']];
    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']], ['theme' => 'horizontal', 'phone_bar' => $cta]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect(flcBarLabels($part))->toBe(['Gọi điện'])
        ->and($part)->not->toContain('Yêu cầu báo giá')->not->toContain('href="/lien-he"');
});

it('fills each disc with its colour darkened under the white mark, Messenger with its band unless the site chose a colour', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'messenger', 'on' => true, 'value' => 'trang.mau'],
        ['key' => 'c1', 'on' => true, 'value' => '/lien-he', 'name' => 'Báo giá', 'color' => '#ffd400', 'icon' => 'ri-global-line'],
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
    ], ['layout' => 'vertical']));
    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('--fc-fill:'.FloatingContact::MESSENGER_FILL.';--fc-tint:#0078ff')
        ->toContain('--fc-fill:'.ReadableColor::inkUnder('#ffd400').';--fc-tint:#ffd400')
        ->toContain('--fc-fill:'.ReadableColor::inkUnder('#4caf50').';--fc-tint:#4caf50')
        ->and(ReadableColor::ratio(ReadableColor::inkUnder('#ffd400'), '#ffffff'))->toBeGreaterThanOrEqual(3.0);

    flcSetting($siteId, flcConfig([['key' => 'messenger', 'on' => true, 'value' => 'trang.mau', 'color' => '#7c3aed']]));
    $own = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($own)->toContain('--fc-fill:'.ReadableColor::inkUnder('#7c3aed').';')->not->toContain('linear-gradient');
});

it('names each click’s channel, place and kind of link, never the number or the address, and nothing with tracking off', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcFive(['layout' => 'combo']));
    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());
    preg_match_all('~data-fc[a-z-]*="([^"]*)"~', $part, $data);

    expect($part)->toContain('href="tel:0912345678" aria-label="Gọi điện" data-fc="phone" data-fc-at="floating" data-fc-lt="tel"')
        ->toContain('href="tel:0912345678" data-fc="phone" data-fc-at="bar" data-fc-lt="tel"')
        ->toContain('href="mailto:lienhe@mau.test" data-fc="email" data-fc-at="sheet" data-fc-lt="mailto"')
        ->and(implode(' ', $data[1]))->not->toContain('0912')->not->toContain('0987')->not->toContain('@')->not->toContain('trang.mau');

    flcSetting($siteId, flcFive(['layout' => 'combo', 'track' => false]));
    $off = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($off)->not->toContain('data-fc=')->toContain('data-fc-root');
});

it('points every popover button at one element of that id', function (array $extra): void {
    $siteId = flcSite();
    flcSetting($siteId, flcFive($extra));
    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    preg_match_all('~popovertarget="([^"]+)"~', $html, $targets);
    expect($targets[1])->not->toBeEmpty();
    foreach ($targets[1] as $id) {
        expect(substr_count($html, 'id="'.$id.'"'))->toBe(1)
            ->and($html)->toMatch('~id="'.$id.'"[^>]* popover~');
    }
})->with([
    'the sheet' => [['layout' => 'combo']],
    'the dial' => [['layout' => 'vertical']],
]);

it('shows the call button’s number with a “Chép số” button on a computer, and hides SMS there', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcFive(['layout' => 'vertical', 'dial' => false]));
    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('<li class="sf-fc__item sf-fc__item--call sf-fc__item--pulse"')
        ->toContain('<span class="sf-fc__label-t">Gọi điện · <span class="sf-fc__num">0912 345 678</span></span><button type="button" class="sf-fc__copy" data-fc-copy data-done="Đã chép">')
        ->toContain('<span class="sf-fc__copy-t">Chép số</span>')
        ->toContain('<li class="sf-fc__item sf-fc__item--sms"')
        ->and(substr_count($part, 'sf-fc__copy"'))->toBe(1);
});

it('gives the page the copy code’s address only when a round call button has its “Chép số” button', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([['key' => 'zalo', 'on' => true, 'value' => '0912345678']], ['layout' => 'combo']));
    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('data-fc-root>')->not->toContain('data-fc-js')->not->toContain('data-fc-copy');
});

it('puts the buttons on the chosen side with the offsets and keeps their room under the footer', function (string $position): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']], ['position' => $position, 'offset_x' => 40, 'offset_y' => 60]));
    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('<nav class="sf-fc__float sf-fc__float--'.$position.'" aria-label="Liên hệ nhanh" style="--fc-ox:40px;--fc-oy:60px">')
        ->toContain('<div class="sf-fc-ghost sf-fc-ghost--side'.($position === 'bottom' ? ' sf-fc-ghost--row' : '').'" aria-hidden="true"');
})->with(['right', 'left', 'bottom']);

it('draws the bar dark when the site chose it, and light otherwise', function (string $tone, string $class): void {
    $siteId = flcSite();
    flcSetting($siteId, flcFive(['layout' => 'horizontal', 'bar_tone' => $tone]));
    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('<nav class="sf-fc-bar sf-fc-bar--'.$class.'"');
})->with([['auto', 'light'], ['light', 'light'], ['dark', 'dark']]);

it('prints the label of the page’s language and escapes every label', function (): void {
    $siteId = flcSite();
    flcSetting($siteId, flcConfig([
        ['key' => 'phone', 'on' => true, 'value' => '0912345678', 'tips' => ['vi' => 'Gọi <b>ngay</b>']],
        ['key' => 'c1', 'on' => true, 'value' => '/lien-he', 'name' => 'Đặt "lịch"'],
    ], ['layout' => 'combo']));
    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->toContain('Gọi &lt;b&gt;ngay&lt;/b&gt;')->toContain('Đặt &quot;lịch&quot;')->not->toContain('<b>ngay');
});

it('shows a site only its own buttons', function (): void {
    $a = flcSite();
    $b = flcSite('Xưởng Khác', 'khac.platform.test');
    flcSetting($a, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0911111111']]));
    flcSetting($b, flcConfig([['key' => 'phone', 'on' => true, 'value' => '0922222222']]));

    expect($this->get('http://khac.platform.test/gioi-thieu')->getContent())->toContain('tel:0922222222')->not->toContain('0911111111');
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
    // A round-button site with the phone bar never showed "Mua hàng": a stale switch neither adds it
    // nor looks the product list up.
    flcSetting($siteId, ['buy' => ['enabled' => true, 'color' => '#0ab39c'], 'phone_bar' => ['enabled' => true]]
        + flcConfig([['key' => 'phone', 'on' => true, 'value' => '0912345678']]));
    $pageStale = $count('/gioi-thieu');
    $stale = flcPart($this->get('http://mau.platform.test/gioi-thieu')->getContent());
    DB::disableQueryLog();

    expect($html)->toContain('<a class="sf-fc-bar__link" href="/san-pham"')
        ->and($pageBuy - $page)->toBe(5)
        ->and($listBuy - $list)->toBe(3)
        ->and($pageStale)->toBe($page)
        ->and(flcBarLabels($stale))->toBe(['Gọi điện'])
        ->and($stale)->not->toContain('href="/san-pham"');
});

/**
 * A setting as an older site saved it: no `bar_raise_call` key.
 *
 * @param  list<string>  $keys
 * @return array<string, mixed>
 */
function flcRaw(array $keys, array $extra = []): array
{
    $values = ['phone' => '0912345678', 'zalo' => '0912345678', 'sms' => '0912345678', 'whatsapp' => '0912345678', 'telegram' => '@mau_shop', 'email' => 'lienhe@mau.test', 'maps' => 'https://maps.app.goo.gl/abc'];

    return $extra + ['enabled' => true, 'theme' => 'horizontal', 'layout' => 'combo', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24,
        'channels' => array_map(static fn (string $key): array => ['key' => $key, 'on' => true, 'value' => $values[$key]], $keys)];
}

it('raises the call into the middle of the bar for a setting saved before the option, with the cell one link', function (array $keys, array $labels): void {
    $siteId = flcSite();
    flcSetting($siteId, flcRaw($keys));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect(flcBarLabels($part))->toBe($labels)
        // The bar and its spacer both carry the raise, so the spacer keeps the room of the rise too.
        ->and(substr_count($part, ' data-up>'))->toBe(2)
        ->and(substr_count($part, 'sf-fc-bar__item--up'))->toBe(2)
        // The whole cell is the call link: the disc and its label sit inside the one tel: link.
        ->and($part)->toMatch('~<li class="sf-fc-bar__item sf-fc-bar__item--up"><a class="sf-fc-bar__link" href="tel:[^"]+"[^>]*><span class="sf-fc-bar__dot"[^>]*>.*?</span><span class="sf-fc-bar__label">Gọi điện</span></a></li>~s');
})->with([
    'three cells' => [['phone', 'zalo', 'sms'], ['Zalo', 'Gọi điện', 'Nhắn tin']],
    'four cells' => [['phone', 'zalo', 'sms', 'whatsapp'], ['Zalo', 'Nhắn tin', 'Gọi điện', 'WhatsApp', 'Thêm']],
    'five cells' => [['phone', 'zalo', 'sms', 'whatsapp', 'telegram'], ['Zalo', 'Nhắn tin', 'Gọi điện', 'WhatsApp', 'Telegram', 'Thêm']],
    'the call saved last' => [['zalo', 'sms', 'whatsapp', 'phone'], ['Zalo', 'Nhắn tin', 'Gọi điện', 'WhatsApp', 'Thêm']],
]);

it('keeps a place for the call even when it is saved after the channels that fill the bar', function (): void {
    $siteId = flcSite();
    flcShop($siteId);
    flcSetting($siteId, flcRaw(['zalo', 'sms', 'whatsapp', 'telegram', 'phone'], ['buy' => ['enabled' => true, 'color' => '#0ab39c']]));

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    // Four places with “Mua hàng”: the call, then the first two quick channels, and “Thêm”; the rest wait in the sheet.
    expect(flcBarLabels($part))->toBe(['Zalo', 'Nhắn tin', 'Gọi điện', 'Thêm', 'Mua hàng'])
        ->and(flcSheetRows($part))->toContain('WhatsApp')->toContain('Telegram');
});

it('keeps the saved order and draws no raised cell when the option is off, the call is off or its number does not pass, and on round buttons only', function (array $extra, array $keys, ?array $phone, array $labels): void {
    $siteId = flcSite();
    $raw = flcRaw($keys, $extra);
    if ($phone !== null) {
        $raw['channels'][0] = ['key' => 'phone'] + $phone;
    }
    flcSetting($siteId, $raw);

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($part)->not->toContain('sf-fc-bar__item--up')->not->toContain('data-up')
        ->and(flcBarLabels($part))->toBe($labels);
})->with([
    'the option off' => [['bar_raise_call' => false], ['phone', 'zalo', 'sms'], null, ['Gọi điện', 'Zalo', 'Nhắn tin']],
    'the call switched off' => [[], ['phone', 'zalo', 'sms'], ['on' => false, 'value' => '0912345678'], ['Zalo', 'Nhắn tin']],
    'a number that does not pass' => [[], ['phone', 'zalo', 'sms'], ['on' => true, 'value' => 'abc'], ['Zalo', 'Nhắn tin']],
    'no call channel' => [[], ['zalo', 'sms', 'whatsapp'], null, ['Zalo', 'Nhắn tin', 'WhatsApp']],
    'round buttons only' => [['layout' => 'vertical', 'theme' => 'vertical'], ['phone', 'zalo', 'sms'], null, []],
]);

it('lists directions switched off for a site that has not turned it on, and prints a map pin link when it is on', function (): void {
    $siteId = flcSite();
    $raw = flcRaw(['phone', 'zalo'], ['bar_raise_call' => false]);
    $raw['channels'][] = ['key' => 'maps', 'on' => false, 'value' => 'https://maps.app.goo.gl/abc'];
    flcSetting($siteId, $raw);

    $off = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());
    expect($off)->not->toContain('maps.app.goo.gl')->not->toContain('Chỉ đường');

    $raw['channels'][2]['on'] = true;
    flcSetting($siteId, $raw);
    $on = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($on)->toContain('href="https://maps.app.goo.gl/abc" rel="noopener"')->toContain('Chỉ đường')
        ->and($on)->toContain(substr((string) ContactIcons::solid('ri-map-pin-fill'), 0, 30))
        ->and($on)->toContain('--fc-fill:'.ReadableColor::inkUnder('#ea4335'));
});

it('never prints a maps link that is not http or https', function (): void {
    $siteId = flcSite();
    $raw = flcRaw(['phone', 'zalo'], ['bar_raise_call' => false]);
    $raw['channels'][] = ['key' => 'maps', 'on' => true, 'value' => 'javascript:alert(1)'];
    flcSetting($siteId, $raw);

    $part = flcPart($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent());

    expect(strtolower($part))->not->toContain('javascript')->and($part)->not->toContain('Chỉ đường');
});
