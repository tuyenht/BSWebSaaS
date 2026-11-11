<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Catalog\Components\ProductGridComponent;
use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\ListDisplay;
use App\Modules\Theme\Components\SectionProps;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Tests\Fixtures\StorefrontCards;

/*
 * Lists that carry the display group draw a rail (grid, slider or swipe row, with arrows and a pause
 * button the script reveals); lists saved before the group draw exactly what they always did. "View
 * all" learns there is more from one extra row, and the page loads the slider script only when a
 * list needs it.
 */

/** @param array<string, mixed> $data */
function railView(string $view, array $data): string
{
    app()->setLocale('vi');

    return View::make('theme::sections.'.$view, $data)->render();
}

/** @return list<array{image_url: string, name: string}> */
function logos(int $n): array
{
    return array_map(fn (int $i): array => ['image_url' => "https://files.test/l/{$i}.webp", 'name' => "Đối tác {$i} ư"], range(1, $n));
}

/** @return list<array{quote: string, name: string}> */
function quotes(int $n): array
{
    return array_map(fn (int $i): array => ['quote' => "Rất tốt số {$i} ạ", 'name' => "Khách {$i} ế"], range(1, $n));
}

/** @return list<array{name: string}> */
function members(int $n): array
{
    return array_map(fn (int $i): array => ['name' => "Thành viên {$i} ộ"], range(1, $n));
}

/** @return list<array<string, string>> */
function railCards(int $n): array
{
    return array_map(fn (int $i): array => ['title' => "Bài số {$i} ạ", 'url' => "/bai-{$i}", 'image' => '', 'srcset' => null, 'icon' => '', 'excerpt' => '', 'date' => '', 'datetime' => '', 'label' => ''], range(1, $n));
}

it('draws a list saved before the display group exactly as it was', function (string $view, array $props, string $kept): void {
    $html = railView($view, ['props' => $props, 'items' => [], 'variant' => 'list', 'display' => null]);

    expect($html)->not->toContain('sf-rail')->and($html)->not->toContain('data-sf-rail')->and($html)->toContain($kept);
})->with([
    'partners' => ['partners', ['logos' => logos(8)], 'partners__list'],
    'testimonial' => ['testimonial', ['items' => quotes(5)], 'sf-quotes__list sf-quotes__list--grid-2 sf-quotes__list--n2'],
    'team' => ['team', ['members' => members(7)], 'sf-team__list sf-team__list--grid-3 sf-team__list--n3'],
]);

it('draws an entry or product list saved before the group with its own grid classes', function (string $view, string $variant, string $kept): void {
    $html = railView($view, ['props' => ['limit' => 6], 'items' => railCards(12), 'variant' => $variant, 'display' => null]);

    expect($html)->not->toContain('sf-rail')->and($html)->toContain($kept);
})->with([
    'service icons' => ['entry_grid', 'icon-cards', 'sf-grid sf-grid--4'],
    'project cards' => ['entry_grid', 'project-cards', 'sf-grid sf-grid--4s'],
    'news cards' => ['entry_grid', 'news-cards', 'sf-grid sf-grid--4s'],
    'product overlay' => ['entry_grid', 'overlay', 'sf-grid sf-grid--3s'],
]);

it('draws a new list block as a rail with the controls hidden for the script', function (string $view, array $props, string $class): void {
    $html = railView($view, ['props' => ['layout' => 'auto'] + $props]);

    expect($html)->toContain('data-sf-rail')->and($html)->toContain('<div class="sf-rail__controls">')
        ->and($html)->toContain($class)
        ->and($html)->toContain('data-sf-rail-prev hidden aria-label="Xem mục trước" title="Xem mục trước"')
        ->and($html)->toContain('data-sf-rail-next hidden aria-label="Xem mục tiếp theo" title="Xem mục tiếp theo"')
        ->and($html)->not->toContain('Ảnh trước');
})->with([
    'partners' => ['partners', ['logos' => logos(8)], 'sf-rail__list sf-rail__list--slider sf-rail__list--c6 sf-rail__list--m-slider'],
    'testimonial' => ['testimonial', ['items' => quotes(5)], 'sf-rail__list sf-rail__list--slider sf-rail__list--c3'],
    'team' => ['team', ['members' => members(7)], 'sf-rail__list sf-rail__list--grid sf-rail__list--c4 sf-rail__list--m-swipe'],
]);

