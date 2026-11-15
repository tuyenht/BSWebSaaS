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

    // four languages: a "Ngôn ngữ · <current>" row that opens the list below it (collapsed), one 48px row link per language
    expect($html)
        ->toContain('<button type="button" class="sf-lang-h" data-lang-head aria-expanded="false" aria-controls="msm-lang-list">')
        ->toMatch('#<span class="sf-lang-h__t">Ngôn ngữ · <span lang="vi" dir="ltr">Tiếng Việt</span></span>#')
        ->toContain('<nav class="sf-lang-list" id="msm-lang-list" aria-label="Ngôn ngữ">')
        ->not->toContain('data-lang-sub')
        ->not->toContain('sf-seg--wrap')
        ->toMatch('#<a class="sf-lang-r" href="[^"]*" hreflang="vi" lang="vi" dir="ltr" aria-current="true"><span class="sf-lang-r__t"><span class="sf-lang-r__n">Tiếng Việt</span> </span><span class="sf-lang-r__ck" aria-hidden="true">#')   // the current one: a check, no gloss
        ->toMatch('#href="http://mau\.platform\.test/en/about" hreflang="en" lang="en" dir="ltr"><span class="sf-lang-r__t"><span class="sf-lang-r__n">English</span><span class="sf-lang-r__g" lang="vi">Tiếng Anh</span> </span></a>#')   // a real translation, with its Vietnamese name
        ->toMatch('#href="/ja" hreflang="ja" lang="ja" dir="ltr"><span class="sf-lang-r__t"><span class="sf-lang-r__n">日本語</span><span class="sf-lang-r__g" lang="vi">Tiếng Nhật</span> <small class="sf-drawer__hint">\(trang chủ\)</small></span></a>#')   // no ja page: its home page, said so
        ->toMatch('#href="http://mau\.platform\.test/ar/about-ar" hreflang="ar" lang="ar" dir="rtl"#')
        ->not->toContain('hreflang="ko"');

    // the hreflang alternates stay the real translations only (no fallback target)
    expect($html)->not->toContain('<link rel="alternate" hreflang="ja"');
});

it('draws two or three languages as one segmented control and no list row', function (array $extra): void {
    drawerSite($extra);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<nav class="sf-drawer__langs" aria-label="Ngôn ngữ">')
        ->toContain('<ul class="sf-seg">')
        ->toMatch('#<a class="sf-seg__s" href="[^"]*" hreflang="vi" lang="vi" dir="ltr" aria-current="true">Tiếng Việt</a>#')
        ->not->toContain('sf-lang-h')->not->toContain('data-lang-sub');
})->with([
    'two' => [['en']],
    'three' => [['en', 'ja']],
]);

it('draws four to seven languages as a collapsed list under a "Ngôn ngữ · name" row, with no screen of its own', function (): void {
    drawerSite(['en', 'ja', 'ar', 'fr', 'de', 'es']);   // seven public languages with vi

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect(substr_count($html, 'class="sf-lang-r"'))->toBe(7)
        ->and($html)->toContain('data-lang-head aria-expanded="false" aria-controls="msm-lang-list">')
        ->toContain('<nav class="sf-lang-list" id="msm-lang-list" aria-label="Ngôn ngữ">')
        ->not->toContain('aria-haspopup')->not->toContain('data-lang-sub')->not->toContain('class="sf-seg"');
});

it('draws eight or more languages as a row opening a screen with a labelled search, the language in use and the others', function (): void {
    drawerSite(['en', 'ja', 'ar', 'fr', 'de', 'es', 'th']);   // eight public languages with vi

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    $sub = substr($html, (int) strpos($html, '<div class="sf-lang-sub"'));
    $sub = substr($sub, 0, (int) strpos($sub, 'data-lang-none'));

    expect($html)
        ->toContain('data-lang-head aria-expanded="false" aria-controls="msm-lang-list">')
        ->toContain('<p class="sf-lang-none" role="status" data-lang-none data-text="Không có ngôn ngữ nào khớp."></p>')   // always there, filled by the script
        ->not->toContain('class="sf-lang-list"');
    expect($sub)
        ->toContain('id="msm-lang-list" role="group" aria-labelledby="msm-lang-title" data-lang-sub')
        ->toContain('<span>Menu</span></button>')
        ->toContain('<h2 class="sf-lang-sub__t" id="msm-lang-title">Ngôn ngữ</h2>')
        ->toContain('<label class="sr-only" for="msm-lang-q">Tìm ngôn ngữ…</label>')
        ->toContain('id="msm-lang-q" type="search"')
        ->toContain('>Đang dùng</p>')->toContain('>Ngôn ngữ khác</p>')
        ->and(substr_count($sub, 'aria-current="true"'))->toBe(1)       // one language in use
        ->and(substr_count($sub, 'class="sf-lang-r"'))->toBe(8)          // every language once: none is lost
        ->and(substr_count($sub, 'hreflang="th" lang="th" dir="ltr"'))->toBe(1);
});

