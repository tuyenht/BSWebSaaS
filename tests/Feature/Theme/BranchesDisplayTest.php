<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * The site's branches on the storefront: a form block with `map_source: branches` draws a chooser, the
 * chosen branch's card, four buttons and the map in the form's card; a `branches` block draws the same
 * branches as photo cards. Every map address is built on the server from fixed templates, each value
 * percent-encoded; the data comes from the footer setting, so no page copies it.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    config(['cms.map_hosts' => ['www.google.com']]);
});

const BRANCH_QUERY = 'Cơ khí "An & Vinh" <b>?q=1#x';

/** The footer setting listing three branches: two that can be mapped (the first with a picture) and one that cannot. */
function branchesFooter(?int $photoId): array
{
    return ['factories' => [
        [
            'key' => 'nha-may-1', 'title' => ['vi' => 'NHÀ MÁY 1'], 'full_name' => ['vi' => 'Nhà máy 1 – Xuân Sơn'], 'locality' => ['vi' => 'Trung Giã'],
            'area_m2' => 2000, 'name' => ['vi' => 'Lắp ráp, hàn, dập'], 'address' => ['vi' => 'Đường 418, thôn Xuân Sơn'],
            'map_query' => BRANCH_QUERY, 'lat' => 21.2983367, 'lng' => 105.8672342, 'approximate' => false, 'image_media_id' => $photoId,
        ],
        [
            'key' => 'nha-may-2', 'title' => ['vi' => 'NHÀ MÁY 2'], 'full_name' => ['vi' => 'Nhà máy 2 – Hương Ninh'], 'locality' => ['vi' => 'Trung Giã'],
            'area_m2' => 500, 'name' => ['vi' => 'Lắp ráp'], 'address' => ['vi' => 'Đường QL3, thôn Hương Ninh'],
            'lat' => 21.2936586, 'lng' => 105.8649934, 'approximate' => true,
        ],
        ['key' => 'kho', 'title' => ['vi' => 'KHO'], 'address' => ['vi' => 'Chưa có vị trí']],
    ]];
}

/** Provisions a site with those branches (and a hotline when $profile), then draws $blocks on /khoi. */
function branchesPage(array $blocks, bool $profile = true, ?array $footer = null): string
{
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($blocks, $profile, $footer): void {
        $photo = StorefrontCards::media('nha-may-1', 800, 600);
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => $footer ?? branchesFooter($photo->id)]);
        if ($profile) {
            SiteProfile::query()->update(['hotline' => '0915 668 383']);
        }
        StorefrontCards::page('/khoi', 'Các khối', $blocks);
    });

    return test()->get('http://mau.platform.test/khoi')->assertOk()->getContent();
}

/** Vite entries drawn as plain script tags, so a page shows which scripts it asked for. */
function branchesFakeVite(): void
{
    test()->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
        }
    });
}

function branchFormBlock(array $props = []): array
{
    return ['type' => 'form', 'props' => ['form_key' => 'contact', 'map_source' => 'branches'] + $props];
}

it('draws the chooser, the first branch\'s card, four buttons and the approximate line in the form\'s card', function (): void {
    $html = branchesPage([branchFormBlock()]);

    expect($html)->toContain('<section class="lead-form lead-form--map" id="lf-contact">')
        ->and($html)->toContain('<section class="sf-bmap" id="lf-contact-map" data-sf-bmap')
        ->and($html)->toContain('data-sf-bmap-tabs data-label="Chọn nhà máy" hidden>')
        // two branches can be mapped; the third has no place to show
        ->and(substr_count($html, '<button type="button" class="sf-bmap__tab"'))->toBe(2)
        ->and($html)->toContain('<span class="sf-bmap__tab-name">Nhà máy 1</span>')
        ->and($html)->toContain('<span class="sf-bmap__tab-sub">Trung Giã</span>')
        ->and($html)->toContain('<h3 class="sf-bmap__name" data-sf-bmap-name>Nhà máy 1 – Xuân Sơn</h3>')
        ->and($html)->toContain('2.000 m² · Lắp ráp, hàn, dập')
        ->and($html)->toContain('Đường 418, thôn Xuân Sơn')
        ->and($html)->toContain('Chỉ đường')->toContain('Gọi điện')->toContain('Mở Maps')->toContain('data-sf-bmap-copy hidden>Sao chép</button>')
        // the first branch is exact, so its approximate line is drawn hidden; the second one's data says so
        ->and($html)->toContain('<p class="sf-bmap__approx" data-sf-bmap-approx hidden>Vị trí trên bản đồ là gần đúng')
        ->and($html)->toContain('data-approx="1"')
        ->and($html)->toContain('<iframe class="sf-bmap__map" data-sf-bmap-frame')
        ->and($html)->toMatch('~<iframe class="sf-bmap__map" data-sf-bmap-frame[^>]*loading="lazy"~')
        ->and($html)->not->toContain('lead-form__map');
});