it('draws no pause button on any rail, rotating or still, and the pause texts are gone from the language files', function (): void {
    $rotating = railView('partners', ['props' => ['layout' => 'auto', 'logos' => logos(8)]]);
    $still = railView('team', ['props' => ['layout' => 'auto', 'members' => members(7)]]);
    $js = (string) file_get_contents(base_path('resources/js/sf-slider.js'));

    expect($rotating)->toContain('data-interval="3000"')->and($still)->toContain('data-interval="0"');
    foreach ([$rotating, $still] as $html) {
        expect($html)->not->toContain('data-sf-rail-pause')->and($html)->not->toContain('sf-rail__pause')->and($html)->not->toContain('sf-rail__bar');
    }
    expect($js)->not->toContain('labelPause')->and($js)->not->toContain('data-sf-pause');
    foreach (['vi', 'en', 'ja', 'zh'] as $locale) {
        $file = json_decode((string) file_get_contents(base_path("lang/{$locale}.json")), true);
        expect($file)->not->toHaveKey('storefront.rail_pause')->and($file)->not->toHaveKey('storefront.slide_pause');
    }
});

it('gives the arrows a 44px hit area with a 36px disc and no arrows when the block says none', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
    $none = railView('partners', ['props' => ['layout' => 'auto', 'arrows' => 'none', 'logos' => logos(8)]]);

    expect($css)->toMatch('/\.sf-rail__btn \{[^}]*width: 2\.75rem;[^}]*height: 2\.75rem;[^}]*background: transparent;/s')
        ->and($css)->toMatch('/\.sf-rail__btn::before \{[^}]*inset: 0\.25rem;/s')
        ->and($none)->not->toContain('data-sf-rail-prev')->and($none)->not->toContain('data-sf-rail-next');
});

it('lets the script rotate nothing on a phone swipe row or under reduced motion, and pause on hover, focus and touch', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/sf-slider.js'));

    expect($js)->toContain("!(phone.matches && root.dataset.mobile === 'swipe')")
        ->and($js)->toContain('!reduce.matches')
        ->and($js)->toContain("addEventListener('pointerenter'")->and($js)->toContain("addEventListener('pointerleave'")
        ->and($js)->toContain("addEventListener('focusin'")->and($js)->toContain("addEventListener('focusout'")
        ->and($js)->toContain("addEventListener('pointerdown'")->and($js)->toContain('const HOLD = 10000;')
        ->and($js)->toContain("addEventListener('visibilitychange'");
});

it('draws a rail of at most 24 partner logos and keeps all when it is a grid', function (): void {
    $slider = railView('partners', ['props' => ['layout' => 'slider', 'logos' => logos(30)]]);
    $grid = railView('partners', ['props' => ['layout' => 'grid', 'logos' => logos(30)]]);

    expect(substr_count($slider, 'partners__logo'))->toBe(24)->and(substr_count($grid, 'partners__logo'))->toBe(30);
});

it('draws the card looks as a rail and shows "view all" only when the plan says so', function (): void {
    $props = ['layout' => 'auto', 'limit' => 6, 'view_all_label' => 'Xem tất cả bài viết', 'view_all_url' => '/tin-tuc'];
    $more = ListDisplay::plan('project', $props + ['limit' => 8], 9, 8);
    $all = ListDisplay::plan('project', ['layout' => 'grid'] + $props + ['limit' => 8], 8, 8);

    $withMore = railView('entry_grid', ['props' => $props, 'items' => railCards(8), 'variant' => 'project-cards', 'display' => $more]);
    $noMore = railView('entry_grid', ['props' => $props, 'items' => railCards(8), 'variant' => 'project-cards', 'display' => $all]);

    expect($withMore)->toContain('sf-rail__list sf-rail__list--slider')->and($withMore)->toContain('>Xem tất cả bài viết</a>')
        ->and($noMore)->toContain('sf-rail__list sf-rail__list--grid')->and($noMore)->not->toContain('Xem tất cả bài viết')
        ->and($withMore)->not->toContain('(6)')->and($withMore)->not->toContain('(7)');
});

