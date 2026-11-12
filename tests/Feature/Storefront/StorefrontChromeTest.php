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
use App\Modules\Theme\Chrome\StorefrontChrome;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCss;

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
        ->not->toContain(StorefrontChrome::TEXT_SIZE_BOOT)
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
        ->toContain('<script>'.StorefrontChrome::TEXT_SIZE_BOOT.'</script>')
        ->not->toContain('site-header');
});

it('keeps the text-size button in the menu panel, never in the top bar, when the site has a menu', function (): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'header', sfcHeader());

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    $topBar = substr($html, (int) strpos($html, '<div class="sf-topbar">'), (int) strpos($html, '<div class="sf-bar">') - (int) strpos($html, '<div class="sf-topbar">'));

    expect($topBar)->toContain('sf-topbar__items')->not->toContain('data-fs-toggle')
        ->and(substr_count($html, 'data-fs-toggle'))->toBe(1)
        ->and($html)->toContain('<button type="button" class="sf-fs sf-drawer__fs" data-fs-toggle');
});

it('draws no top bar where it would hold only the text-size button, and none at a width with nothing in it', function (): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'header', ['top_bar' => ['items' => []], 'text_size' => true, 'sticky' => false]);

    // the text-size button lives in the menu panel: an empty top bar is not drawn at all
    $this->get('http://mau.platform.test/gioi-thieu')->assertOk()
        ->assertSee('<header class="sf-header">', false)
        ->assertDontSee('sf-topbar', false);

    // every item from 640px up: the phone width (xs) gets no top bar
    sfcSetting($siteId, 'header', ['top_bar' => ['items' => [
        ['kind' => 'email', 'text' => 'lienhe@mau.test', 'value' => 'lienhe@mau.test', 'from' => 'sm'],
    ]], 'text_size' => true, 'sticky' => false]);
    $this->get('http://mau.platform.test/gioi-thieu')->assertOk()
        ->assertSee('<header class="sf-header sf-header--topbar sf-header--top-off-xs">', false);

    // something at every width: no band is switched off
    sfcSetting($siteId, 'header', sfcHeader());
    $this->get('http://mau.platform.test/gioi-thieu')->assertOk()
        ->assertSee('<header class="sf-header sf-header--sticky sf-header--topbar">', false)
        ->assertDontSee('sf-header--top-off', false);
});

it('lets the top bar\'s phone items step aside under 768px while the phone bottom bar carries the call', function (): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'header', ['top_bar' => ['items' => [
        ['kind' => 'phone', 'text' => '0901 234 567', 'value' => '+84 901-234-567', 'from' => 'md', 'phone_only_below' => 'md'],
        ['kind' => 'email', 'text' => 'lienhe@mau.test', 'value' => 'lienhe@mau.test', 'from' => 'md'],
    ]], 'text_size' => true, 'sticky' => false]);
    sfcSetting($siteId, 'floating_contact', ['enabled' => true, 'theme' => 'vertical', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24,
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']], 'phone_bar' => ['enabled' => true]]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    // the phone-only copy (below md) was all the top bar had under 768px: those two bands draw none
    expect($html)->toContain('<header class="sf-header sf-header--topbar sf-header--top-off-xs sf-header--top-off-sm">')
        ->toContain('<li class="sf-topbar__item sf-from-md sf-below-md sf-topbar__item--bar">')
        ->toContain('<li class="sf-topbar__item sf-from-md">')
        ->and((string) StorefrontCss::all())
        ->toContain("@media (max-width: 767.98px) {\n    .sf-topbar__item--bar {\n        display: none;");
});

