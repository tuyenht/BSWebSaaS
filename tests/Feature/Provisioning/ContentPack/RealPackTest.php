<?php

use App\Modules\Admin\Support\Settings\FloatingContactInput;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\StorefrontChrome;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * The real pack under database/content-packs passes every check the loader makes, publishes every
 * page, post and product (the owner publishes the content as it stands; the site's staff edit it
 * later) except the job offers, which wait as drafts for the company to confirm what they promise,
 * and the two retired capability posts, whose addresses redirect to the capabilities that replace
 * them. It holds no testimonial, and plans with no conflict on a freshly provisioned site; after
 * loading, every internal link it holds resolves to a live address.
 */

const RETIRED = ['post:thiet-ke-jig-do-ga', 'post:san-xuat-chi-tiet-may-oem'];

/** The about section: the company page, then its subpages in menu order. */
const ABOUT_PAGES = [
    'page:/gioi-thieu' => 'Giới thiệu',
    'page:/nha-xuong-may-moc' => 'Nhà xưởng & máy móc',
    'page:/chung-chi-thanh-tuu' => 'Chứng chỉ & thành tựu',
    'page:/ho-so-nang-luc' => 'Hồ sơ năng lực & tài liệu',
];

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

afterEach(fn () => ContentPacks::cleanup());

/** @return list<string> every internal path a value links to (props ending in url/href, links inside html), without its #fragment */
function packLinks(mixed $value, ?string $key = null): array
{
    if (is_string($value) && str_contains($value, '<a ')) {
        preg_match_all('/href="(\/[^"#]*)/', $value, $m);

        return $m[1];
    }
    if (is_string($value)) {
        return $key !== null && preg_match('/(url|href)$/', $key) === 1 && str_starts_with($value, '/') ? [explode('#', $value)[0]] : [];
    }
    $links = [];
    foreach (is_array($value) ? $value : [] as $child => $item) {
        array_push($links, ...packLinks($item, is_string($child) ? $child : $key));
    }

    return $links;
}

it('validates the real pack: schema, bodies, slots, content published but job offers and retired posts, no testimonials', function (): void {
    $pack = (new PackReader)->read('an-vinh');

    expect(app(PackValidator::class)->validate($pack, 'an-vinh'))->toBe([]);

    $content = 0;
    $jobs = [];
    foreach ($pack->items as $item) {
        if (preg_match('/\A(page|post|product):/', $item->key) === 1) {
            $content++;
            if (in_array('tax:post_cat/tuyen-dung', $item->data['categories'] ?? [], true)) {
                $jobs[] = $item->key;
                expect($item->data['verified'])->toBeFalse($item->key)
                    ->and($item->data['status'])->toBe('draft', $item->key)
                    ->and($item->data['claims'])->not->toBeEmpty();
            } else {
                expect($item->data['verified'])->toBeTrue($item->key)
                    ->and($item->data['status'])->toBe(in_array($item->key, RETIRED, true) ? 'draft' : 'published', $item->key)
                    ->and($item->data)->not->toHaveKey('claims');
            }
        }
        expect(json_encode($item->data))->not->toContain('testimonial');
    }
    expect($content)->toBe(46)->and($jobs)->toHaveCount(3);
    foreach ($pack->slots as $slot) {
        if (isset($slot['file'])) {
            expect(PackValidator::mime($pack->dir.'/'.$slot['file'], PackValidator::isDocument($slot)))->not->toBeNull();
        }
    }
});

it('gives every project, news card and product its own image and the quality block its ISO certificate', function (): void {
    $pack = (new PackReader)->read('an-vinh');
    $items = collect($pack->items)->keyBy(fn ($item) => $item->key);

    $cards = $items->filter(fn ($item) => str_starts_with($item->key, 'post:')
        && array_intersect($item->data['categories'], ['tax:post_cat/du-an', 'tax:post_cat/tin-tuc']) !== []);
    expect($cards)->toHaveCount(17);
    foreach ($cards as $item) {
        expect($item->data['image']['media'] ?? null)->toBeString($item->key);
    }
    expect($cards->map(fn ($item) => $item->data['image']['media'])->duplicates()->all())->toBe([]);

    $productImages = $items->filter(fn ($item) => str_starts_with($item->key, 'product:'))
        ->map(fn ($item) => $item->data['images'][0]['media'] ?? null);
    expect($productImages->filter()->count())->toBe($productImages->count())
        ->and($productImages->duplicates()->all())->toBe([]);

    $quality = collect($items['page:/gioi-thieu']->data['body']['vi'])
        ->firstWhere('props.heading', 'Cam kết chất lượng theo tiêu chuẩn quốc tế');
    expect($quality['props']['image_url'])->toBe(['media' => 'iso-9001-certificate']);
    $certificate = collect($items['page:/chung-chi-thanh-tuu']->data['body']['vi'])->firstWhere('type', 'about');
    expect($certificate['props']['image_url'])->toBe(['media' => 'iso-9001-certificate']);
});