it('reads one extra row for "view all" and shows it only when that row exists', function (): void {
    StorefrontCards::reset();
    app(Tenancy::class)->enter(StorefrontCards::site(), function (): void {
        $news = StorefrontCards::news();
        foreach (range(1, 4) as $n) {
            StorefrontCards::post($news, "Tin số {$n} ạ", "tin-so-{$n}", CarbonImmutable::parse('2026-09-01 08:00', 'UTC')->addDays($n));
        }
        $component = app(EntryGridComponent::class);
        $base = ['taxonomy_slug' => 'tin-tuc', 'variant' => 'news-cards', 'locale' => 'vi', 'layout' => 'auto'];

        $three = $component->data($base + ['limit' => 3]);
        $four = $component->data($base + ['limit' => 4]);
        $nullLayout = $component->data(['layout' => null] + $base + ['limit' => 3]);
        unset($base['layout']);
        $old = $component->data($base + ['limit' => 3]);

        expect($three['items'])->toHaveCount(3)->and($three['display']['view_all'])->toBeTrue()
            ->and($four['items'])->toHaveCount(4)->and($four['display']['view_all'])->toBeFalse()
            ->and($old['items'])->toHaveCount(3)->and($old['display'])->toBeNull()
            ->and($nullLayout['display']['view_all'])->toBeTrue();
    });
});

it('reads the same number of queries with and without the display group', function (): void {
    StorefrontCards::reset();
    app(Tenancy::class)->enter(StorefrontCards::site(), function (): void {
        $news = StorefrontCards::news();
        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham');
        StorefrontCards::catalog($catalog);
        foreach (range(1, 5) as $n) {
            StorefrontCards::post($news, "Tin số {$n} ạ", "tin-so-{$n}", CarbonImmutable::parse('2026-09-01 08:00', 'UTC')->addDays($n));
            StorefrontCards::product("Sản phẩm {$n} ạ", "san-pham-{$n}", $catalog);
        }
        $count = function (string $component, array $props): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app($component)->data($props);

            return count(DB::getQueryLog());
        };
        $entries = ['taxonomy_slug' => 'tin-tuc', 'variant' => 'news-cards', 'locale' => 'vi', 'limit' => 3];
        $products = ['variant' => 'overlay', 'locale' => 'vi', 'limit' => 3];
        $count(EntryGridComponent::class, $entries);
        $count(ProductGridComponent::class, $products);

        expect($count(EntryGridComponent::class, $entries + ['layout' => 'auto']))->toBe($count(EntryGridComponent::class, $entries))
            ->and($count(ProductGridComponent::class, $products + ['layout' => 'auto']))->toBe($count(ProductGridComponent::class, $products));

        $data = app(ProductGridComponent::class)->data($products + ['layout' => 'auto']);
        expect($data['items'])->toHaveCount(3)->and($data['display']['view_all'])->toBeTrue();
        expect(app(ProductGridComponent::class)->data(['limit' => 5, 'locale' => 'vi', 'layout' => 'auto'])['display']['view_all'])->toBeFalse();
    });
});

it('declares the group on the five list blocks and refuses an unknown value when saving', function (string $type): void {
    $schema = app(ComponentRegistry::class)->schemaFor($type);

    foreach (array_keys(SectionProps::LIST_DISPLAY) as $prop) {
        expect($schema)->toHaveKey($prop);
    }
})->with(['entry_grid', 'product_grid', 'partners', 'testimonial', 'team']);

it('validates the group in the page JSON', function (): void {
    seedTenancy();
    $check = fn (array $props): bool => ! Validator::make(
        ['blocks' => [['type' => 'partners', 'props' => $props]]],
        ['blocks' => [app(ValidPageJson::class)]],
    )->fails();

    expect($check(['layout' => 'slider', 'columns' => '4', 'mobile' => 'swipe', 'autoplay' => '8', 'arrows' => 'always', 'view_all' => 'hide']))->toBeTrue()
        ->and($check(['layout' => 'carousel']))->toBeFalse()
        ->and($check(['autoplay' => '1']))->toBeFalse()
        ->and($check(['columns' => '5']))->toBeFalse();
});