it('hides the menu button from lg (xl for a wide menu) and the text-size control from lg, after the rules that show them', function (): void {
    // equal selectors: the later rule wins, so each hiding rule must come after the base rule it overrides
    $css = (string) StorefrontCss::all();
    $at = fn (string $needle): int => (int) strpos($css, $needle);

    foreach ([
        "@media (min-width: 1024px) {\n    .sf-header:not(.sf-header--menu-xl) .sf-burger {\n        display: none;",
        "@media (min-width: 1280px) {\n    .sf-header--menu-xl .sf-burger {\n        display: none;",
        "@media (min-width: 1024px) {\n    .sf-fs {\n        display: none;",
    ] as $hiding) {
        $class = str_contains($hiding, 'sf-fs') ? 'sf-fs' : 'sf-burger';
        $base = $at("\n.{$class} {\n    display: inline-flex;");

        expect($base)->toBeGreaterThan(0, "base .{$class} rule not found")
            ->and($at($hiding))->toBeGreaterThan($base, "{$hiding} must come after its base rule");
    }
    // the desktop menu and the phone panel switch at the same width as the menu button: lg, or xl
    // for a menu too wide for a laptop; nothing squeezes a wide menu into 1024–1279 any more
    expect($css)->toContain("@media (min-width: 1024px) {\n    .sf-header:not(.sf-header--menu-xl) .sf-nav--desk {\n        display: block;\n        margin-left: auto;")
        ->toContain("@media (min-width: 1280px) {\n    .sf-header--menu-xl .sf-nav--desk {\n        display: block;\n        margin-left: auto;")
        ->toContain("@media (min-width: 1024px) {\n    .sf-header:not(.sf-header--menu-xl) .sf-nav--mob {\n        visibility: hidden;")
        ->toContain("@media (min-width: 1280px) {\n    .sf-header--menu-xl .sf-nav--mob {\n        visibility: hidden;")
        ->not->toContain('@media (min-width: 1024px) and (max-width: 1279.98px)')
        ->and(preg_match('/@media \(min-width: 1280px\) \{\s*\.sf-(?:burger|nav--desk|nav--mob) \{/', $css))->toBe(0);
    // the enlarged sizes apply below lg only
    expect(preg_match('/@media \(max-width: 1023\.98px\) \{\s*html\[data-fs="l"\][^}]*\}\s*html\[data-fs="xl"\]/', $css))->toBe(1)
        ->and(substr_count($css, 'html[data-fs='))->toBe(2);
});

it('keeps a menu that fits a laptop from 1024px and gives a wide one the menu button up to 1280px', function (): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'header', ['sticky' => false]);

    // the provisioned menu (three short items) fits beside the logo at 1024px
    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    expect($html)->toContain('<header class="sf-header">')
        ->toContain('<noscript><style>@media (max-width:1023.98px){.sf-drawer[hidden]{display:block;position:static}');

    // six more top-level items: An Vinh's nine-item menu wraps at 1024 and 1100px beside its logo
    app(Tenancy::class)->enter($siteId, function (): void {
        $menu = Menu::query()->firstOrFail();
        foreach (['Sản phẩm', 'Dự án', 'Đối tác', 'Tuyển dụng', 'Liên hệ', 'Chứng chỉ'] as $i => $label) {
            $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => 'https://example.com/'.$i, 'position' => 10 + $i]);
            $item->translations()->create(['locale' => 'vi', 'label' => $label]);
        }
    });
    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    expect($html)->toContain('<header class="sf-header sf-header--menu-xl">')
        ->toContain('<noscript><style>@media (max-width:1279.98px){.sf-drawer[hidden]{display:block;position:static}');
});

it('shows each menu item set to one device by the screen width, in the menu panel too', function (): void {
    // From 1024px the menu panel serves laptops (a wide menu keeps it up to 1280px): a computer-only
    // item must be reachable there, and a phone-only one hidden. Below 1024px the reverse.
    $css = (string) StorefrontCss::all();

    expect($css)->toContain("@media (max-width: 1023.98px) {\n    .sf-only-desk {\n        display: none !important;\n    }\n}")
        ->toContain("@media (min-width: 1024px) {\n    .sf-only-mob {\n        display: none !important;\n    }\n}")
        ->and(preg_match_all('/[^{}]*\.sf-only-(?:desk|mob)[^{}]*\{/', $css, $rules))->toBe(2, 'one rule per device class: '.implode(' | ', $rules[0]))
        ->and(implode('', $rules[0]))->not->toContain('sf-nav--');
});

it('keeps every menu and top-bar target 40px with a mouse and 44px on touch, at every width', function (): void {
    $css = (string) StorefrontCss::all();
    preg_match('/\/\* Touch screens: every control of the rich chrome reaches 44px, at every width\. \*\/\n@media \(any-pointer: coarse\) \{(.*?)\n\}\n/s', $css, $touch);

    expect($touch[1] ?? '')->toContain(".sf-nav__link,\n    .sf-nav__chev,\n    .sf-nav__sublink,\n    .sf-footer__link,\n    .sf-factory__map {\n        min-height: 2.75rem;")
        ->toContain(".sf-nav__chev {\n        min-width: 2.75rem;")
        ->toContain(".sf-nav--desk .sf-nav__item--parent > .sf-nav__chev::after {\n        inset: 0 calc(100% - 2.75rem) 0 0;");
    // a parent's chevron draws narrow (spaced like a plain item) and reaches 40px through its extension
    expect($css)->toContain(".sf-nav--desk .sf-nav__item--parent > .sf-nav__chev {\n    position: relative;\n    z-index: 1;\n    min-width: 0;\n    padding: 0 0.75rem 0 0.25rem;")
        ->toContain(".sf-nav--desk .sf-nav__item--parent > .sf-nav__chev::after {\n    content: \"\";\n    position: absolute;\n    inset: 0 calc(100% - 2.5rem) 0 0;");
    // from lg the top bar draws 32px (2rem), after the touch rule; its links reach 40 / 44px below it
    $touchAt = (int) strpos($css, '@media (any-pointer: coarse) {'."\n".'    .sf-header--topbar {');
    $barAt = (int) strpos($css, "@media (min-width: 1024px) {\n    .sf-header--topbar:not(.sf-header--top-off-lg) {\n        --sf-topbar-h: 2rem;");
    expect($touchAt)->toBeGreaterThan(0)->and($barAt)->toBeGreaterThan($touchAt)
        ->and($css)->toContain(".sf-topbar :is(.sf-topbar__link, .sf-lang__link)::after {\n        content: \"\";\n        position: absolute;\n        inset: 0 0 -0.5rem;")
        ->toContain("@media (min-width: 1024px) and (any-pointer: coarse) {\n    .sf-topbar :is(.sf-topbar__link, .sf-lang__link)::after {\n        bottom: -0.75rem;");
});