it('never moves a tone mark off its syllable when the text is spelt the new way (hoàn, thoát stay whole)', function (): void {
    foreach ((new PackReader)->read('an-vinh')->items as $item) {
        expect(json_encode($item->data, JSON_UNESCAPED_UNICODE))->not->toMatch('/[òóỏõọ][ae]\p{L}|[ùúủũụ]y\p{L}/iu', $item->key);
    }
});

it('makes no absolute advertising claim, and credits every video as an illustration under its licence', function (): void {
    $pack = (new PackReader)->read('an-vinh');
    $settings = json_decode((string) file_get_contents($pack->dir.'/settings.json'), true);
    $texts = [json_encode($settings, JSON_UNESCAPED_UNICODE)];
    $videos = [];
    foreach ($pack->items as $item) {
        $texts[] = json_encode($item->data, JSON_UNESCAPED_UNICODE);
        foreach ($item->data['body']['vi'] ?? [] as $block) {
            if ($block['type'] === 'video') {
                // the source and licence may sit in the heading or in the caption line under the player
                $videos[$block['props']['url']] = $block['props']['heading'].': '.($block['props']['caption'] ?? '');
            }
        }
    }

    foreach ($texts as $text) {
        expect($text)->not->toMatch('/dẫn đầu|tuyệt đối|duy nhất|100% sản phẩm|(tốt|tối ưu|khắt khe|tiết kiệm chi phí|rõ nét|số) (nhất|một)\b/iu');
    }
    // "hàng đầu" only in the company's stated goal ("trở thành … hàng đầu"), never as a claim about today
    preg_match_all('/.{0,90}hàng đầu/iu', implode("\n", $texts), $leading);
    foreach ($leading[0] as $phrase) {
        expect($phrase)->toMatch('/trở thành công ty/iu');
    }
    expect($videos)->toHaveCount(3);
    foreach ($videos as $heading) {
        expect($heading)->toMatch('/^Video (minh họa|& hình ảnh): /u')->and(mb_strtolower($heading))->toContain('nguồn kênh')->toContain('giấy phép cc by');
    }
});

it('gives every post body text, an excerpt and a search description', function (): void {
    $posts = collect((new PackReader)->read('an-vinh')->items)->filter(fn ($item) => str_starts_with($item->key, 'post:'));

    foreach ($posts as $item) {
        $text = collect($item->data['body']['vi'])->map(fn (array $block): string => match ($block['type']) {
            'richtext' => implode('', $block['props']['paragraphs']),
            'richtext_html' => trim(strip_tags($block['props']['html'])),
            default => '',
        });
        expect($text->filter()->count())->toBeGreaterThan(0, $item->key)
            ->and($item->data['excerpt']['vi'] ?? '')->not->toBe('', $item->key)
            ->and($item->data['seo_description']['vi'] ?? '')->not->toBe('', $item->key);
    }
});

it('lists the partners on their page with a call to contact, and links the privacy policy from the footer', function (): void {
    $items = collect((new PackReader)->read('an-vinh')->items)->keyBy(fn ($item) => $item->key);
    $partners = ['VinFast', 'Festo', 'Hutchinson', 'Weldcom', 'SMC', 'DGP', 'Yamaguchi', 'Mitsuboshi', 'Palfinger', 'Vinmanutech'];
    $withLogo = ['VinFast', 'Festo', 'Hutchinson', 'Weldcom', 'SMC', 'Palfinger', 'Vinmanutech'];

    $blocks = collect($items['page:/doi-tac']->data['body']['vi']);
    $home = collect($items['page:/']->data['body']['vi'])->firstWhere('type', 'partners');
    foreach ([$blocks[1]['props'], $home['props']] as $wall) {
        $logos = collect($wall['logos']);
        expect($logos->pluck('name')->all())->toBe($partners)
            ->and($logos->filter(fn (array $logo): bool => isset($logo['image_url']))->pluck('name')->all())->toBe($withLogo)
            ->and($logos->pluck('image_url.media')->filter()->every(fn (string $slot): bool => str_starts_with($slot, 'logo-')))->toBeTrue();
    }
    expect($blocks->pluck('type')->all())->toBe(['page_header', 'partners', 'features', 'cta'])
        ->and($blocks[1]['props']['layout'])->toBe('grid')
        ->and($blocks[3]['props']['button_url'])->toBe('/lien-he')
        ->and($blocks[3]['props']['button2_url'])->toBe('/lien-he');

    $about = $items['menu:footer']->data['items'][0];
    expect($about['label']['vi'])->toBe('GIỚI THIỆU')
        ->and(collect($about['children'])->firstWhere('target', 'page:/chinh-sach-bao-mat')['label']['vi'] ?? null)->toBe('Chính sách bảo mật');
});

