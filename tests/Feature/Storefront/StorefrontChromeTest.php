<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * The opt-in storefront header and footer (`storefront.header` / `storefront.footer` settings).
 * A site without them keeps the classic chrome byte for byte (golden fragment), apart from the
 * skip link, the main landmark id and the keyboard-operable submenu toggles every site now gets.
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

function sfcSite(string $name = 'Xưởng Mẫu', string $host = 'mau.platform.test'): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $name, siteName: $name, hostname: $host,
        ownerEmail: 'owner@'.$host, ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $siteId = Site::query()->firstWhere('name', $name)->id;

    app(Tenancy::class)->enter($siteId, function () use ($name): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'general', 'key' => 'brand'],
            ['value' => ['name' => $name, 'logo' => 'https://files.baoson.net/logo.png', 'license' => '0100000001',
                'phone' => '0901 234 567', 'email' => 'lienhe@mau.test', 'address' => 'Số 1 Đường Thử, TP Hà Nội']],
        );

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'Về chúng tôi', 'paragraphs' => ['Một đoạn.']]]]]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $entry->id]);

        // The provisioned primary menu (Trang chủ, Tin tức) gains a submenu under Tin tức and a
        // heading-only parent (#) with one child.
        $menu = Menu::query()->firstOrFail();
        $news = MenuItem::query()->where('menu_id', $menu->id)->where('link_type', 'taxonomy')->firstOrFail();
        $child = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $news->id, 'link_type' => 'entry',
            'routable_type' => 'entry', 'routable_id' => $entry->id, 'position' => 0]);
        $child->translations()->create(['locale' => 'vi', 'label' => 'Giới thiệu']);
        $heading = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '#', 'position' => 2]);
        $heading->translations()->create(['locale' => 'vi', 'label' => 'Năng lực']);
        $leaf = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $heading->id, 'link_type' => 'url',
            'url' => 'https://example.com/nang-luc', 'position' => 0]);
        $leaf->translations()->create(['locale' => 'vi', 'label' => 'Nhà xưởng']);
    });

    return $siteId;
}

/** The chrome of a rendered page: everything from <body> to the page h1, plus the footer. */
function sfcChrome(string $html): string
{
    preg_match('/<body>.*?(?=<h1)/s', $html, $top);
    preg_match('/<footer.*?<\/footer>/s', $html, $footer);

    return str_replace(date('Y'), '{year}', ($top[0] ?? '')."\n".($footer[0] ?? ''));
}

/** Stores a storefront setting for the site. */
function sfcSetting(int $siteId, string $key, array $value): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => $key],
        ['value' => $value],
    ));
}

/** A full header setting: three top-bar items (one per kind) and the text-size button. */
function sfcHeader(): array
{
    return [
        'top_bar' => ['items' => [
            ['kind' => 'address', 'text' => ['vi' => 'Số 1 Đường Thử', 'en' => '1 Test Street'], 'from' => 'lg'],
            ['kind' => 'phone', 'text' => '0901 234 567', 'value' => '+84 901-234-567', 'from' => 'md', 'phone_only_below' => 'md'],
            ['kind' => 'email', 'text' => 'lienhe@mau.test', 'value' => 'lienhe@mau.test', 'from' => 'sm'],
        ]],
        'text_size' => true,
        'sticky' => true,
    ];
}

/** A full footer setting with two factories, a licence issuer, a copyright start year and a credit. */
function sfcFooter(array $overrides = []): array
{
    return array_replace([
        'about' => ['vi' => 'Giới thiệu ngắn.', 'en' => 'Short about.'],
        'legal' => ['license_issuer' => 'Sở KH&ĐT Hà Nội', 'show_contact' => true],
        'factories' => [
            ['title' => ['vi' => 'Nhà máy 1'], 'area_m2' => 12000, 'name' => 'Xưởng cơ khí', 'address' => 'Lô A1, KCN Thử', 'map_url' => 'https://maps.google.com/?q=1'],
            ['title' => 'Nhà máy 2', 'address' => 'Lô B2, KCN Thử'],
        ],
        'copyright' => ['start_year' => 2010],
        'credit' => ['label' => 'Nhà Thiết Kế', 'url' => 'https://designer.example'],
    ], $overrides);
}

