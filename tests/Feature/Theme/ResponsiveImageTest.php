<?php

use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Rendering\BlockRenderer;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * Responsive images: srcset candidates snap to the widths the media Worker serves, never exceed
 * the master, keep the smallest and drop duplicates; the static driver (one file) gives none. The
 * page's images still resolve in one query, and image_url keeps its 1280 URL.
 */

beforeEach(function (): void {
    config(['media.widths' => [320, 640, 960, 1280, 1600, 2048], 'media.cloudflare.base' => 'https://files.test']);
});

it('builds a srcset capped at the master width and snapped to the served widths', function (): void {
    config(['media.driver' => 'cloudflare']);
    $resolver = app(MediaUrlResolver::class);

    expect($resolver->srcset('s/a.webp', [1280, 640, 960], 1000))
        ->toBe('https://files.test/s/a.webp?w=640 640w, https://files.test/s/a.webp?w=960 960w')
        ->and($resolver->srcset('s/a.webp', [700, 1300]))
        ->toBe('https://files.test/s/a.webp?w=960 960w, https://files.test/s/a.webp?w=1600 1600w')
        ->and($resolver->srcset('s/a.webp', [640, 960], 300))->toBeNull()          // only the smallest is left
        ->and($resolver->srcset('s/a.webp', [3000, 4000]))->toBeNull();             // both snap to 2048: one URL
});

it('gives no srcset on the static driver, which serves one file at every width', function (): void {
    config(['media.driver' => 'static']);

    expect(app(MediaUrlResolver::class)->srcset('s/a.webp', [640, 960, 1280], 2000))->toBeNull();
});

it('resolves every image of a page in one query and keeps image_url at 1280', function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $siteId = StorefrontCards::site();

    app(Tenancy::class)->enter($siteId, function (): void {
        $ids = array_map(fn (string $slug): int => StorefrontCards::media($slug)->id, ['a', 'b', 'c', 'd']);
        $body = ['blocks' => [
            ['type' => 'hero_slider', 'props' => ['slides' => [['image_url' => $ids[0], 'heading' => '1'], ['image_url' => $ids[1], 'heading' => '2'], ['image_url' => $ids[2], 'heading' => '3']]]],
            ['type' => 'page_header', 'props' => ['title' => 'Dải', 'image_url' => $ids[3]]],
        ]];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = app(BlockRenderer::class)->render($body);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect(collect($queries)->filter(fn (array $q): bool => str_contains($q['query'], '"media"'))->count())->toBe(1)
            ->and($html)->toMatch('#<img class="page-band__bg"\s+src="https://files\.test/s/library/d-[0-9a-f]{12}\.webp\?w=1280"#')
            ->and($html)->toMatch('#src="https://files\.test/s/library/a-[0-9a-f]{12}\.webp\?w=1280" srcset="[^"]*640w[^"]*1600w"#');
    });
});
