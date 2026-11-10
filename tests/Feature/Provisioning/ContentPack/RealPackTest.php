<?php

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use App\Modules\Tenancy\Models\SiteSetting;
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
    expect($content)->toBe(44)->and($jobs)->toHaveCount(3);
    foreach ($pack->slots as $slot) {
        if (isset($slot['file'])) {
            expect(PackValidator::mime($pack->dir.'/'.$slot['file']))->not->toBeNull();
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
        ->firstWhere('props.heading', 'Cam Kết Chất Lượng Theo Tiêu Chuẩn Quốc Tế');
    expect($quality['props']['image_url'])->toBe(['media' => 'iso-9001-certificate']);
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
                $videos[$block['props']['url']] = $block['props']['heading'];
            }
        }
    }

    foreach ($texts as $text) {
        expect($text)->not->toMatch('/dẫn đầu|tuyệt đối|duy nhất|100% sản phẩm|(tốt|tối ưu|khắt khe|tiết kiệm chi phí|rõ nét|số) (nhất|một)\b/iu');
    }
    // "hàng đầu" only in the company's stated goal ("trở thành … hàng đầu"), never as a claim about today
    preg_match_all('/.{0,90}hàng đầu/iu', implode("\n", $texts), $leading);
    expect($leading[0])->toHaveCount(1)->and($leading[0][0])->toMatch('/trở thành công ty/iu');
    expect($videos)->toHaveCount(3);
    foreach ($videos as $heading) {
        expect($heading)->toStartWith('Video minh họa:')->toContain('nguồn kênh')->toContain('giấy phép CC BY');
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
        ->toBe(['/gioi-thieu', '/nha-xuong-may-moc#video', '/nang-luc']);
    expect(collect($items['page:/gioi-thieu']->data['body']['vi'])->firstWhere('type', 'about')['props']['variant'])->toBe('letter');

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
        ->toContain('tháng 11 năm 2026');
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
    foreach (['/', '/gioi-thieu', '/doi-tac', '/nha-xuong-may-moc', '/cam-on', '/nang-luc', '/thiet-ke-lap-trinh', '/gia-cong-phay-tien-cnc',
        '/gia-cong-cat-day-xung-dien', '/han-lap-rap-co-khi', '/do-kiem-qa-qc', '/du-an', '/tin-tuc', '/san-pham', '/lien-he'] as $path) {
        $this->get('http://'.ContentPacks::HOST.$path)->assertOk();
    }
    foreach (['/ky-thuat-vien-van-hanh-may-cnc', '/ky-su-thiet-ke-co-khi-do-ga', '/nhan-vien-kiem-tra-chat-luong-qc'] as $path) {
        $this->get('http://'.ContentPacks::HOST.$path)->assertNotFound();
    }
    expect($this->get('http://'.ContentPacks::HOST.'/gioi-thieu')->getContent())->toContain('Nhà Xưởng &amp; Máy Móc')
        ->and($this->get('http://'.ContentPacks::HOST.'/gia-cong-cat-day-xung-dien')->getContent())->toContain('nguồn kênh Kurt Bleyweert')->toContain('4IhkMgHkMEA');
});

it('loads the flourish heading decoration, a floating contact of Zalo and call only, and the post side column and product band', function (): void {
    $settings = collect(json_decode((string) file_get_contents((new PackReader)->read('an-vinh')->dir.'/settings.json'), true)['items'])->keyBy('key');
    expect($settings['setting:theme.heading_decor']['value'])->toBe(['style' => 'flourish'])
        ->and(collect($settings['setting:storefront.floating_contact']['value']['channels'])->where('on', true)->pluck('key')->all())->toBe(['zalo', 'phone'])
        ->and($settings['setting:storefront.layout_blocks']['managed_keys'])->toBe(['post_aside', 'after_product'])
        ->and(collect($settings['setting:storefront.layout_blocks']['value']['post_aside']['vi']['blocks'])->pluck('type')->all())->toBe(['category_list', 'consult', 'recent_posts'])
        ->and(collect($settings['setting:storefront.layout_blocks']['value']['after_product']['vi']['blocks'])->pluck('type')->all())->toBe(['cta']);

    $folder = ContentPacks::use('an-vinh', database_path('content-packs'));
    ContentPacks::edit($folder, 'pack.json', fn (array $pack): array => ['hosts' => [ContentPacks::HOST]] + $pack);
    $siteId = StorefrontCards::site('Demo', ContentPacks::HOST);
    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output);

    $stored = fn (string $group, string $key): array => ContentPacks::in($siteId, fn (): array => SiteSetting::query()->where('group_name', $group)->where('key', $key)->value('value'));
    expect($stored('theme', 'heading_decor')['style'])->toBe('flourish')
        ->and(array_keys($stored('storefront', 'layout_blocks')))->toEqualCanonicalizing(['post_aside', 'after_product']);

    $home = $this->get('http://'.ContentPacks::HOST.'/')->assertOk()->getContent();
    expect($home)->toContain('<nav class="sf-fc sf-fc--right"')
        ->toContain('href="https://zalo.me/0915668383"')->toContain('href="tel:0915668383"')
        ->not->toContain('mailto:quybv@ckanvinh.vn" class="sf-fc')
        ->and(preg_match('/<nav class="sf-fc sf-fc--right".*?<\/nav>/s', $home, $nav))->toBe(1)
        ->and(preg_match_all('/<a /', $nav[0]))->toBe(2);

    $post = $this->get('http://'.ContentPacks::HOST.'/gia-cong-phay-tien-cnc')->assertOk()->getContent();
    expect($post)->toContain('Tư vấn kỹ thuật')->toContain('Cần tư vấn hoặc báo giá?')->toContain('Năng lực của An Vinh');

    $product = $this->get('http://'.ContentPacks::HOST.'/jig-kiem-tap-lo-vf7')->assertOk()->getContent();
    expect($product)->toContain('Cần đồ gá cho dây chuyền của bạn?');
});