/** Binds a footer menu (one heading with two links, one of them a bare #) to the footer region. */
function sfcFooterMenu(int $siteId): void
{
    app(Tenancy::class)->enter($siteId, function (): void {
        $menu = Menu::query()->create(['name' => 'Chân trang', 'handle' => 'chan-trang']);
        $heading = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '#', 'position' => 0]);
        $heading->translations()->create(['locale' => 'vi', 'label' => 'Liên kết']);
        foreach ([['https://example.com/a', 'Trang A'], ['#', 'Chưa có trang']] as $position => [$url, $label]) {
            $item = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $heading->id, 'link_type' => 'url', 'url' => $url, 'position' => $position]);
            $item->translations()->create(['locale' => 'vi', 'label' => $label]);
        }
        MenuAssignment::query()->create(['region_key' => 'footer', 'locale' => 'vi', 'menu_id' => $menu->id]);
    });
}

it('renders the classic chrome of a site without storefront settings exactly as pinned', function (): void {
    sfcSite();
    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect(sfcChrome($html))->toBe(file_get_contents(__DIR__.'/golden/default-chrome.html'))
        ->and($html)->not->toContain('sf-header')
        ->not->toContain('sf-footer')
        ->not->toContain('fs-boot.js')
        ->not->toContain('rel="preload" href="/fonts/');
});

it('renders the top bar with safe tel and mailto links and each item’s breakpoint classes', function (): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'header', sfcHeader());

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<header class="sf-header sf-header--sticky sf-header--topbar">')
        ->toContain('<li class="sf-topbar__item sf-from-lg">')
        ->toContain('<li class="sf-topbar__item sf-from-md sf-below-md">')
        ->toContain('<a class="sf-topbar__link" href="tel:+84901234567">')
        ->toContain('<a class="sf-topbar__link" href="mailto:lienhe@mau.test">')
        ->toContain('<span>Số 1 Đường Thử</span>')
        ->toContain('data-fs-toggle')
        ->toContain('aria-label="Cỡ chữ: Vừa. Bấm để đổi"')
        ->toMatch('#<script src="/storefront/fs-boot\.js\?v=[0-9a-f]{10}"></script>#')
        ->not->toContain('site-header');
});

it('makes submenus disclosures: a # parent is a button, a page parent keeps its link plus a toggle', function (): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'header', ['sticky' => false]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<header class="sf-header">')
        ->not->toContain('sf-topbar')
        ->toMatch('#<button type="button" class="sf-nav__link sf-nav__toggle" aria-expanded="false" aria-controls="dsm-2"><span>Năng lực</span>#')
        ->toMatch('#<a class="sf-nav__link is-trail" href="/tin-tuc"\s*>Tin tức</a><button type="button" class="sf-nav__chev is-trail" aria-expanded="false" aria-controls="dsm-1" aria-label="Mở menu con Tin tức">#')
        ->toContain('<div class="sf-nav__panel" id="dsm-1">')
        ->toContain('<div class="sf-nav__panel" id="msm-1">')
        ->toMatch('#<a class="sf-nav__sublink" href="/gioi-thieu"\s+aria-current="page"\s*>Giới thiệu</a>#')
        ->toContain('<nav id="mobile-menu" class="sf-nav sf-nav--mob" aria-label="Điều hướng chính" hidden>')
        ->toContain('aria-controls="mobile-menu"');
});

it('gives the classic menu keyboard-operable submenu toggles too', function (): void {
    sfcSite();

    expect($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent())
        ->toContain('<button type="button" class="site-nav__link site-nav__toggle" aria-expanded="false" aria-controls="sm-2">Năng lực')
        ->toContain('aria-label="Mở menu con Tin tức"')
        ->toContain('<ul class="site-nav__list site-nav__list--level-1" id="sm-1">')
        ->toContain('class="site-nav__item site-nav__item--parent is-trail" data-disclosure');
});