it('tells the editor the range when a list limit is out of it, not to type a whole number', function (int|string $limit): void {
    seedTenancy();
    app()->setLocale('vi');
    $validator = Validator::make(
        ['blocks' => [['type' => 'entry_grid', 'props' => ['limit' => $limit]]]],
        ['blocks' => [app(ValidPageJson::class)]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first())->toContain('từ 1 đến 50')->and($validator->errors()->first())->not->toContain('1500000');
})->with([0, 51]);

it('takes a list limit of 1 to 50 when saving and refuses the rest', function (string $type, int|string $limit, bool $accepted): void {
    seedTenancy();
    $valid = ! Validator::make(
        ['blocks' => [['type' => $type, 'props' => ['limit' => $limit]]]],
        ['blocks' => [app(ValidPageJson::class)]],
    )->fails();

    expect($valid)->toBe($accepted);
})->with([
    'entries, 1' => ['entry_grid', 1, true],
    'entries, 50' => ['entry_grid', 50, true],
    'entries, 0' => ['entry_grid', 0, false],
    'entries, 51' => ['entry_grid', 51, false],
    'products, "12"' => ['product_grid', '12', true],
    'products, 51' => ['product_grid', 51, false],
    'products, "abc"' => ['product_grid', 'abc', false],
]);

it('announces only a slider as a carousel, in the page language, and gives a scrolling row a keyboard stop', function (): void {
    $slider = railView('partners', ['props' => ['layout' => 'slider', 'heading' => 'Đối tác ạ', 'logos' => logos(8)]]);
    $grid = railView('team', ['props' => ['layout' => 'grid', 'members' => members(3)]]);

    expect($slider)->toContain('role="group" aria-roledescription="băng chuyền" aria-label="Đối tác ạ"')
        ->and($slider)->toMatch('/<ul class="sf-rail__list sf-rail__list--slider[^"]*" tabindex="0">/')
        ->and(substr_count($slider, 'aria-label="Đối tác ạ"'))->toBe(1)
        ->and($grid)->toContain('data-sf-rail')->and($grid)->not->toContain('aria-roledescription')->and($grid)->not->toContain('role="group"')
        ->and($grid)->not->toContain('tabindex');
});

it('announces a list that rotates only on a phone, and names every rail even when its heading is blank', function (): void {
    $phoneOnly = railView('partners', ['props' => ['layout' => 'grid', 'heading' => '   ', 'logos' => logos(5)]]);
    $team = railView('team', ['props' => ['layout' => 'slider', 'heading' => '  ', 'members' => members(9)]]);
    $cards = railView('entry_grid', ['props' => ['layout' => 'slider', 'heading' => ' ', 'limit' => 9], 'items' => railCards(9), 'variant' => 'grid', 'display' => ListDisplay::plan('news', ['layout' => 'slider'], 9, 9)]);

    expect($phoneOnly)->toContain('role="group" aria-roledescription="băng chuyền" aria-label="Danh sách"')
        ->and($phoneOnly)->toMatch('/<ul class="sf-rail__list sf-rail__list--grid[^"]*" tabindex="0">/')
        ->and($team)->toContain('aria-label="Danh sách"')->and($team)->not->toContain('aria-label=""')
        ->and($cards)->toContain('aria-label="Danh sách"')->and($cards)->not->toContain('aria-label=""');
});

it('draws the rail controls as one row below the list: arrow, dots, arrow, all hidden until the script shows them', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
    $html = railView('team', ['props' => ['layout' => 'slider', 'members' => members(9)]]);

    expect($html)->toMatch('#<div class="sf-rail__controls">\s*<button type="button" class="sf-rail__btn sf-rail__prev" data-sf-rail-prev hidden aria-label="[^"]+" title="[^"]+">.*?<span class="sf-rail__dots" data-sf-rail-dots hidden aria-hidden="true"></span>\s*<button type="button" class="sf-rail__btn sf-rail__next" data-sf-rail-next hidden aria-label="[^"]+"#s')
        ->and($css)->toMatch('/\.sf-rail__controls \{[^}]*display: flex;[^}]*justify-content: center;/s')
        ->and($css)->toMatch('/@media \(min-width: 1360px\) \{\s*\.sf-rail__controls \{[^}]*\}\s*\.sf-rail__prev,\s*\.sf-rail__next \{\s*position: absolute;/s')
        ->and($css)->not->toContain('.sf-rail__bar')->and($css)->not->toContain('sf-slider__pause');
});

