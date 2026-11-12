<?php

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Storefront\Http\Middleware\PreloadHeadAssets;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Rendering\BlockStyles;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * The Link header (the edge's Early Hints) names exactly the stylesheets the finished page links, in
 * link order: the page's head is narrowed after it is drawn, and the hints follow the narrowed list.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->siteId = StorefrontCards::site();
    // Like the real Vite: every entry is registered as preloaded, CSS gets a link, a script a module tag.
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(function (string $entry): string {
                $this->preloadedAssets['/'.$entry] = ['rel="preload"', 'as="style"'];

                return str_ends_with($entry, '.css')
                    ? '<link rel="stylesheet" href="/'.$entry.'">'
                    : '<script type="module" src="/'.$entry.'"></script>';
            })->implode(''));
        }
    });
});

/** @return list<string> the stylesheet hrefs the HTML links, in order */
function htmlStyleHrefs(string $html): array
{
    preg_match_all('~<link rel="stylesheet" href="([^"]+)">~', $html, $matches);

    return $matches[1];
}

/** @return list<string> the style hrefs the Link header hints, in order */
function linkStyleHrefs(string $link): array
{
    preg_match_all('~<([^>]+)>; rel="preload"; as="style"~', $link, $matches);

    return $matches[1];
}

it('hints exactly the stylesheets a home-like page links, every one of them', function (): void {
    // The provisioned home page gets real blocks in place of its starter body.
    app(Tenancy::class)->enter($this->siteId, function (): void {
        $home = SiteRoute::query()->where('path', '/')->firstOrFail();
        EntryTranslation::query()->where('entry_id', $home->entry_id)->update(['body' => json_encode(['blocks' => [
            ['type' => 'cta', 'props' => ['heading' => 'Liên hệ', 'button_label' => 'Gọi', 'button_url' => '/lien-he']],
            ['type' => 'faq', 'props' => ['items' => [['q' => 'Ở đâu?', 'a' => 'Hà Nội']]]],
            ['type' => 'stats', 'props' => ['items' => [['value' => 15, 'suffix' => '+', 'label' => 'Năm']]]],
            ['type' => 'richtext', 'props' => ['heading' => 'Đôi lời', 'body' => 'Nội dung']],
        ]])]);
    });

    $response = $this->get('http://mau.platform.test/')->assertOk();
    $html = $response->getContent();
    $hints = linkStyleHrefs($response->headers->get('Link', ''));

    expect(htmlStyleHrefs($html))->toContain('/resources/css/sf-cta.css', '/resources/css/sf-blocks.css', '/resources/css/sf-stats.css')
        ->and($hints)->toBe(htmlStyleHrefs($html))
        ->and($hints[0])->toBe('/resources/css/storefront.css')
        ->and($hints)->not->toContain('/resources/css/sf-hero.css')
        ->and($hints)->not->toContain('/resources/css/sf-slider.css');
});

it('hints exactly the stylesheets a list page links and none it does not use', function (): void {
    app(Tenancy::class)->enter($this->siteId, function (): void {
        StorefrontCards::post(StorefrontCards::news(), 'Khai trương xưởng mới', 'khai-truong-xuong-moi');
    });

    $response = $this->get('http://mau.platform.test/tin-tuc')->assertOk();
    $hints = linkStyleHrefs($response->headers->get('Link', ''));

    expect($hints)->toBe(htmlStyleHrefs($response->getContent()))
        ->and($hints)->toContain('/resources/css/sf-lists.css')
        ->and(count($hints))->toBeLessThan(count(BlockStyles::FILES) + 1)
        ->and($hints)->not->toContain('/resources/css/sf-hero.css')
        ->and($hints)->not->toContain('/resources/css/sf-decor.css');
});

it('keeps the 304 and the edge-cache headers of the page', function (): void {
    app(Tenancy::class)->enter($this->siteId, fn () => StorefrontCards::page('/gioi-thieu', 'Giới thiệu', [
        ['type' => 'richtext', 'props' => ['heading' => 'Đôi lời', 'body' => 'Nội dung']],
    ]));

    $first = $this->get('http://mau.platform.test/gioi-thieu')->assertOk();
    expect($first->headers->get('Cache-Control'))->not->toBeNull()
        ->and($first->headers->get('Link'))->not->toBeNull();

    $this->get('http://mau.platform.test/gioi-thieu', ['If-None-Match' => $first->headers->get('ETag')])->assertStatus(304);
});

it('lists the font with its crossorigin and lets modules fill only what the cap leaves', function (): void {
    $html = '<html><head>'
        .'<link rel="preload" href="/fonts/a.woff2" as="font" type="font/woff2" crossorigin>'
        .'<link rel="stylesheet" href="/a.css"><link rel="stylesheet" href="/b.css">'
        .implode('', array_map(fn (int $i): string => '<link rel="modulepreload" href="/m'.$i.'.js">', range(1, 20)))
        .'</head><body></body></html>';

    $response = (new PreloadHeadAssets)->handle(request(), fn () => response($html));
    $entries = explode(', ', $response->headers->get('Link'));

    expect($entries)->toHaveCount(PreloadHeadAssets::MAX_HINTS)
        ->and($entries[0])->toBe('</a.css>; rel="preload"; as="style"')
        ->and($entries[1])->toBe('</b.css>; rel="preload"; as="style"')
        ->and($entries[2])->toBe('</fonts/a.woff2>; rel="preload"; as="font"; type="font/woff2"; crossorigin')
        ->and($entries[3])->toBe('</m1.js>; rel="modulepreload"');
});

it('never drops a stylesheet for the cap', function (): void {
    $html = '<head>'.implode('', array_map(fn (int $i): string => '<link rel="stylesheet" href="/s'.$i.'.css">', range(1, 20))).'</head>';

    $response = (new PreloadHeadAssets)->handle(request(), fn () => response($html));

    expect(explode(', ', $response->headers->get('Link')))->toHaveCount(20);
});