it('makes submenus disclosures: a # parent is a button, a page parent keeps its link plus a toggle', function (): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'header', ['sticky' => false]);

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<header class="sf-header">')
        ->not->toContain('sf-topbar')
        ->toMatch('#<button type="button" class="sf-nav__link sf-nav__toggle" aria-expanded="false" aria-controls="dsm-2"><span>Năng lực</span>#')
        ->toMatch('#<a class="sf-nav__link is-trail" href="/tin-tuc"\s*aria-current="true"\s*>Tin tức</a><button type="button" class="sf-nav__chev is-trail" aria-expanded="false" aria-controls="dsm-1" aria-label="Mở menu con Tin tức">#')
        ->toContain('<div class="sf-nav__panel" id="dsm-1">')
        ->toContain('<div class="sf-nav__panel" id="msm-1">')
        ->toMatch('#<a class="sf-nav__sublink" href="/gioi-thieu"\s+aria-current="page"\s*>Giới thiệu</a>#')
        ->toContain('<div id="mobile-menu" class="sf-drawer" role="dialog" aria-modal="true" aria-label="Menu" hidden>')
        ->toContain('aria-controls="mobile-menu"');
});

it('gives the classic menu keyboard-operable submenu toggles too', function (): void {
    sfcSite();

    expect($this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent())
        ->toContain('<button type="button" class="site-nav__link site-nav__toggle" aria-expanded="false" aria-controls="sm-2">Năng lực')
        ->toContain('aria-label="Mở menu con Tin tức"')
        ->toContain('<ul class="site-nav__list site-nav__list--level-1" id="sm-1">')
        ->toContain('class="site-nav__item site-nav__item--parent is-trail" data-disclosure')
        ->toMatch('#<a class="site-nav__link" href="/tin-tuc"\s*aria-current="true"\s*>Tin tức</a>#');
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

it('keeps the hidden skip link and the top bar from widening the page at 200% text', function (): void {
    // A browser measures the page width; this pins the two rules that made a 320-390px page 410px wide
    // at 200% text: a skip link clipped at full size, and top-bar items that could not wrap.
    $css = (string) StorefrontCss::all();
    $rule = function (string $selector) use ($css): string {
        expect(preg_match('/\n'.preg_quote($selector, '/').' \{([^}]*)\}/', $css, $m))->toBe(1, "rule {$selector} not found");

        return $m[1];
    };

    expect($rule('.sf-skip'))->toContain('width: 1px;', 'height: 1px;', 'overflow: hidden;', 'white-space: nowrap;')
        ->and($rule('.sf-skip:focus'))->toContain('max-width: calc(100% - 2rem);', 'white-space: normal;', 'overflow: visible;')
        ->and($rule('.sf-topbar'))->toContain('min-height: var(--sf-topbar-h);')->not->toMatch('/(?<!-)height:/')
        ->and($rule('.sf-topbar__inner'))->toContain('flex-wrap: wrap;')
        ->and($rule('.sf-topbar__items'))->toContain('flex-wrap: wrap;')
        ->and($rule(".sf-topbar__item,\n.sf-topbar__link"))->toContain('min-width: 0;')->not->toContain('nowrap');
});

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
        ->toContain('Mã số doanh nghiệp: 0100000001 do Sở KH&amp;ĐT Hà Nội cấp ngày 05/03/2010')
        ->not->toContain('GPĐKKD')
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

it('writes the legal line with the enterprise code and its issuer when the issue date is unknown', function (): void {
    $siteId = sfcSite();
    sfcSetting($siteId, 'footer', sfcFooter());

    $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('Mã số doanh nghiệp: 0100000001 do Sở KH&amp;ĐT Hà Nội cấp')
        ->not->toContain('cấp ngày')
        ->not->toContain('GPĐKKD');
});

it('adds only a directions link to the footer when the factories carry branch coordinates', function (): void {
    $branchFields = [
        ['key' => 'nha-may-1', 'full_name' => ['vi' => 'Nhà máy 1 – Xuân Sơn'], 'locality' => ['vi' => 'Trung Giã'], 'map_query' => 'Xưởng Mẫu, KCN Thử',
            'lat' => 21.2983367, 'lng' => 105.8672342, 'approximate' => false, 'image_media_id' => 7],
        ['key' => 'nha-may-2', 'lat' => 21.0655337, 'lng' => 105.556209, 'approximate' => true],
    ];
    $old = sfcFooter();
    $withBranches = sfcFooter(['factories' => array_map(fn (array $factory, array $extra): array => $factory + $extra, $old['factories'], $branchFields)]);

    expect(StorefrontChrome::footer($old, 'vi', 'vi')['factories'])->toBe([
        ['title' => 'Nhà máy 1', 'area' => 12000, 'name' => 'Xưởng cơ khí', 'address' => 'Lô A1, KCN Thử', 'map_url' => 'https://maps.google.com/?q=1', 'directions_url' => null],
        ['title' => 'Nhà máy 2', 'area' => null, 'name' => null, 'address' => 'Lô B2, KCN Thử', 'map_url' => null, 'directions_url' => null],
    ])
        ->and(StorefrontChrome::footer($withBranches, 'vi', 'vi')['factories'])->toBe([
            ['title' => 'Nhà máy 1', 'area' => 12000, 'name' => 'Xưởng cơ khí', 'address' => 'Lô A1, KCN Thử', 'map_url' => 'https://maps.google.com/?q=1', 'directions_url' => 'https://www.google.com/maps/dir/?api=1&destination=21.2983367%2C105.8672342'],
            ['title' => 'Nhà máy 2', 'area' => null, 'name' => null, 'address' => 'Lô B2, KCN Thử', 'map_url' => null, 'directions_url' => 'https://www.google.com/maps/dir/?api=1&destination=21.0655337%2C105.556209'],
        ]);

    $siteId = sfcSite();
    $footerHtml = function (array $value) use ($siteId): string {
        sfcSetting($siteId, 'footer', $value);
        $html = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
        expect(preg_match('~<footer class="sf-footer".*?</footer>~s', $html, $m))->toBe(1);

        return $m[0];
    };

    // A factory with its own map link keeps it (and no directions link); the other gets "Chỉ đường".
    $plain = $footerHtml($old);
    $rich = $footerHtml($withBranches);
    expect($plain)->not->toContain('Chỉ đường')
        ->and($rich)->toContain('>Chỉ đường</span>')
        ->toContain('href="https://www.google.com/maps/dir/?api=1&amp;destination=21.0655337%2C105.556209"')
        ->not->toContain('destination=21.2983367')
        ->and(substr_count($rich, 'sf-factory__map'))->toBe(substr_count($plain, 'sf-factory__map') + 1);
});

it('turns the footer\'s factory cards into a one-card chooser only when there are two or more', function (): void {
    // Print each Vite entry as a module script so the footer's own script is visible.
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
        }
    });
    $siteId = sfcSite();
    $footerHtml = function (array $value) use ($siteId): string {
        sfcSetting($siteId, 'footer', $value);

        return $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
    };

    $two = $footerHtml(sfcFooter());
    expect($two)->toContain('<ul class="sf-footer__factories" data-sf-rot')
        ->toContain('data-sf-rot-nav hidden')
        ->and(substr_count($two, 'data-sf-rot-go'))->toBe(2)
        ->and($two)->toContain('aria-label="Nhà máy trước"')->toContain('aria-label="Nhà máy tiếp theo"')
        ->toContain('sf-footer-rot')
        // the first dot is the current one before any script runs
        ->and(preg_match('~data-sf-rot-go aria-label="Nhà máy 1" aria-current="true"~', $two))->toBe(1);

    $one = $footerHtml(sfcFooter(['factories' => [['title' => ['vi' => 'Nhà máy 1'], 'address' => 'Lô A1']]]));
    expect($one)->toContain('sf-footer__factories')->not->toContain('data-sf-rot-nav')->not->toContain('sf-footer-rot');
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
        ->toContain('Enterprise code: 0100000001, issued by Sở KH&amp;ĐT Hà Nội')
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