it('puts the skip link first, aimed at the main content', function (string $path, string $landmark): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'header', sfcHeader());

    $html = $this->get('http://mau.platform.test'.$path)->assertOk()->getContent();
    preg_match('/<body>\s*(<[^>]+>)/', $html, $first);

    expect($first[1])->toBe('<a class="sf-skip" href="#noi-dung">')
        ->and($html)->toMatch('#<'.$landmark.'[^>]* id="noi-dung" tabindex="-1">#');
})->with([
    'page' => ['/gioi-thieu', 'main'],
    'category' => ['/tin-tuc', 'main'],
]);

it('renders the rich footer: factories, dated licence with its issuer, copyright range and credit', function (): void {
    $siteId = sfcSite();
    app(Tenancy::class)->enter($siteId, fn () => SiteProfile::query()->firstOrFail()->update([
        'legal_name' => 'Công ty TNHH Xưởng Mẫu', 'license_issued_at' => '2010-03-05',
        'social_links' => ['facebook' => 'https://facebook.com/xuongmau', 'myspace' => 'https://myspace.com/x'],
    ]));
    sfcSetting($siteId, 'footer', sfcFooter());
    sfcFooterMenu($siteId);
    $year = date('Y');

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<footer class="sf-footer" role="contentinfo">')
        ->toContain('<p class="sf-footer__about">Giới thiệu ngắn.</p>')
        ->toContain('<h2 class="sf-footer__legal-name">Công ty TNHH Xưởng Mẫu</h2>')
        ->toContain('GPĐKKD: 0100000001 do Sở KH&amp;ĐT Hà Nội cấp ngày 05/03/2010')
        ->toContain('<h3 class="sf-factory__title">Nhà máy 1</h3>')
        ->toContain('<h3 class="sf-factory__title">Nhà máy 2</h3>')
        ->toContain('Diện tích: 12.000 m²')
        ->toContain('href="https://maps.google.com/?q=1" rel="noopener" aria-label="Xem bản đồ Nhà máy 1 (mở trang ngoài)"')
        ->toContain("Bản quyền © 2010 - {$year} thuộc về <strong>Xưởng Mẫu</strong>")
        ->toContain('Thiết kế website bởi <a href="https://designer.example" rel="noopener"><strong>Nhà Thiết Kế</strong></a>')
        ->toContain('href="https://facebook.com/xuongmau" rel="noopener" aria-label="Facebook của Xưởng Mẫu (mở trang ngoài)"')
        ->not->toContain('sf-footer__social" href="https://myspace.com')   // no icon for that network
        ->toContain('<h2 class="sf-footer__heading">')
        ->toContain('<a class="sf-footer__link" href="https://example.com/a"')
        ->not->toContain('Chưa có trang')
        ->not->toContain('site-footer');

    sfcSetting($siteId, 'footer', sfcFooter(['credit' => null, 'copyright' => ['start_year' => (int) $year]]));
    $plain = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($plain)->not->toContain('sf-footer__credit')
        ->toContain("Bản quyền © {$year} thuộc về <strong>Xưởng Mẫu</strong>");
});

