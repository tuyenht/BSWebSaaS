<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\StorefrontChrome;
use App\Modules\Theme\Theming\StorefrontSettings;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCss;

/*
 * The phone menu drawer (a named dialog, no title word, the current page marked) and the language
 * switch: every public language of the site in its own words, links from PathBuilder, direction and
 * og:locale per language, and the header's right corner (the call goes while the bottom bar is on).
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

/**
 * A vi site with public en, ja and ar, an enabled-but-unpublished ko, one page in vi, en and ar (not ja),
 * and a "Tin tức" menu item with that page as its child.
 *
 * @param  list<string>  $extraLocales  public locales to add (default: en, ja, ar)
 */
function drawerSite(array $extraLocales = ['en', 'ja', 'ar']): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Mau', siteName: 'Mau', hostname: 'mau.platform.test',
        ownerEmail: 'owner@mau.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $siteId = Site::query()->firstWhere('name', 'Mau')->id;

    app(Tenancy::class)->enter($siteId, function () use ($extraLocales): void {
        foreach ($extraLocales as $locale) {
            SiteLocale::query()->create(['locale' => $locale, 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        }
        if ($extraLocales !== []) {
            SiteLocale::query()->create(['locale' => 'ko', 'is_default' => false, 'enabled' => true, 'is_public' => false]);
        }
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'general', 'key' => 'brand'],
            ['value' => ['name' => 'Xưởng Mẫu', 'logo' => 'https://files.baoson.net/logo.png', 'phone' => '0901 234 567', 'email' => 'lienhe@mau.test']],
        );
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'storefront', 'key' => 'header'],
            ['value' => ['top_bar' => ['items' => []], 'text_size' => true, 'sticky' => true]],
        );

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $body = fn (string $h): array => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => $h, 'paragraphs' => ['x']]]]];
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => $body('Giới thiệu')]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $entry->id]);
        if (in_array('en', $extraLocales, true)) {
            $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'body' => $body('About')]);
            SiteRoute::query()->create(['locale' => 'en', 'path' => '/about', 'entry_id' => $entry->id]);
        }
        if (in_array('ar', $extraLocales, true)) {
            $entry->translations()->create(['locale' => 'ar', 'title' => 'من نحن', 'slug' => 'about-ar', 'status' => 'published', 'body' => $body('من نحن')]);
            SiteRoute::query()->create(['locale' => 'ar', 'path' => '/about-ar', 'entry_id' => $entry->id]);
        }

        $menu = Menu::query()->firstOrFail();
        $news = MenuItem::query()->where('menu_id', $menu->id)->where('link_type', 'taxonomy')->firstOrFail();
        $child = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $news->id, 'link_type' => 'entry',
            'routable_type' => 'entry', 'routable_id' => $entry->id, 'position' => 0]);
        $child->translations()->create(['locale' => 'vi', 'label' => 'Giới thiệu']);
    });

    return $siteId;
}

it('lists every public language in its own name, links from PathBuilder, and no unpublished one', function (): void {
    drawerSite();

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<nav class="sf-drawer__langs" aria-label="Ngôn ngữ">')
        ->toContain('<ul class="sf-pill">')->toContain('<span class="sf-pr__t" aria-hidden="true">Ngôn ngữ</span>')
        ->toMatch('#<a class="sf-pill__s" href="[^"]*" hreflang="vi" lang="vi" dir="ltr"\s+aria-current="true"\s*>#')
        ->toMatch('#href="http://mau\.platform\.test/en/about" hreflang="en" lang="en" dir="ltr"\s*>\s*<span>English#')   // a real translation
        ->toMatch('#href="/ja" hreflang="ja" lang="ja" dir="ltr"\s*>\s*<span>日本語\s*<small class="sf-drawer__hint">\(trang chủ\)</small>#')   // no ja page: its home page, said so
        ->toMatch('#href="http://mau\.platform\.test/ar/about-ar" hreflang="ar" lang="ar" dir="rtl"#')
        ->toMatch('#hreflang="vi" lang="vi" dir="ltr"\s+aria-current="true"\s*>\s*<span>Tiếng Việt</span>#')
        ->not->toContain('hreflang="ko"');

    // the hreflang alternates stay the real translations only (no fallback target)
    expect($html)->not->toContain('<link rel="alternate" hreflang="ja"');
});