it('builds every map address from the fixed templates with each value percent-encoded', function (): void {
    $html = branchesPage([branchFormBlock()]);
    $q = rawurlencode(BRANCH_QUERY);

    expect($html)->toContain('src="https://www.google.com/maps?q='.$q.'&amp;hl=vi&amp;z=16&amp;output=embed"')
        ->and($html)->toContain('href="https://www.google.com/maps/dir/?api=1&amp;destination=21.2983367%2C105.8672342"')
        ->and($html)->toContain('href="https://www.google.com/maps/search/?api=1&amp;query='.$q.'"')
        // no search text: the coordinates are searched, and the approximate branch zooms out one step
        ->and($html)->toContain('data-embed="https://www.google.com/maps?q=21.2936586%2C105.8649934&amp;hl=vi&amp;z=15&amp;output=embed"')
        ->and($html)->toContain('data-open="https://www.google.com/maps/search/?api=1&amp;query=21.2936586%2C105.8649934"')
        // the text never reaches the page raw
        ->and($html)->not->toContain('<b>?q=1#x')
        ->and($html)->not->toContain('"An & Vinh"');
});

it('opens map links safely and calls the site hotline', function (): void {
    $html = branchesPage([branchFormBlock()]);

    expect($html)->toContain('target="_blank" rel="noopener"')
        ->and($html)->toMatch('~<a class="sf-bmap__btn sf-bmap__btn--dark" href="tel:0915668383">Gọi điện</a>~');
});

it('leaves the call button out when the site has no phone number', function (): void {
    $html = branchesPage([branchFormBlock()], profile: false);

    expect($html)->toContain('Chỉ đường')->toContain('Mở Maps')->not->toContain('sf-bmap__btn--dark');
});

it('loads the chooser script only on a page whose form draws the branches', function (): void {
    branchesFakeVite();

    expect(branchesPage([branchFormBlock()]))->toContain('src="/resources/js/sf-branch-map.js"');

    StorefrontCards::reset();
    expect(branchesPage([['type' => 'form', 'props' => ['form_key' => 'contact', 'map_source' => 'embed']]]))->not->toContain('sf-branch-map');
});

it('keeps a form without map_source exactly as it was, and loads no chooser script', function (): void {
    $html = branchesPage([['type' => 'form', 'props' => ['form_key' => 'contact', 'map_embed' => 'https://www.google.com/maps/embed?pb=2']]]);

    expect($html)->toContain('<iframe class="lead-form__map" src="https://www.google.com/maps/embed?pb=2"')
        ->and($html)->not->toContain('sf-bmap')
        ->and($html)->not->toContain('sf-branch-map');

    StorefrontCards::reset();
    $plain = branchesPage([['type' => 'form', 'props' => ['form_key' => 'contact']]]);
    expect($plain)->toContain('<section class="lead-form" id="lf-contact">')->and($plain)->not->toContain('sf-bmap');
});

it('loads no chooser script when the form falls back to its map address', function (): void {
    branchesFakeVite();
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        StorefrontCards::page('/khoi', 'Các khối', [branchFormBlock(['map_embed' => 'https://www.google.com/maps/embed?pb=3'])]);
    });
    $html = $this->get('http://mau.platform.test/khoi')->assertOk()->getContent();

    expect($html)->toContain('lead-form__map')->and($html)->not->toContain('sf-branch-map');
});

it('loads the chooser script for a form drawn in a page slot', function (): void {
    branchesFakeVite();
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => branchesFooter(null)]);
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'layout_blocks', 'value' => ['after_list' => ['vi' => ['blocks' => [branchFormBlock()]]]]]);
    });
    $html = $this->get('http://mau.platform.test/tin-tuc')->assertOk()->getContent();

    expect($html)->toContain('data-sf-bmap')->and($html)->toContain('src="/resources/js/sf-branch-map.js"');
});

it('draws a single branch with no chooser, its copy button and its links', function (): void {
    $html = branchesPage([branchFormBlock()], true, ['factories' => [
        ['key' => 'tru-so', 'title' => ['vi' => 'TRỤ SỞ'], 'full_name' => ['vi' => 'Trụ sở chính'], 'address' => ['vi' => 'Số 1, phố Mẫu'], 'map_query' => 'Cơ khí An Vinh'],
    ]]);

    expect($html)->toContain('data-sf-bmap')
        ->and($html)->not->toContain('data-sf-bmap-tabs')
        ->and($html)->not->toContain('sf-bmap__hint')
        ->and($html)->toContain('<p class="sf-bmap__addr" data-sf-bmap-addr >Số 1, phố Mẫu</p>')
        ->and($html)->toContain('data-sf-bmap-copy hidden>Sao chép</button>')
        ->and($html)->toContain('Chỉ đường')->toContain('Gọi điện')->toContain('Mở Maps')
        // no coordinates: directions go to the search text
        ->and($html)->toContain('href="https://www.google.com/maps/dir/?api=1&amp;destination='.rawurlencode('Cơ khí An Vinh').'"');
});