it('follows the storefront design: capability posts let the layout draw their band, projects lead with their sector', function (): void {
    $pack = (new PackReader)->read('an-vinh');
    $items = collect($pack->items)->keyBy(fn ($item) => $item->key);

    foreach (['thiet-ke-lap-trinh', 'gia-cong-cat-day-xung-dien', 'do-kiem-qa-qc', 'gia-cong-phay-tien-cnc', 'han-lap-rap-co-khi'] as $slug) {
        expect($items['post:'.$slug]->data['body']['vi'][0]['type'])->not->toBe('page_header', $slug);
    }
    $projects = $items->filter(fn ($item) => str_starts_with($item->key, 'post:') && in_array('tax:post_cat/du-an', $item->data['categories'], true));
    expect($projects)->toHaveCount(9);
    foreach ($projects as $item) {
        expect($item->data['categories'][0])->toStartWith('tax:post_cat/du-an/', $item->key);
    }

    $home = collect($items['page:/']->data['body']['vi']);
    expect($home->firstWhere('type', 'cta')['props'])->toMatchArray([
        'button2_url_do' => ['k' => 'tel', 'phone' => '0915668383'],
        'extra_style' => ['pattern-cross'],
    ])->and(collect($home->firstWhere('type', 'about')['props']['links'])->pluck('url')->all())
        ->toBe(['/gioi-thieu', '/ho-so-nang-luc', '/nha-xuong-may-moc#video', '/nang-luc']);
    expect(collect($items['page:/gioi-thieu']->data['body']['vi'])->firstWhere('type', 'about')['props']['variant'])->toBe('letter');

    // The services strip is the board's four cards (glyph, title, text, "Xem chi tiết →"), not the capability posts.
    $services = $home->firstWhere('props.heading', 'Dịch vụ của chúng tôi')['props'];
    expect($home->where('type', 'entry_grid')->pluck('props.taxonomy_slug')->all())->not->toContain('nang-luc')
        ->and($services)->toMatchArray(['variant' => 'icon-cards', 'bg' => 'tint', 'layout' => 'auto'])
        ->and(collect($services['items'])->map(fn (array $item): array => [$item['title'], $item['icon'], $item['url']])->all())->toBe([
            ['Thiết kế Jig & Đồ gá', 'lock', '/thiet-ke-lap-trinh'],
            ['Gia công Phay & Tiện CNC', 'adjust', '/gia-cong-phay-tien-cnc'],
            ['Hàn & Lắp ráp cơ khí', 'wrench', '/han-lap-rap-co-khi'],
            ['Sản xuất chi tiết máy & OEM', 'machine', '/nang-luc'],
        ]);

    $settings = collect(json_decode((string) file_get_contents($pack->dir.'/settings.json'), true)['items'])->keyBy('key');
    expect($settings['setting:storefront.footer']['value']['copyright']['start_year'])->toBe(2019)
        ->and($settings['setting:theme.tokens']['value']['font'])->toContain('Inter-Fallback');
});

it('publishes a privacy policy naming the company, what the form collects and how to exercise rights', function (): void {
    $page = collect((new PackReader)->read('an-vinh')->items)->firstWhere('key', 'page:/chinh-sach-bao-mat');
    $blocks = $page->data['body']['vi'];
    $text = implode("\n", collect($blocks)->where('type', 'richtext')
        ->flatMap(fn (array $block): array => [$block['props']['heading'], ...$block['props']['paragraphs']])->all());

    expect($page->data['status'])->toBe('published')
        ->and(collect($blocks)->where('type', 'richtext')->every(fn (array $block): bool => $block['props']['paragraphs'] !== []))->toBeTrue()
        ->and($text)->toContain('CÔNG TY TNHH THƯƠNG MẠI VÀ DỊCH VỤ THIẾT KẾ GIA CÔNG CƠ KHÍ AN VINH')
        ->toContain('0108649706')
        ->toContain('Thôn Xuân Sơn, Xã Trung Giã, TP Hà Nội')
        ->toContain('quybv@ckanvinh.vn')
        ->toContain('0915 66 83 83')
        ->toContain('họ tên')
        ->toContain('địa chỉ IP')
        ->toContain('Google Maps')
        // The band over the text carries the effective date; the body repeats none, so the page never gives two.
        ->not->toContain('Ngày hiệu lực');
});

