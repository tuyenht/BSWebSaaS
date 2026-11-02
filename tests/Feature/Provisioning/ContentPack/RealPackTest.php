<?php

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * The real pack under database/content-packs passes every check the loader makes, holds no
 * published unverified item and no testimonial, and plans with no conflict on a freshly
 * provisioned site; after loading, every internal link it holds resolves to a live address.
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

it('validates the real pack: schema, bodies, slots, unverified items and no testimonials', function (): void {
    $pack = (new PackReader)->read('an-vinh');

    expect(app(PackValidator::class)->validate($pack, 'an-vinh'))->toBe([]);

    foreach ($pack->items as $item) {
        if (($item->data['verified'] ?? true) === false) {
            expect($item->data['status'])->toBe('draft');
        }
        expect(json_encode($item->data))->not->toContain('testimonial');
    }
    foreach ($pack->slots as $slot) {
        if (isset($slot['file'])) {
            expect(PackValidator::mime($pack->dir.'/'.$slot['file']))->not->toBeNull();
        }
    }
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
