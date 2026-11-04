<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
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
        'truncate tenant_probes, routes, entry_translations, entries,
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

/** The floating-contact markup of a page (the nav and its phone spacer). */
function flcPart(string $html): string
{
    preg_match_all('~<div class="sf-fc-(?:ghost|bar sf-fc-bar--ghost)".*?</div></div>|<nav class="sf-fc[ -].*?</nav>~s', $html, $m);

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
        // the spacer copies the buttons, hidden from assistive tech
        ->toContain('<div class="sf-fc-ghost" aria-hidden="true"')
        ->and(substr_count($part, 'sf-fc__btn"></span>'))->toBe(3);
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

it('adds no query to a page', function (): void {
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
});