it('plans the real pack on a fresh site with no conflict, and every internal link resolves after the load', function (): void {
    $folder = ContentPacks::use('an-vinh', database_path('content-packs'));
    ContentPacks::edit($folder, 'pack.json', fn (array $pack): array => ['hosts' => [ContentPacks::HOST]] + $pack);
    $siteId = StorefrontCards::site('Demo', ContentPacks::HOST);

    [$exit, $output] = ContentPacks::run('an-vinh');
    expect($exit)->toBe(0, $output)->and($output)->toContain('conflict 0');

    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output);

    $pack = (new PackReader)->read('an-vinh');
    $links = array_values(array_unique(packLinks(array_map(fn ($item) => $item->data, $pack->items))));
    $live = ContentPacks::in($siteId, fn () => SiteRoute::query()->where('locale', 'vi')->whereIn('path', $links)->pluck('path')->all());

    expect(array_values(array_diff($links, $live)))->toBe([]);

    $this->get('http://'.ContentPacks::HOST.'/thiet-ke-jig-do-ga')->assertStatus(301)->assertRedirect('/thiet-ke-lap-trinh');
    $this->get('http://'.ContentPacks::HOST.'/san-xuat-chi-tiet-may-oem')->assertStatus(301)->assertRedirect('/gia-cong-phay-tien-cnc');
    foreach (['/', '/gioi-thieu', '/doi-tac', '/nha-xuong-may-moc', '/chung-chi-thanh-tuu', '/cam-on', '/nang-luc', '/thiet-ke-lap-trinh', '/gia-cong-phay-tien-cnc',
        '/gia-cong-cat-day-xung-dien', '/han-lap-rap-co-khi', '/do-kiem-qa-qc', '/du-an', '/tin-tuc', '/san-pham', '/lien-he', '/ho-so-nang-luc'] as $path) {
        $this->get('http://'.ContentPacks::HOST.$path)->assertOk();
    }
    foreach (['/ky-thuat-vien-van-hanh-may-cnc', '/ky-su-thiet-ke-co-khi-do-ga', '/nhan-vien-kiem-tra-chat-luong-qc'] as $path) {
        $this->get('http://'.ContentPacks::HOST.$path)->assertNotFound();
    }
    $home = $this->get('http://'.ContentPacks::HOST.'/')->getContent();
    foreach (['/thiet-ke-lap-trinh' => 'Thiết kế Jig &amp; Đồ gá', '/gia-cong-phay-tien-cnc' => 'Gia công Phay &amp; Tiện CNC', '/han-lap-rap-co-khi' => 'Hàn &amp; Lắp ráp cơ khí', '/nang-luc' => 'Sản xuất chi tiết máy &amp; OEM'] as $href => $title) {
        expect($home)->toContain('<a class="sf-card__link" href="'.$href.'">'.$title.'</a>');
    }
    expect(substr_count($home, 'class="sf-card sf-card--icon"'))->toBe(4);
    expect($this->get('http://'.ContentPacks::HOST.'/gioi-thieu')->getContent())->toContain('Nhà xưởng &amp; máy móc')
        ->and($this->get('http://'.ContentPacks::HOST.'/gia-cong-cat-day-xung-dien')->getContent())->toContain('nguồn kênh Kurt Bleyweert')->toContain('4IhkMgHkMEA');
});