it('shows a slice of the next card on a tablet slider and lets the logo strip scroll freely', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/storefront.css'));

    expect($css)->toMatch('/\.sf-rail__list--slider:not\(\.sf-rail__list--c6\) \{ --sf-c: 2\.25; \}/')
        ->and($css)->toMatch('/\.partners \.sf-rail__list--m-slider \{\s*scroll-snap-type: none;/s');
});

it('lets the phone rules win under 640px, caps the tablet at two (three logos for partners) and keeps the scrollbar when it is the only way to scroll', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
    $tablet = strpos($css, '@media (min-width: 640px) and (max-width: 1023px) {', (int) strpos($css, '.sf-rail {'));
    $phone = strpos($css, '@media (max-width: 639px) {', (int) $tablet);

    expect($css)->not->toContain('.sf-rail__list[class]')->and($css)->not->toContain('.sf-rail__list::-webkit-scrollbar')
        ->and($tablet)->toBeInt()->and($phone)->toBeGreaterThan($tablet)
        ->and($css)->toMatch('/\.partners \.sf-rail__list \{ --sf-c: 3; \}/')
        ->and($css)->toContain('.sf-rail__list > li > :only-child')
        ->and($css)->toMatch('/@media \(scripting: enabled\) \{\s*\.sf-rail:not\(\[data-arrows="none"\]\) \.sf-rail__list \{\s*scrollbar-width: none;/s');
});

it('shows the arrows only on a row that can scroll, and the title tooltips on every rail button', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/sf-slider.js'));
    $html = railView('partners', ['props' => ['layout' => 'auto', 'logos' => logos(8)]]);

    expect($js)->toContain('over = overflows()')->and($js)->toContain("over && mode !== 'none'")->and($js)->not->toContain('hover: hover')
        ->and(substr_count($html, 'title="'))->toBe(2);
});

it('shows "view all" under the plain product grid only when the block carries the group', function (): void {
    $props = ['view_all_label' => 'Xem tất cả sản phẩm', 'view_all_url' => '/san-pham'];
    $cards = array_map(fn (int $i): array => ['title' => "Sản phẩm {$i} ạ", 'url' => "/sp-{$i}", 'image' => ''], range(1, 9));
    $more = ListDisplay::plan('product', ['layout' => 'auto'] + $props + ['limit' => 8], 9, 8);
    $hidden = ListDisplay::plan('product', ['layout' => 'auto', 'view_all' => 'hide'] + $props + ['limit' => 8], 9, 8);

    $shown = railView('entry_grid', ['props' => ['layout' => 'auto'] + $props, 'items' => array_slice($cards, 0, 8), 'variant' => 'grid', 'display' => $more]);
    $off = railView('entry_grid', ['props' => ['layout' => 'auto', 'view_all' => 'hide'] + $props, 'items' => array_slice($cards, 0, 8), 'variant' => 'grid', 'display' => $hidden]);
    $old = railView('entry_grid', ['props' => $props, 'items' => $cards, 'variant' => 'grid', 'display' => null]);

    expect($shown)->toContain('>Xem tất cả sản phẩm</a>')->and($shown)->toContain('sf-rail__list sf-rail__list--grid')
        ->and($off)->not->toContain('Xem tất cả sản phẩm')
        ->and($old)->not->toContain('Xem tất cả sản phẩm')->and($old)->not->toContain('sf-rail');
});

