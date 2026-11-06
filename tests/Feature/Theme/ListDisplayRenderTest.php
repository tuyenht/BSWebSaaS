<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Catalog\Components\ProductGridComponent;
use App\Modules\Cms\Components\EntryGridComponent;
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

    expect($html)->toContain('data-sf-rail')->and($html)->toContain('role="group" aria-roledescription="carousel"')
        ->and($html)->toContain('<div class="sf-rail__controls" hidden>')
        ->and($html)->toContain($class)
        ->and($html)->toContain('data-sf-rail-prev hidden aria-label="Ảnh trước"')
        ->and($html)->toContain('data-sf-rail-next hidden aria-label="Ảnh tiếp theo"');
})->with([
    'partners' => ['partners', ['logos' => logos(8)], 'sf-rail__list sf-rail__list--slider sf-rail__list--c6 sf-rail__list--m-swipe'],
    'testimonial' => ['testimonial', ['items' => quotes(5)], 'sf-rail__list sf-rail__list--slider sf-rail__list--c3'],
    'team' => ['team', ['members' => members(7)], 'sf-rail__list sf-rail__list--grid sf-rail__list--c4 sf-rail__list--m-swipe'],
]);

it('gives a rotating rail a Pause button with both labels, and a still one none', function (): void {
    $rotating = railView('partners', ['props' => ['layout' => 'auto', 'logos' => logos(8)]]);
    $still = railView('team', ['props' => ['layout' => 'auto', 'members' => members(7)]]);
    $off = railView('partners', ['props' => ['layout' => 'auto', 'autoplay' => 'off', 'logos' => logos(8)]]);

    expect($rotating)->toContain('data-sf-rail-pause hidden aria-label="Dừng tự chuyển ảnh"')
        ->and($rotating)->toContain('data-label-play="Tự chuyển ảnh tiếp"')
        ->and($rotating)->toContain('data-interval="3000"')
        ->and($still)->not->toContain('data-sf-rail-pause')->and($still)->toContain('data-interval="0"')
        ->and($off)->not->toContain('data-sf-rail-pause');
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
        ->and($js)->toContain("addEventListener('mouseenter'")->and($js)->toContain("addEventListener('focusin'")
        ->and($js)->toContain("addEventListener('pointerdown'")->and($js)->toContain('const HOLD = 10000;')
        ->and($js)->toContain('dataset.labelPause');
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