it('loads the flourish heading decoration, round floating buttons of email, Zalo and call with no phone bar, the real-user speed beacon, and the post side column and product band', function (): void {
    $settings = collect(json_decode((string) file_get_contents((new PackReader)->read('an-vinh')->dir.'/settings.json'), true)['items'])->keyBy('key');
    expect($settings['setting:theme.heading_decor']['value'])->toBe(['style' => 'flourish'])
        ->and(collect($settings['setting:storefront.floating_contact']['value']['channels'])->where('on', true)->pluck('key')->all())->toBe(['email', 'zalo', 'phone'])
        ->and($settings['setting:storefront.floating_contact']['value'])->not->toHaveKey('phone_bar')
        ->and($settings['setting:storefront.web_analytics']['value']['cloudflare_token'])->toMatch('/\A[0-9a-f]{32}\z/')
        ->and($settings['setting:storefront.layout_blocks']['managed_keys'])->toBe(['post_aside', 'after_product', 'product_before_action', 'product_after_action'])
        ->and(collect($settings['setting:storefront.layout_blocks']['value']['post_aside']['vi']['blocks'])->pluck('type')->all())->toBe(['consult', 'recent_posts', 'category_list'])
        ->and(collect($settings['setting:storefront.layout_blocks']['value']['after_product']['vi']['blocks'])->pluck('type')->all())->toBe(['cta']);

    $folder = ContentPacks::use('an-vinh', database_path('content-packs'));
    ContentPacks::edit($folder, 'pack.json', fn (array $pack): array => ['hosts' => [ContentPacks::HOST]] + $pack);
    $siteId = StorefrontCards::site('Demo', ContentPacks::HOST);
    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output);

    $stored = fn (string $group, string $key): array => ContentPacks::in($siteId, fn (): array => SiteSetting::query()->where('group_name', $group)->where('key', $key)->value('value'));
    expect($stored('theme', 'heading_decor')['style'])->toBe('flourish')
        ->and(array_keys($stored('storefront', 'layout_blocks')))->toEqualCanonicalizing(['post_aside', 'after_product', 'product_before_action', 'product_after_action']);

    $home = $this->get('http://'.ContentPacks::HOST.'/')->assertOk()->getContent();
    expect($home)->toContain('<nav class="sf-fc sf-fc--right"')
        ->toContain('href="https://zalo.me/0915668383"')->toContain('href="tel:0915668383"')
        ->not->toContain('class="sf-fc-bar')
        ->and(preg_match('/<nav class="sf-fc sf-fc--right".*?<\/nav>/s', $home, $nav))->toBe(1)
        ->and($nav[0])->toContain('href="mailto:quybv@ckanvinh.vn"')
        ->and(preg_match_all('/<a /', $nav[0]))->toBe(3)
        ->and(substr_count($home, 'src="https://static.cloudflareinsights.com/beacon.min.js"'))->toBe(1)
        ->and($home)->toContain($settings['setting:storefront.web_analytics']['value']['cloudflare_token']);

    $post = $this->get('http://'.ContentPacks::HOST.'/gia-cong-phay-tien-cnc')->assertOk()->getContent();
    expect($post)->toContain('Tư vấn kỹ thuật')->toContain('Cần tư vấn hoặc báo giá?')->toContain('Năng lực của An Vinh')->toContain('Hồ sơ năng lực An Vinh');

    $product = $this->get('http://'.ContentPacks::HOST.'/jig-kiem-tap-lo-vf7')->assertOk()->getContent();
    expect($product)->toContain('Cần đồ gá cho dây chuyền của bạn?');

    // The factories are the site's branches: stored with their coordinates, and planned again as unchanged.
    $branches = StorefrontChrome::branches($stored('storefront', 'footer'), 'vi', 'vi');
    expect(array_column($branches, 'key'))->toBe(['nha-may-1', 'nha-may-2', 'nha-may-3'])
        ->and(array_column($branches, 'approximate'))->toBe([false, true, true])
        ->and($branches[1]['lat'])->toBe(21.0655337)->and($branches[1]['lng'])->toBe(105.556209)
        ->and($branches[2]['lat'])->toBe(21.3655865)->and($branches[2]['lng'])->toBe(105.8750945);
    [$exit, $output] = ContentPacks::run('an-vinh');
    expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'setting:storefront.footer'))->toBe('unchanged');
});