describe('on a page', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
            }
        });
    });

    /** @param list<array<string, mixed>> $blocks */
    function railPage(array $blocks): string
    {
        StorefrontCards::reset();
        $siteId = StorefrontCards::site();
        app(Tenancy::class)->enter($siteId, fn () => StorefrontCards::page('/trang', 'Trang thử ạ', $blocks));

        return test()->get('http://mau.platform.test/trang')->assertOk()->getContent();
    }

    it('loads the slider script only for a page with a list that carries the group', function (): void {
        $new = railPage([['type' => 'team', 'props' => ['layout' => 'auto', 'members' => members(5)]]]);
        $old = railPage([['type' => 'team', 'props' => ['members' => members(5)]]]);

        expect($new)->toContain('<script type="module" src="/resources/js/sf-slider.js"></script>')->and($new)->toContain('data-sf-rail')
            ->and($old)->not->toContain('sf-slider.js')->and($old)->not->toContain('sf-rail');
    });
});

describe('the builder', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
    });

    it('writes "auto" for the whole group on a block added now and leaves a stored block alone', function (string $type): void {
        app(Tenancy::class)->enter(1, function () use ($type): void {
            $editor = User::factory()->create();
            grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);

            $component = Livewire::actingAs($editor->fresh())->test(Editor::class)->call('addBlock', $type);
            $props = $component->get('blocks')[0]['props'];

            foreach (array_keys(SectionProps::LIST_DISPLAY) as $prop) {
                expect($props[$prop])->toBe('auto');
            }

            $old = Livewire::actingAs($editor->fresh())->test(Editor::class)->set('blocks', [['type' => $type, 'props' => ['heading' => 'Khối cũ ạ']]]);
            expect($old->get('blocks')[0]['props'])->toBe(['heading' => 'Khối cũ ạ']);
        });
    })->with(['entry_grid', 'product_grid', 'partners', 'testimonial', 'team']);
});

describe('the builder saving a list block', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
    });

    /** @param list<array<string, mixed>> $blocks */
    function railEntry(User $editor, array $blocks): Entry
    {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Trang', 'slug' => 'trang', 'status' => 'draft', 'body' => ['blocks' => $blocks]]);

        return $entry;
    }

    it('stores the defaults of a block added now through a real save', function (string $type): void {
        app(Tenancy::class)->enter(1, function () use ($type): void {
            $editor = User::factory()->create();
            grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);
            $entry = railEntry($editor, []);

            Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $entry->ulid])
                ->call('addBlock', $type)->call('save')->assertHasNoErrors();

            $props = $entry->translationFor('vi')?->body['blocks'][0]['props'] ?? [];
            foreach (array_keys(SectionProps::LIST_DISPLAY) as $prop) {
                expect($props[$prop] ?? null)->toBe('auto');
            }
        });
    })->with(['entry_grid', 'product_grid', 'partners', 'testimonial', 'team']);

    it('pulls a stored limit outside 1 to 50 into range when the block loads, so saving again never fails', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $editor = User::factory()->create();
            grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);
            $entry = railEntry($editor, [
                ['type' => 'entry_grid', 'props' => ['limit' => 99]],
                ['type' => 'product_grid', 'props' => ['limit' => 0]],
                ['type' => 'product_grid', 'props' => ['limit' => 'abc']],
                ['type' => 'entry_grid', 'props' => ['limit' => '12']],
                ['type' => 'entry_grid', 'props' => ['heading' => 'Không có hạn mức ạ']],
            ]);

            $component = Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSet('blocks.0.props.limit', 50)->assertSet('blocks.1.props.limit', 1)->assertSet('blocks.2.props.limit', 6)
                ->assertSet('blocks.3.props.limit', 12);
            expect($component->get('blocks')[4]['props'])->toBe(['heading' => 'Không có hạn mức ạ']);

            $component->call('save')->assertHasNoErrors();
            $saved = $entry->translationFor('vi')?->body['blocks'] ?? [];
            expect(array_column(array_column($saved, 'props'), 'limit'))->toBe([50, 1, 6, 12]);
        });
    });
});