it('draws no photo for a branch whose picture belongs to another site', function (): void {
    $foreign = 0;
    $other = StorefrontCards::site('Xưởng Khác', 'khac.platform.test');
    app(Tenancy::class)->enter($other, function () use (&$foreign): void {
        $foreign = StorefrontCards::media('anh-nguoi-khac', 800, 600)->id;
    });

    $html = branchesPage([['type' => 'branches', 'props' => ['heading' => 'Các nhà máy', 'layout' => 'auto']]], true, ['factories' => [
        ['key' => 'nha-may-1', 'title' => ['vi' => 'NHÀ MÁY 1'], 'full_name' => ['vi' => 'Nhà máy 1'], 'map_query' => 'Cơ khí An Vinh', 'image_media_id' => $foreign],
    ]]);

    expect($html)->toContain('<h3 class="sf-branch__name">Nhà máy 1</h3>')
        ->and($html)->not->toContain('sf-branch__img')
        ->and($html)->not->toContain('anh-nguoi-khac');
});

it('falls back to the map address when the site has no branch to show', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        StorefrontCards::page('/khoi', 'Các khối', [branchFormBlock(['map_embed' => 'https://www.google.com/maps/embed?pb=3'])]);
    });
    $html = $this->get('http://mau.platform.test/khoi')->assertOk()->getContent();

    expect($html)->toContain('<iframe class="lead-form__map" src="https://www.google.com/maps/embed?pb=3"')->and($html)->not->toContain('sf-bmap');
});

it('draws the branches block as cards with their photos', function (): void {
    $html = branchesPage([['type' => 'branches', 'props' => ['heading' => 'Ba nhà máy', 'description' => 'Tại Hà Nội.', 'layout' => 'auto']]]);

    expect($html)->toContain('<h2 class="sf-section-head__title">Ba nhà máy</h2>')
        ->and($html)->toContain('<p class="sf-section-head__text">Tại Hà Nội.</p>')
        ->and(substr_count($html, '<article class="sf-branch">'))->toBe(3)
        ->and($html)->toContain('<h3 class="sf-branch__name">Nhà máy 1 – Xuân Sơn</h3>')
        ->and($html)->toContain('<span class="sf-branch__chip">2.000 m²</span>')
        ->and($html)->toContain('<p class="sf-branch__role">Lắp ráp, hàn, dập</p>')
        ->and($html)->toContain('<p class="sf-branch__addr">Đường 418, thôn Xuân Sơn</p>')
        ->and($html)->toMatch('~<img class="sf-branch__img" src="https://files\.test/[^"]*nha-may-1-[^"]*"[^>]*alt="Nhà máy 1 – Xuân Sơn" width="800" height="600" loading="lazy"~')
        // only the first branch has a picture; the others draw their cards without that part
        ->and(substr_count($html, 'sf-branch__img'))->toBe(1)
        ->and($html)->toContain('<p class="sf-branch__area">500 m²</p>');
});

it('draws nothing for a branches block when the site has no branch', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, fn () => StorefrontCards::page('/khoi', 'Các khối', [['type' => 'branches', 'props' => ['heading' => 'Ba nhà máy']]]));

    expect($this->get('http://mau.platform.test/khoi')->assertOk()->getContent())->not->toContain('Ba nhà máy');
});

it('keeps the contact page at the same queries and the branch cards at one more', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $photo = StorefrontCards::media('nha-may-1', 800, 600);
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => ['factories' => [
            ['key' => 'nha-may-1', 'title' => ['vi' => 'NHÀ MÁY 1'], 'map_query' => 'Cơ khí An Vinh', 'image_media_id' => $photo->id],
            ['key' => 'nha-may-2', 'title' => ['vi' => 'NHÀ MÁY 2'], 'lat' => 21.29, 'lng' => 105.86],
        ]]]);
        StorefrontCards::page('/lien-he-cu', 'Liên hệ', [['type' => 'form', 'props' => ['form_key' => 'contact', 'map_embed' => 'https://www.google.com/maps/embed?pb=2']]]);
        StorefrontCards::page('/lien-he-moi', 'Liên hệ', [branchFormBlock()]);
        StorefrontCards::page('/khong-co', 'Nhà xưởng', [['type' => 'cta', 'props' => ['heading' => 'A']]]);
        StorefrontCards::page('/the', 'Nhà xưởng', [['type' => 'branches', 'props' => ['layout' => 'auto']], ['type' => 'cta', 'props' => ['heading' => 'A']]]);
    });
    $queries = function (string $path) {
        $this->get('http://mau.platform.test'.$path)->assertOk();   // warm: the reads that do not depend on the page

        return countQueries(fn () => $this->get('http://mau.platform.test'.$path)->assertOk());
    };

    expect($queries('/lien-he-moi'))->toBe($queries('/lien-he-cu'))
        ->and($queries('/the'))->toBeLessThanOrEqual($queries('/khong-co') + 1);
});