it('gives the factories map points (an approximate one where the exact place is unknown) and a photo each, the company\'s own where it has one', function (): void {
    $pack = (new PackReader)->read('an-vinh');
    $footer = collect($pack->items)->firstWhere('key', 'setting:storefront.footer')->data['value'];

    $points = array_map(fn (array $factory): array => [$factory['key'], $factory['lat'] ?? null, $factory['lng'] ?? null, $factory['approximate'] ?? null, $factory['image_media_id']['media'] ?? null], $footer['factories']);
    expect($points)->toBe([
        ['nha-may-1', 21.2983367, 105.8672342, false, 'factory-1'],
        ['nha-may-2', 21.0655337, 105.556209, true, 'plant-3-machining-hall'],
        ['nha-may-3', 21.3655865, 105.8750945, true, 'n-factory-opening'],
    ])
        ->and($footer['factories'][0]['map_query'])->toBe('Cơ khí An Vinh, Xuân Sơn, Trung Giã, Hà Nội')
        ->and(array_column($footer['factories'], 'full_name'))->toBe([['vi' => 'Nhà máy 1 – Xuân Sơn'], ['vi' => 'Nhà máy 2 – Ngũ Sơn'], ['vi' => 'Nhà máy 3 – Kim Tỉnh']]);
    foreach (['plant-2-assembly-floor-1', 'plant-3-machining-hall'] as $slot) {
        expect($pack->slots[$slot]['license'])->toStartWith('Ảnh của Công ty An Vinh');
    }
});

it('builds the about section as one family: a parent page, titles named like the menu, one band style, one closing call, unique search texts', function (): void {
    $items = collect((new PackReader)->read('an-vinh')->items)->keyBy(fn ($item) => $item->key);
    $closing = null;
    $seo = [];

    foreach (ABOUT_PAGES as $key => $title) {
        $page = $items[$key];
        $blocks = collect($page->data['body']['vi']);
        expect($page->data['title']['vi'])->toBe($title, $key)
            ->and($page->data['parent'] ?? null)->toBe($key === 'page:/gioi-thieu' ? null : 'page:/gioi-thieu', $key)
            ->and($blocks->first()['type'])->toBe('page_header', $key)
            ->and($blocks->where('type', 'page_header'))->toHaveCount(1)
            ->and($blocks->first()['props'])->toHaveKeys(['title', 'subtitle', 'image_url'])->not->toHaveKey('overlay')
            ->and($page->data['seo_title']['vi'])->toEndWith(' | Cơ Khí Chính Xác An Vinh')
            ->and(mb_strlen($page->data['seo_title']['vi']))->toBeLessThanOrEqual(60, $key)
            ->and(mb_strlen($page->data['seo_description']['vi']))->toBeGreaterThanOrEqual(140, $key)->toBeLessThanOrEqual(160, $key);
        $closing ??= $blocks->last();
        expect($blocks->last())->toBe($closing, $key)
            ->and($closing['props']['button_url'])->toBe('/lien-he');
        $seo[] = $page->data['seo_title']['vi'];
        $seo[] = $page->data['seo_description']['vi'];
    }
    expect(array_unique($seo))->toHaveCount(count($seo));

    // the ISO summary on the company page and the profile page's main call both download the company
    // profile, labelled with the file's type and size as loaded
    $download = ['label' => ['text' => 'Tải hồ sơ năng lực', 'type_and_size_of' => 'ho-so-nang-luc'], 'do' => ['k' => 'file', 'file' => ['media' => 'ho-so-nang-luc']]];
    $iso = collect($items['page:/gioi-thieu']->data['body']['vi'])->firstWhere('props.anchor_id', 'chung-chi')['props'];
    $profile = collect($items['page:/ho-so-nang-luc']->data['body']['vi'])->firstWhere('props.heading', 'Hồ sơ năng lực An Vinh')['props'];
    expect(['label' => $iso['cta_label'], 'do' => $iso['cta_url_do']])->toBe($download)->and($iso)->not->toHaveKey('cta_url')
        ->and(['label' => $profile['button_label'], 'do' => $profile['button_url_do']])->toBe($download)->and($profile)->not->toHaveKey('button_url');

    $menu = fn (string $key): array => $items[$key]->data['items'];
    $about = collect($menu('menu:primary'))->firstWhere('label.vi', 'Giới thiệu');
    $wanted = [['Giới thiệu công ty', 'page:/gioi-thieu'], ...array_map(null, array_slice(array_values(ABOUT_PAGES), 1), array_slice(array_keys(ABOUT_PAGES), 1))];
    expect($about['target'])->toBe('page:/gioi-thieu')
        ->and(collect($about['children'])->map(fn (array $child): array => [$child['label']['vi'], $child['target']])->all())->toBe($wanted)
        // The footer column follows the board's own wording and order, so it names the same pages without repeating the menu's labels.
        ->and(array_diff(array_column($wanted, 1), array_column($menu('menu:footer')[0]['children'], 'target')))->toBe([]);
});