it('drops unsafe links from map, credit and social settings', function (string $url): void {
    $siteId = sfcSite();
    app(Tenancy::class)->enter($siteId, fn () => SiteProfile::query()->firstOrFail()->update(['social_links' => ['facebook' => $url]]));
    sfcSetting($siteId, 'footer', sfcFooter([
        'factories' => [['title' => 'Nhà máy', 'map_url' => $url]],
        'credit' => ['label' => 'Nhà Thiết Kế', 'url' => $url],
    ]));
    sfcSetting($siteId, 'header', ['top_bar' => ['items' => [['kind' => 'link', 'text' => 'Liên kết', 'url' => $url]]]]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->not->toContain('sf-factory__map')
        ->not->toContain('sf-footer__social')
        ->not->toContain('>Liên kết<')
        ->toContain('<strong>Nhà Thiết Kế</strong>')
        ->not->toContain('href="'.e($url).'"');
})->with([
    'javascript' => 'javascript:alert(1)',
    'protocol-relative' => '//evil.example/x',
    'data' => 'data:text/html,<b>x</b>',
    'backslash' => '/\\evil.example',
]);

it('escapes markup in setting texts', function (): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'footer', sfcFooter([
        'about' => '<script>alert(1)</script>',
        'factories' => [['title' => '<img src=x onerror=alert(1)>', 'address' => '<b>đậm</b>']],
    ]));
    sfcSetting($siteId, 'header', ['top_bar' => ['items' => [['kind' => 'address', 'text' => '"><script>alert(2)</script>']]]]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert')
        ->not->toContain('<img src=x')
        ->not->toContain('<b>đậm</b>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('keeps each site’s chrome apart when one worker serves two sites in turn', function (): void {
    $a = sfcSite('Xưởng Một', 'mot.platform.test');
    $b = sfcSite('Xưởng Hai', 'hai.platform.test');
    sfcSetting($a, 'footer', sfcFooter(['about' => 'Về xưởng một.']));
    sfcSetting($b, 'header', ['top_bar' => ['items' => [['kind' => 'address', 'text' => 'Địa chỉ xưởng hai']]]]);

    $first = $this->get('http://mot.platform.test/gioi-thieu')->assertOk()->getContent();
    $second = $this->get('http://hai.platform.test/gioi-thieu')->assertOk()->getContent();
    $again = $this->get('http://mot.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($first)->toContain('Về xưởng một.')->not->toContain('Địa chỉ xưởng hai')->not->toContain('sf-header')
        ->and($second)->toContain('Địa chỉ xưởng hai')->not->toContain('Về xưởng một.')->not->toContain('sf-footer')
        ->and($again)->toBe($first);
});

it('picks setting texts in the page language, falling back to the default language', function (): void {
    $siteId = sfcSite();
    app(Tenancy::class)->enter($siteId, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $entry = Entry::query()->findOrFail(EntryTranslation::query()->where('slug', 'gioi-thieu')->value('entry_id'));
        $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'About us', 'paragraphs' => ['One.']]]]]]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/about', 'entry_id' => $entry->id]);
    });
    sfcSetting($siteId, 'header', sfcHeader());
    sfcSetting($siteId, 'footer', sfcFooter());

    $html = $this->get('http://mau.platform.test/en/about')->assertOk()->getContent();

    expect($html)
        ->toContain('<span>1 Test Street</span>')                          // en given
        ->toContain('<p class="sf-footer__about">Short about.</p>')
        ->toContain('<h3 class="sf-factory__title">Nhà máy 1</h3>')        // no en: the vi text
        ->toContain('aria-label="Text size: Medium. Press to change"')
        ->toContain('Business registration no.: 0100000001, issued by Sở KH&amp;ĐT Hà Nội')
        ->toMatch('#href="http://mau\.platform\.test/en/about" hreflang="en" lang="en"\s+aria-current="true"\s*>EN</a>#')
        ->toMatch('#hreflang="vi" lang="vi"\s*>VI</a>#')
        ->toContain('<nav class="sf-lang" aria-label="Language">')
        ->not->toContain('<span>Số 1 Đường Thử</span>');
});

it('gives a page led by a title band a single h1, the band’s, defaulting to the page title', function (): void {
    $siteId = sfcSite();
    app(Tenancy::class)->enter($siteId, fn () => EntryTranslation::query()->where('slug', 'gioi-thieu')->firstOrFail()->update(['body' => ['blocks' => [
        ['type' => 'page_header', 'props' => ['subtitle' => 'Về chúng tôi']],
        ['type' => 'page_header', 'props' => ['title' => 'Phần hai']],
        ['type' => 'richtext', 'props' => ['heading' => 'Đoạn', 'paragraphs' => ['Một đoạn.']]],
    ]]]));

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<h1 class="page-band__title">Giới thiệu</h1>')
        ->toContain('<h2 class="page-band__title">Phần hai</h2>')
        ->not->toContain('page__title');
});