it('shows no language switch on a site with one public language', function (): void {
    drawerSite([]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->toContain('id="mobile-menu"')
        ->not->toContain('sf-drawer__lang')->not->toContain('sf-lang-h')
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

it('marks the current page in the drawer with aria-current, a neutral pill and a bar, in a named dialog', function (): void {
    drawerSite();

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<div id="mobile-menu" class="sf-drawer" role="dialog" aria-modal="true" aria-label="Menu" hidden style="--sf-dw-bg:#')
        ->toContain('data-menu-close aria-label="Đóng menu"')
        ->toMatch('#<div class="sf-nav__panel" id="msm-1">.*?<a class="sf-nav__sublink" href="/gioi-thieu"\s+aria-current="page"\s*><span class="sf-nav__t">Giới thiệu</span></a>#s');

    // the pill is a layer behind the row, 8px in from each side, in the drawer's own neutral; the bar is 4x20 at 12px
    $css = (string) StorefrontCss::all();
    expect($css)->toContain(".sf-nav--mob :is(.sf-nav__link:not(.sf-nav__link--cta), .sf-nav__sublink)::after {\n    content: \"\";\n    position: absolute;\n    inset: 2px 8px;\n    z-index: -1;\n    border-radius: 10px;")
        ->toContain(".sf-nav--mob :is(.sf-nav__link:not(.sf-nav__link--cta), .sf-nav__sublink)[aria-current=\"page\"]::after {\n    background: var(--sf-dw-pill);")
        // logical sides, so a right-to-left page mirrors the bar
        ->toContain("    inset-inline-start: 12px;\n    width: 4px;\n    height: 20px;\n    margin-top: -10px;\n    border-radius: 2px;\n    background: var(--sf-dw-strong);")
        ->not->toContain('--sf-dw-here');                                   // the brand never tints the current row
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

it('keeps language, text size and the call in the drawer’s footer in that order after the menu, with no email', function (): void {
    drawerSite();

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    $drawer = drawerOf($html);
    $at = fn (string $needle): int => (int) strpos($drawer, $needle);

    expect($at('class="sf-nav sf-nav--mob"'))->toBeLessThan($at('sf-drawer__foot'))
        ->and($at('sf-drawer__foot'))->toBeLessThan($at('sf-drawer__lang'))
        ->and($at('sf-drawer__lang'))->toBeLessThan($at('sf-drawer__fs'))
        ->and($at('sf-drawer__fs'))->toBeLessThan($at('sf-drawer__call'))
        ->and($drawer)->toContain('<a class="sf-drawer__call" href="tel:0901234567">')
        ->not->toContain('mailto:')->not->toContain('sf-drawer__contact')->not->toContain('Kết nối với chúng tôi');
});

it('draws the phone menu light by default and dark when the site asks for dark or for the header bar’s tone, with that tone’s colours on it', function (mixed $tone, bool $dark): void {
    $siteId = drawerSite([]);
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->where('key', 'header')
        ->update(['value' => ['top_bar' => ['items' => []], 'text_size' => true, 'drawer_tone' => $tone]]));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    // the panel at 96% (light, f5) or 97% (dark, f7) over a scrim of 34% (57) or 44% (70) ink
    expect($html)->toContain('<div id="mobile-menu" class="sf-drawer'.($dark ? ' sf-drawer--dark' : '').'" role="dialog"')
        ->toMatch($dark ? '#--sf-dw-panel:\#[0-9a-f]{6}f7;--sf-dw-scrim:\#[0-9a-f]{6}70;#' : '#--sf-dw-panel:\#[0-9a-f]{6}f5;--sf-dw-scrim:\#[0-9a-f]{6}57;#');
})->with([
    'auto: the header bar’s, which is dark' => ['auto', true],
    'not set' => [null, false],
    'light' => ['light', false],
    'dark' => ['dark', true],
    'an unknown word' => ['purple', false],
    'a list' => [['dark'], false],
]);

it('opens the way to the current page in the phone menu on render, and leaves the desktop bar closed', function (): void {
    drawerSite([]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        // the drawer: the whole row is one button
        ->toMatch('#<li class="sf-nav__item sf-nav__item--parent is-open" data-disclosure>\s*<button type="button" class="sf-nav__link sf-nav__toggle is-trail" aria-current="true" aria-expanded="true" aria-controls="msm-1"><span class="sf-nav__t">Tin tức</span><span class="sf-nav__caret" aria-hidden="true">#')
        ->toMatch('#<li class="sf-nav__item sf-nav__item--parent" data-disclosure>\s*<a class="sf-nav__link is-trail" href="[^"]+"\s*aria-current="true"\s*>Tin tức</a><button type="button" class="sf-nav__chev is-trail" aria-expanded="false" aria-controls="dsm-1"#')
        ->and(substr_count($html, ' is-open'))->toBe(1);

    // a page with no menu item below a parent: every group starts closed
    expect($this->get('http://mau.platform.test/')->assertOk()->getContent())
        ->not->toContain(' is-open')->not->toContain('aria-expanded="true" aria-controls="msm-');
});

it('marks the current page by aria-current alone, with no check mark in either menu', function (): void {
    drawerSite([]);

    // the mark is drawn by CSS from aria-current, so each menu names exactly its current rows and nothing else
    foreach (['/gioi-thieu', '/'] as $path) {
        $page = $this->get('http://mau.platform.test'.$path)->assertOk()->getContent();
        preg_match('#<nav class="sf-nav sf-nav--mob".*?</nav>#s', $page, $mob);
        preg_match('#<nav class="sf-nav sf-nav--desk".*?</nav>#s', $page, $desk);
        expect($page)->not->toContain('sf-nav__check')
            ->and(substr_count($mob[0] ?? '', 'aria-current="page"'))->toBe(1)
            ->and($desk[0] ?? '')->not->toBe('');
    }

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    expect($html)->toMatch('#<div class="sf-nav__panel" id="msm-1">.*?<a class="sf-nav__sublink" href="/gioi-thieu"\s+aria-current="page"\s*><span class="sf-nav__t">Giới thiệu</span></a>#s')
        ->and($html)->toMatch('#<div class="sf-nav__panel" id="dsm-1">.*?<a class="sf-nav__sublink"\s+href="/gioi-thieu"\s+aria-current="page"\s*>Giới thiệu</a>#s');
});

/** The phone menu's markup in a page: from the drawer to the no-script styles after it. */
function drawerOf(string $html): string
{
    $from = (int) strpos($html, '<div id="mobile-menu"');

    return substr($html, $from, (int) strpos($html, '<noscript>', $from) - $from);
}

it('lines the labels up on the icon column when a top-level item has an icon, and names no icon class otherwise', function (): void {
    expect(StorefrontChrome::drawerHasIcons([['label' => 'A', 'icon' => 'home'], ['label' => 'B']]))->toBeTrue()
        ->and(StorefrontChrome::drawerHasIcons([['label' => 'A', 'icon' => 'no-such-icon'], ['label' => 'B']]))->toBeFalse()
        ->and(StorefrontChrome::drawerHasIcons([['label' => 'A', 'icon' => 'home', 'cta' => true]]))->toBeFalse()   // the button row has its own layout
        ->and(StorefrontChrome::drawerHasIcons([['label' => 'A', 'children' => [['label' => 'B', 'icon' => 'home']]]]))->toBeFalse()
        ->and((string) StorefrontChrome::menuLabel(['label' => 'Trang chủ', 'icon' => 'home'], false, true))
        ->toContain('sf-icon sf-nav__ic')->toEndWith('<span class="sf-nav__t">Trang chủ</span>')
        ->and((string) StorefrontChrome::menuLabel(['label' => 'Trang chủ'], false, true))->toBe('<span class="sf-nav__t">Trang chủ</span>')
        ->and((string) StorefrontChrome::menuLabel(['label' => 'Trang chủ']))->toBe('Trang chủ');   // the desktop label is unchanged
});