it('shows each about subpage under Giới thiệu in its breadcrumb and opens the about menu by its own button', function (): void {
    $folder = ContentPacks::use('an-vinh', database_path('content-packs'));
    ContentPacks::edit($folder, 'pack.json', fn (array $pack): array => ['hosts' => [ContentPacks::HOST]] + $pack);
    StorefrontCards::site('Demo', ContentPacks::HOST);
    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output);

    foreach (['/nha-xuong-may-moc' => 'Nhà xưởng &amp; máy móc', '/chung-chi-thanh-tuu' => 'Chứng chỉ &amp; thành tựu', '/ho-so-nang-luc' => 'Hồ sơ năng lực &amp; tài liệu'] as $path => $crumb) {
        $html = $this->get('http://'.ContentPacks::HOST.$path)->assertOk()->getContent();
        expect(preg_match('#<nav class="sf-crumbs[^"]*"[^>]*>.*?</nav>#s', $html, $crumbs))->toBe(1, $path)
            ->and($crumbs[0])->toMatch('#<a class="sf-crumbs__link" href="/">Trang chủ</a>.*<a class="sf-crumbs__link" href="/gioi-thieu">Giới thiệu</a>.*<span class="sf-crumbs__current" aria-current="page">'.preg_quote($crumb, '#').'</span>#s')
            ->and(substr_count($html, '<h1'))->toBe(1, $path);
    }

    $home = $this->get('http://'.ContentPacks::HOST.'/')->assertOk()->getContent();
    expect($home)->toMatch('#<a class="sf-nav__link" href="/gioi-thieu"\s*>Giới thiệu</a><button type="button" class="sf-nav__chev" aria-expanded="false" aria-controls="dsm-1"#')
        ->toContain('href="/chung-chi-thanh-tuu"')
        ->toContain('href="/ho-so-nang-luc"')
        ->not->toContain('/gioi-thieu#chung-chi');
});

it('gives the contact form the board\'s order, a need list with its four choices, and opens the thank-you page after a send', function (): void {
    $folder = ContentPacks::use('an-vinh', database_path('content-packs'));
    ContentPacks::edit($folder, 'pack.json', fn (array $pack): array => ['hosts' => [ContentPacks::HOST]] + $pack);
    StorefrontCards::site('Demo', ContentPacks::HOST);
    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output);

    $page = $this->get('http://'.ContentPacks::HOST.'/lien-he')->assertOk()->getContent();
    preg_match_all('/<(?:input|select|textarea) id="[^"]+" (?:type="[a-z]+" )?name="([a-z_]+)"/', $page, $names);
    expect(array_values(array_diff($names[1], ['locale', 'company_website'])))->toBe(['name', 'phone', 'email', 'need', 'message', 'consent'])
        ->and($page)->toMatch('/<option value="">— Chọn nhu cầu —<\/option>\s*<option value="jig-do-ga"\s*>Thiết kế Jig &amp; Đồ gá<\/option>\s*<option value="khuon-mau"\s*>Khuôn mẫu<\/option>\s*<option value="gia-cong-chi-tiet"\s*>Gia công chi tiết<\/option>\s*<option value="khac"\s*>Khác<\/option>/')
        ->toContain('placeholder="ten@congty.vn"')->toContain('placeholder="0912 345 678"');

    config(['services.turnstile.secret_key' => '']);
    Cache::flush();
    Notification::fake();
    $this->post('http://'.ContentPacks::HOST.'/forms/contact', ['name' => 'A', 'phone' => '0912345678', 'need' => 'khac', 'message' => 'Xin báo giá', 'consent' => '1', 'locale' => 'vi'])
        ->assertStatus(303)->assertRedirect('http://'.ContentPacks::HOST.'/cam-on');
});

/** @return list<string> the title of every page_header block found anywhere in a decoded pack file */
function packBandTitles(mixed $node): array
{
    $titles = [];
    if (is_array($node)) {
        if (($node['type'] ?? null) === 'page_header' && is_string($node['props']['title'] ?? null)) {
            $titles[] = $node['props']['title'];
        }
        foreach ($node as $child) {
            $titles = [...$titles, ...packBandTitles($child)];
        }
    }

    return $titles;
}

it('writes every title band in sentence case, like the page names, never Every Word Capitalised', function (): void {
    $titles = [];
    foreach (glob(database_path('content-packs/*/*.json')) ?: [] as $file) {
        $titles = [...$titles, ...packBandTitles(json_decode((string) file_get_contents($file), true))];
    }

    expect($titles)->not->toBeEmpty();
    foreach ($titles as $title) {
        $words = array_values(array_filter(preg_split('/\s+/u', $title) ?: [], fn (string $word): bool => preg_match('/\p{L}/u', $word) === 1));
        $capitalised = array_filter($words, fn (string $word): bool => mb_strtoupper(mb_substr($word, 0, 1)) === mb_substr($word, 0, 1));

        expect(count($words) >= 3 && count($capitalised) === count($words))->toBeFalse($title);
    }
});