it('shows no language switch on a site with one public language', function (): void {
    drawerSite([]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->toContain('id="mobile-menu"')
        ->not->toContain('sf-drawer__langs')
        ->not->toContain('class="sf-lang"');
});

it('writes right-to-left pages with dir and gives each language its og:locale', function (): void {
    drawerSite();

    $ar = $this->get('http://mau.platform.test/ar/about-ar')->assertOk()->getContent();
    expect($ar)->toContain('<html lang="ar" dir="rtl">')->toContain('<meta property="og:locale" content="ar_EG">');

    $en = $this->get('http://mau.platform.test/en/about')->assertOk()->getContent();
    expect($en)->toContain('<html lang="en">')->not->toContain('<html lang="en" dir=')->toContain('<meta property="og:locale" content="en_US">');

    expect($this->get('http://mau.platform.test/gioi-thieu')->getContent())->toContain('<meta property="og:locale" content="vi_VN">');
});

it('derives language names, direction and og:locale for any code', function (): void {
    expect(StorefrontChrome::languageName('vi'))->toBe('Tiếng Việt')
        ->and(StorefrontChrome::languageName('en'))->toBe('English')
        ->and(StorefrontChrome::languageName('ja'))->toBe('日本語')
        ->and(StorefrontChrome::languageName('fr'))->toBe('Français')
        ->and(StorefrontChrome::languageName('zh-Hant'))->toStartWith('中文')
        ->and(StorefrontChrome::dir('ar'))->toBe('rtl')
        ->and(StorefrontChrome::dir('he-IL'))->toBe('rtl')
        ->and(StorefrontChrome::dir('vi'))->toBe('ltr')
        ->and(StorefrontChrome::ogLocale('vi'))->toBe('vi_VN')
        ->and(StorefrontChrome::ogLocale('en'))->toBe('en_US')
        ->and(StorefrontChrome::ogLocale('ja'))->toBe('ja_JP')
        ->and(StorefrontChrome::ogLocale('de'))->toBe('de_DE')
        ->and(StorefrontChrome::ogLocale('en-GB'))->toBe('en_GB')
        ->and(StorefrontChrome::ogLocale('zh-Hant'))->toBe('zh_TW');
});

it('builds the switch from the public languages, falling back to each language’s home page', function (): void {
    $home = fn (string $locale): string => $locale === 'vi' ? '/' : '/'.$locale;
    $alternates = [['hreflang' => 'vi', 'href' => '/a'], ['hreflang' => 'en', 'href' => '/en/b'], ['hreflang' => 'x-default', 'href' => '/a']];

    $languages = StorefrontChrome::languages($alternates, 'vi', ['vi', 'en', 'ja'], $home);

    expect(array_column($languages, 'locale'))->toBe(['vi', 'en', 'ja'])
        ->and(array_column($languages, 'href'))->toBe(['/a', '/en/b', '/ja'])
        ->and(array_column($languages, 'fallback'))->toBe([false, false, true])
        ->and($languages[0]['current'])->toBeTrue()
        ->and(StorefrontChrome::languages($alternates, 'vi', ['vi'], $home))->toBe([]);
});

it('marks the current page in the drawer with aria-current, a pill and a check mark, in a named dialog', function (): void {
    drawerSite();

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<div id="mobile-menu" class="sf-drawer sf-drawer--dark" role="dialog" aria-modal="true" aria-label="Menu" hidden>')
        ->toContain('data-menu-close aria-label="Đóng menu"')
        ->toMatch('#<div class="sf-nav__panel" id="msm-1">.*?<a class="sf-nav__sublink"\s+href="/gioi-thieu"\s+aria-current="page"\s*>Giới thiệu\s*<svg class="sf-icon sf-nav__check"#s');

    // the pill is a layer behind the row in the track's grey, never the brand colour, and no bar is left
    $css = (string) StorefrontCss::all();
    expect($css)->toContain(".sf-nav--mob :is(.sf-nav__link, .sf-nav__sublink)[aria-current=\"page\"]::after {\n    content: \"\";\n    position: absolute;\n    inset: 2px 8px;\n    z-index: -1;\n    border-radius: 12px;\n    background: var(--sf-dw-track);")
        ->not->toContain('.sf-nav--mob .sf-nav__link[aria-current]::before');
});

it('prints no “Menu” title in the open drawer, only the button that opens it', function (): void {
    drawerSite();

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    preg_match('#<div id="mobile-menu".*?</nav>#s', $html, $drawer);
    $text = strip_tags($drawer[0] ?? '');

    expect($drawer)->not->toBeEmpty()
        ->and($text)->not->toContain('Menu')
        ->and($html)->toContain('<span class="sf-burger__word">Menu</span>');
});

it('shows the call in the header corner, and drops it while the phone bottom bar is on', function (): void {
    $siteId = drawerSite([]);

    expect($this->get('http://mau.platform.test/gioi-thieu')->getContent())
        ->toContain('<a class="sf-bar__call" href="tel:0901234567" aria-label="Gọi 0901 234 567">');

    // the bottom bar carries the call itself
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'floating_contact'],
        ['value' => ['enabled' => true, 'theme' => 'horizontal', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24,
            'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']]]],
    ));
    app(StorefrontSettings::class)->forget();   // the settings are memoized for the request
    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    // the header call stays for tablets and is hidden by CSS only where the bar shows (under 768px)
    expect($html)->toContain('<a class="sf-bar__call sf-bar__call--hasbar" href="tel:')->toContain('class="sf-fc-bar sf-fc-bar--light"');

    // an older round-button site with the phone bar reads as combo and does the same
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('key', 'floating_contact')->update(['value' => [
        'enabled' => true, 'theme' => 'vertical', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24,
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']], 'phone_bar' => ['enabled' => true],
    ]]));
    app(StorefrontSettings::class)->forget();
    expect($this->get('http://mau.platform.test/gioi-thieu')->getContent())->toContain('sf-bar__call--hasbar');

    // bar off again: the call is back
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('key', 'floating_contact')->update(['value' => ['enabled' => false]]));
    app(StorefrontSettings::class)->forget();
    expect($this->get('http://mau.platform.test/gioi-thieu')->getContent())->toContain('<a class="sf-bar__call" href="tel:');
});

