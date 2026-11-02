<?php

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * The real pack under database/content-packs passes every check the loader makes, publishes every
 * page, post and product (the owner publishes the content as it stands; the site's staff edit it
 * later), holds no testimonial, and plans with no conflict on a freshly provisioned site; after
 * loading, every internal link it holds resolves to a live address.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

afterEach(fn () => ContentPacks::cleanup());

/** @return list<string> every internal path a value links to (props ending in url/href) */
function packLinks(mixed $value, ?string $key = null): array
{
    if (is_string($value)) {
        return $key !== null && preg_match('/(url|href)$/', $key) === 1 && str_starts_with($value, '/') ? [$value] : [];
    }
    $links = [];
    foreach (is_array($value) ? $value : [] as $child => $item) {
        array_push($links, ...packLinks($item, is_string($child) ? $child : $key));
    }

    return $links;
}

it('validates the real pack: schema, bodies, slots, every page, post and product published, no testimonials', function (): void {
    $pack = (new PackReader)->read('an-vinh');

    expect(app(PackValidator::class)->validate($pack, 'an-vinh'))->toBe([]);

    $content = 0;
    foreach ($pack->items as $item) {
        if (preg_match('/\A(page|post|product):/', $item->key) === 1) {
            $content++;
            expect($item->data['verified'])->toBeTrue($item->key)
                ->and($item->data['status'])->toBe('published', $item->key)
                ->and($item->data)->not->toHaveKey('claims');
        }
        expect(json_encode($item->data))->not->toContain('testimonial');
    }
    expect($content)->toBe(36);
    foreach ($pack->slots as $slot) {
        if (isset($slot['file'])) {
            expect(PackValidator::mime($pack->dir.'/'.$slot['file']))->not->toBeNull();
        }
    }
});

it('gives every project, news card and product its own image and the quality block a photo', function (): void {
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
    expect($quality['props']['image_url'])->toBe(['media' => 'pr-body-check-fixture']);
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
});