it('lists the five capabilities in the board order, names the list pages like the board and keeps one effective date on the policy', function (): void {
    $items = collect((new PackReader)->read('an-vinh')->items)->keyBy(fn ($item) => $item->key);

    // The list is newest first, so the board's 01…05 are the dates counted down.
    $order = collect(['thiet-ke-lap-trinh', 'gia-cong-phay-tien-cnc', 'gia-cong-cat-day-xung-dien', 'han-lap-rap-co-khi', 'do-kiem-qa-qc'])
        ->map(fn (string $slug): string => $items['post:'.$slug]->data['published_at']);
    expect($order->all())->toBe($order->sortDesc()->values()->all())->and($order->unique())->toHaveCount(5);

    expect($items['tax:post_cat/nang-luc']->data['name']['vi'])->toBe('Năng lực sản xuất')
        ->and($items['tax:post_cat/nang-luc']->data['description']['vi'])->toBe('Từ thiết kế, gia công đến đo kiểm – một quy trình khép kín')
        ->and($items['tax:post_cat/san-pham']->data['name']['vi'])->toBe('Sản phẩm của chúng tôi')
        ->and($items['tax:post_cat/du-an']->data['name']['vi'])->toBe('Dự án tiêu biểu')
        ->and($items['tax:post_cat/tin-tuc']->data['name']['vi'])->toBe('Tin tức & sự kiện')
        ->and($items['tax:post_cat/tuyen-dung']->data['name']['vi'])->toBe('Gia nhập đội ngũ An Vinh');

    $footer = collect($items['menu:footer']->data['items'][0]['children'])->pluck('label.vi')->all();
    expect($footer)->toBe(['Về công ty', 'Dự án', 'Nhà xưởng & máy móc', 'Hồ sơ năng lực', 'Chứng chỉ & thành tựu', 'Tin tức', 'Tuyển dụng', 'Liên hệ', 'Chính sách bảo mật']);

    foreach ($items->filter(fn ($item) => str_starts_with($item->key, 'product:')) as $product) {
        expect($product->data['description']['vi'])->not->toContain('Năng lực liên quan');
    }
    expect($items['page:/doi-tac']->data['body']['vi'][0]['props']['image_url'])->toBe(['media' => 'hero-2']);
});

it('dates the products in the board order with the Taplo jig first, and marks only it featured as the board shows', function (): void {
    $products = collect((new PackReader)->read('an-vinh')->items)->filter(fn ($item) => str_starts_with($item->key, 'product:'));

    // /san-pham lists newest first: the board's nine in its order, then the one product the board leaves out.
    $newestFirst = $products->sortByDesc(fn ($item) => $item->data['published_at'])->map(fn ($item) => $item->key)->values()->all();
    expect($newestFirst)->toBe([
        'product:jig-kiem-tap-lo-vf7', 'product:khuon-dap-binh-xang', 'product:may-tu-dong-nan-sua-san-pham',
        'product:truc-may-cnc', 'product:khuon-duc-ap-luc-cao', 'product:jig-han-frame-comp',
        'product:jig-han-stand-main', 'product:jig-kiem-tra-dien-tu', 'product:chi-tiet-phay-cnc',
        'product:khuon-dap-kim-loai',
    ])->and($products->map(fn ($item) => $item->data['published_at'])->unique())->toHaveCount($products->count());

    $featured = $products->filter(fn ($item) => ($item->data['featured'] ?? false) === true)->map(fn ($item) => $item->key)->values()->all();
    expect($featured)->toBe(['product:jig-kiem-tap-lo-vf7']);
});

it('keeps the real floating contact valid and stores it with round buttons everywhere, folding on a phone, an automatic bar tone and click counting', function (): void {
    $settings = collect(json_decode((string) file_get_contents((new PackReader)->read('an-vinh')->dir.'/settings.json'), true)['items'])->keyBy('key');
    $value = $settings['setting:storefront.floating_contact']['value'];

    expect(FloatingContactInput::errors($value, ['vi']))->toBe([])
        ->and(FloatingContactInput::stored($value, ['vi']))->toMatchArray(['theme' => 'vertical', 'layout' => 'vertical', 'dial' => true, 'bar_tone' => 'auto', 'track' => true]);
});