it('follows the corner setting: search and cart draw nothing yet, none leaves it empty', function (string $corner): void {
    $siteId = drawerSite([]);
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('key', 'header')->update(['value' => ['top_bar' => ['items' => []], 'mobile_corner' => $corner]]));

    expect($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent())
        ->not->toContain('sf-bar__call')
        ->toContain('<span class="sf-bar__corner"></span>');
})->with(['search', 'cart', 'none']);

it('keeps the language, text size and contact blocks in the drawer in that order after the menu', function (): void {
    drawerSite();

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    $at = fn (string $needle): int => (int) strpos($html, $needle);

    expect($at('class="sf-nav sf-nav--mob"'))->toBeLessThan($at('sf-drawer__langs'))
        ->and($at('sf-drawer__langs'))->toBeLessThan($at('sf-drawer__fs'))
        ->and($at('sf-drawer__fs'))->toBeLessThan($at('sf-drawer__contact'))
        ->and($html)->toContain('<a class="sf-drawer__call" href="tel:0901234567">')
        ->toContain('href="mailto:lienhe@mau.test"');
});

it('gives the phone menu the header bar’s dark tone unless the header asks for a light one', function (mixed $tone, bool $dark): void {
    $siteId = drawerSite([]);
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('key', 'header')
        ->update(['value' => ['top_bar' => ['items' => []], 'text_size' => true, 'drawer_tone' => $tone]]));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->toContain('<div id="mobile-menu" class="sf-drawer'.($dark ? ' sf-drawer--dark' : '').'" role="dialog"');
})->with([
    'auto: the bar is dark' => ['auto', true],
    'not set' => [null, true],
    'light' => ['light', false],
    'dark' => ['dark', true],
    'an unknown word' => ['purple', true],
    'a list' => [['light'], true],
]);

it('opens the way to the current page in the phone menu on render, and leaves the desktop bar closed', function (): void {
    drawerSite([]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toMatch('#<li class="sf-nav__item sf-nav__item--parent is-open" data-disclosure>\s*<a class="sf-nav__link is-trail" href="[^"]+"\s*aria-current="true"\s*>Tin tức</a><button type="button" class="sf-nav__chev is-trail" aria-expanded="true" aria-controls="msm-1"#')
        ->toMatch('#<li class="sf-nav__item sf-nav__item--parent" data-disclosure>\s*<a class="sf-nav__link is-trail" href="[^"]+"\s*aria-current="true"\s*>Tin tức</a><button type="button" class="sf-nav__chev is-trail" aria-expanded="false" aria-controls="dsm-1"#')
        ->and(substr_count($html, ' is-open'))->toBe(1);

    // a page with no menu item below a parent: every group starts closed
    expect($this->get('http://mau.platform.test/')->assertOk()->getContent())
        ->not->toContain(' is-open')->not->toContain('aria-expanded="true" aria-controls="msm-');
});

it('puts a check mark after the current page in the phone menu only, and nowhere else', function (): void {
    drawerSite([]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect(substr_count($html, 'sf-nav__check'))->toBe(1)
        ->and($html)->toMatch('#<div class="sf-nav__panel" id="msm-1">.*?<a class="sf-nav__sublink"\s+href="/gioi-thieu"\s+aria-current="page"\s*>Giới thiệu\s*<svg class="sf-icon sf-nav__check" width="20" height="20" [^>]*aria-hidden="true"#s')
        ->and($html)->toMatch('#<div class="sf-nav__panel" id="dsm-1">.*?<a class="sf-nav__sublink"\s+href="/gioi-thieu"\s+aria-current="page"\s*>Giới thiệu</a>#s');

    // on every page: one check per current-page row of the phone menu, none in the desktop bar
    foreach (['/gioi-thieu', '/'] as $path) {
        $page = $this->get('http://mau.platform.test'.$path)->assertOk()->getContent();
        preg_match('#<nav class="sf-nav sf-nav--mob".*?</nav>#s', $page, $mob);
        preg_match('#<nav class="sf-nav sf-nav--desk".*?</nav>#s', $page, $desk);
        preg_match_all('#aria-current="page"[^>]*>[^<]*(?:<(?!/a>)[^<]*)*?<svg class="sf-icon sf-nav__check"#s', $mob[0] ?? '', $checked);
        expect(substr_count($mob[0] ?? '', 'sf-nav__check'))->toBe(substr_count($mob[0] ?? '', 'aria-current="page"'))
            ->and(count($checked[0]))->toBe(substr_count($mob[0] ?? '', 'aria-current="page"'))
            ->and($desk[0] ?? '')->not->toBe('')->not->toContain('sf-nav__check');
    }
});
