<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Rendering\BlockStyles;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * The storefront's block stylesheets: the head links only the files whose classes the drawn page uses,
 * after storefront.css, once each and in one fixed order; every rule in a file needs one of the file's
 * classes, so a page without them loses nothing.
 */

/** Strip CSS comments and return every style rule's selector list (keyframe steps left out). */
function blockStyleSelectors(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    $selectors = [];
    $stack = [];
    $buffer = '';
    foreach (str_split($css) as $char) {
        if ($char === '{') {
            $prelude = trim($buffer);
            $inKeyframes = in_array('keyframes', $stack, true);
            $stack[] = str_starts_with($prelude, '@keyframes') ? 'keyframes' : (str_starts_with($prelude, '@') ? 'at' : 'rule');
            if (! $inKeyframes && ! str_starts_with($prelude, '@')) {
                $selectors[] = $prelude;
            }
            $buffer = '';
        } elseif ($char === '}') {
            array_pop($stack);
            $buffer = '';
        } elseif ($char === ';' && end($stack) !== 'rule') {
            $buffer = '';
        } else {
            $buffer .= $char;
        }
    }

    return $selectors;
}

/** The classes a selector cannot match without (inside :not, :is, :where and nth arguments they are optional). */
function blockStyleRequiredClasses(string $selector): array
{
    do {
        $before = $selector;
        $selector = (string) preg_replace('~:(?:not|is|where|nth-child|nth-last-child|nth-of-type|nth-last-of-type)\((?:[^()]|\([^()]*\))*\)~', '', $selector);
    } while ($selector !== $before);
    preg_match_all('~\.([A-Za-z0-9_-]+)~', $selector, $matches);

    return array_map(BlockStyles::root(...), $matches[1]);
}

/** Split a selector list on its top-level commas. */
function blockStyleSplit(string $list): array
{
    $parts = [];
    $depth = 0;
    $current = '';
    foreach (str_split($list) as $char) {
        $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
        if ($char === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';

            continue;
        }
        $current .= $char;
    }

    return [...$parts, trim($current)];
}

describe('the files', function (): void {
    it('needs one of its own classes in every rule of every block stylesheet', function (): void {
        foreach (BlockStyles::FILES as $file => $roots) {
            $css = (string) file_get_contents(base_path($file));
            $selectors = blockStyleSelectors($css);
            expect($selectors)->not->toBeEmpty($file);
            foreach ($selectors as $list) {
                foreach (blockStyleSplit($list) as $selector) {
                    expect(array_intersect(blockStyleRequiredClasses($selector), $roots))
                        ->not->toBeEmpty("{$file}: {$selector}");
                }
            }
        }
    });

    it('builds every block stylesheet and gives each one a size budget', function (): void {
        $vite = (string) file_get_contents(base_path('vite.config.js'));
        $budget = json_decode((string) file_get_contents(base_path('scripts/asset-budget.json')), true);
        foreach (array_keys(BlockStyles::FILES) as $file) {
            expect($vite)->toContain("'{$file}'")
                ->and($budget['entries'])->toHaveKey($file);
        }
        expect(strpos($vite, "'resources/css/storefront.css'"))->toBeLessThan(strpos($vite, "'resources/css/sf-hero.css'"));
    });

    it('keeps one owner per class root', function (): void {
        $roots = array_merge(...array_values(BlockStyles::FILES));

        expect($roots)->toBe(array_values(array_unique($roots)));
    });
});

describe('narrowing', function (): void {
    beforeEach(function (): void {
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<link rel="stylesheet" href="/'.$entry.'">')->implode(''));
            }
        });
    });

    it('cuts a class name at its first element or modifier mark', function (string $class, string $root): void {
        expect(BlockStyles::root($class))->toBe($root);
    })->with([
        ['sf-card__title', 'sf-card'],
        ['sf-card--featured', 'sf-card'],
        ['sf-fc-bar__link', 'sf-fc-bar'],
        ['hero', 'hero'],
        ['about--letter__x', 'about'],
    ]);

    it('lists the files whose classes the page uses, in link order', function (): void {
        $html = '<main><div class="sf-stats sf-band"><p class="sf-card__title x">Số</p></div><div class="hero--split"></div></main>';

        expect(BlockStyles::needed($html))->toBe(['resources/css/sf-hero.css', 'resources/css/sf-cards.css', 'resources/css/sf-stats.css']);
    });

    it('does not take a longer class with the same start for a root', function (): void {
        expect(BlockStyles::needed('<div class="sf-cardigan heroic post-it"></div>'))->toBe([]);
    });

    it('reads the classes of a template the page copies in later', function (): void {
        expect(BlockStyles::needed('<template id="sf-viewer"><dialog class="sf-viewer" aria-label="Ảnh"></dialog></template>'))
            ->toBe(['resources/css/sf-media.css']);
    });

    it('leaves HTML without the markers as it is', function (): void {
        $html = '<head><link rel="stylesheet" href="/x.css"></head><body class="sf-card"></body>';

        expect(BlockStyles::narrow($html))->toBe($html);
    });

    it('prints every file between the markers until the page is narrowed', function (): void {
        $all = (string) BlockStyles::all();

        expect($all)->toStartWith(BlockStyles::START)->toEndWith(BlockStyles::END);
        foreach (array_keys(BlockStyles::FILES) as $file) {
            expect(substr_count($all, '/'.$file.'"'))->toBe(1);
        }
    });

    it('replaces the full list with the files the page needs and nothing else', function (): void {
        $html = '<head>'.BlockStyles::all().'</head><body><section class="cta cta--rounded"><h2 class="sf-decor sf-decor--bar">Đề nghị</h2></section></body>';
        $narrowed = BlockStyles::narrow($html);

        expect($narrowed)->toBe('<head><link rel="stylesheet" href="/resources/css/sf-cta.css"><link rel="stylesheet" href="/resources/css/sf-decor.css"></head><body><section class="cta cta--rounded"><h2 class="sf-decor sf-decor--bar">Đề nghị</h2></section></body>');
    });

    it('prints no block stylesheet for a page that uses none of their classes', function (): void {
        expect(BlockStyles::narrow('<head>'.BlockStyles::all().'</head><body class="x"><p class="sf-container">Chữ</p></body>'))
            ->toBe('<head></head><body class="x"><p class="sf-container">Chữ</p></body>');
    });
});

describe('on a page', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $this->siteId = StorefrontCards::site();
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => str_ends_with($entry, '.css') ? '<link rel="stylesheet" href="/'.$entry.'">' : '<script type="module" src="/'.$entry.'"></script>')->implode(''));
            }
        });
    });

    /** @return list<string> the stylesheets the page links, in order */
    function blockStyleLinks(string $html): array
    {
        preg_match_all('~<link rel="stylesheet" href="/([^"]+)">~', $html, $matches);

        return $matches[1];
    }

    /** @param  list<array<string, mixed>>  $blocks */
    function blockStylePage(string $path, array $blocks): string
    {
        app(Tenancy::class)->enter(test()->siteId, fn () => StorefrontCards::page($path, 'Giới thiệu', $blocks));

        return test()->get('http://mau.platform.test'.$path)->assertOk()->getContent();
    }

    it('links the stats stylesheet on a page with a stats block, once, after the core one', function (): void {
        $html = blockStylePage('/so-lieu', [['type' => 'stats', 'props' => ['items' => [['value' => 15, 'suffix' => '+', 'label' => 'Năm']]]]]);
        $links = blockStyleLinks($html);

        expect($links[0])->toBe('resources/css/storefront.css')
            ->and($links)->toContain('resources/css/sf-stats.css')
            ->and($links)->toBe(array_values(array_unique($links)))
            ->and($html)->not->toContain(BlockStyles::START)
            ->and($html)->not->toContain(BlockStyles::END);
    });

    it('links no stats, form or product stylesheet on a page without those blocks', function (): void {
        $links = blockStyleLinks(blockStylePage('/chu', [['type' => 'richtext', 'props' => ['heading' => 'Đôi lời', 'body' => 'Nội dung chữ']]]));

        expect($links)->not->toContain('resources/css/sf-stats.css')
            ->and($links)->not->toContain('resources/css/sf-form.css')
            ->and($links)->not->toContain('resources/css/sf-product.css')
            ->and($links)->not->toContain('resources/css/sf-post.css');
    });

    it('links exactly the files the drawn page needs', function (): void {
        $html = blockStylePage('/goi-y', [
            ['type' => 'cta', 'props' => ['heading' => 'Liên hệ ngay', 'button_label' => 'Gọi', 'button_url' => '/lien-he']],
            ['type' => 'faq', 'props' => ['items' => [['q' => 'Ở đâu?', 'a' => 'Hà Nội']]]],
        ]);

        expect(array_slice(blockStyleLinks($html), 1))->toBe(BlockStyles::needed($html));
    });

    it('narrows the list page, the post page and the not-found page too', function (): void {
        app(Tenancy::class)->enter($this->siteId, function (): void {
            $news = StorefrontCards::news();
            StorefrontCards::post($news, 'Khai trương xưởng mới', 'khai-truong-xuong-moi');
        });

        $list = $this->get('http://mau.platform.test/tin-tuc')->assertOk()->getContent();
        $post = $this->get('http://mau.platform.test/tin-tuc/khai-truong-xuong-moi')->assertOk()->getContent();
        $missing = $this->get('http://mau.platform.test/khong-co-trang-nay')->assertNotFound()->getContent();

        expect(blockStyleLinks($list))->toContain('resources/css/sf-lists.css')
            ->and(blockStyleLinks($post))->toContain('resources/css/sf-post.css')
            ->and(blockStyleLinks($missing))->toContain('resources/css/sf-404.css')
            ->and(blockStyleLinks($missing))->not->toContain('resources/css/sf-post.css');
        foreach ([$list, $post, $missing] as $html) {
            expect($html)->not->toContain(BlockStyles::START)
                ->and(array_slice(blockStyleLinks($html), 1))->toBe(BlockStyles::needed($html));
        }
    });
});
