<?php

use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\StyleProps;
use App\Modules\Theme\Rendering\BlockRenderer;
use App\Modules\Theme\Theming\ReadableColor;
use App\Modules\Theme\Theming\ThemeResolver;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * The look of a section ("Trình bày"): one set of props shared by every block type, checked at
 * save like any other prop, read at render through fixed tables (a value that is not listed gives
 * no class), a wrapper only when a block sets a look (pages without one render as before), colour
 * sets that read well for any brand, and the motion script only where something moves.
 */

/** @param  array<string, mixed>  $props */
function styleBlockValid(array $props, string $type = 'richtext'): bool
{
    return Validator::make(['blocks' => [['type' => $type, 'props' => ['heading' => 'Một'] + $props]]], ['blocks' => [app(ValidPageJson::class)]])->passes();
}

/** @param  list<array<string, mixed>>  $blocks */
function styleRender(array $blocks): string
{
    return app(Tenancy::class)->enter(test()->siteId, fn (): string => app(BlockRenderer::class)->render(['blocks' => $blocks]));
}

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->siteId = StorefrontCards::site();
});

it('adds the look props to every block type and lets no block declare a reserved name itself', function (): void {
    $registry = app(ComponentRegistry::class);
    // The two photo blocks had their own `overlay` (how dark their photo is) first; it keeps that meaning. The cta declares `extra_style` for its pattern.
    $own = ['page_header' => ['overlay'], 'hero_slider' => ['overlay'], 'cta' => ['extra_style']];

    foreach ($registry->types() as $type) {
        expect(array_values(array_diff(array_intersect(array_keys($registry->get($type)->schema()), StyleProps::RESERVED), $own[$type] ?? [])))->toBe([], "{$type} declares a reserved look prop")
            ->and(array_keys($registry->schemaFor($type)))->toContain(...array_keys(StyleProps::SCHEMA));
    }

    expect(array_diff(array_keys(StyleProps::SCHEMA), StyleProps::RESERVED))->toBe([]);
});

it('accepts listed looks and refuses a value that is not listed or a malformed anchor', function (): void {
    expect(styleBlockValid(['bg' => 'scheme', 'scheme' => 'dark', 'spacing_top' => 'xl', 'width' => 'narrow', 'motion' => 'rise', 'anchor_id' => 'lien-he']))->toBeTrue()
        ->and(styleBlockValid(['bg' => 'tint']))->toBeTrue()
        ->and(styleBlockValid(['anchor_id' => '']))->toBeTrue()
        ->and(styleBlockValid(['scheme' => 'neon']))->toBeFalse()
        ->and(styleBlockValid(['overlay' => '40']))->toBeFalse()
        ->and(styleBlockValid(['motion' => 'spin']))->toBeFalse()
        ->and(styleBlockValid(['bg_image' => 'https://evil.test/x.png']))->toBeFalse()
        ->and(styleBlockValid(['anchor_id' => 'Liên hệ']))->toBeFalse()
        ->and(styleBlockValid(['anchor_id' => '"><script>']))->toBeFalse()
        ->and(styleBlockValid(['anchor_id' => str_repeat('a', 65)]))->toBeFalse()
        ->and(styleBlockValid(['anchor_id' => "top\n"]))->toBeFalse();
});

it('renders a block without a look exactly as before, with no wrapper', function (): void {
    $plain = styleRender([['type' => 'richtext', 'props' => ['heading' => 'Một', 'paragraphs' => ['Hai']]]]);

    expect($plain)->not->toContain('sf-s')
        ->and(styleRender([['type' => 'richtext', 'props' => ['heading' => 'Một', 'paragraphs' => ['Hai'], 'width' => 'contained', 'motion' => 'none']]]))->toBe($plain);
});

it('wraps a block that sets a look in classes from the fixed tables only', function (): void {
    $html = styleRender([
        ['type' => 'richtext', 'props' => ['heading' => 'Đầu']],
        ['type' => 'richtext', 'props' => ['heading' => 'Một', 'bg' => 'scheme', 'scheme' => 'dark', 'spacing_top' => 'l', 'spacing_bottom' => 'none', 'width' => 'narrow', 'align' => 'center', 'corner' => 'soft', 'shadow' => 'soft', 'hide_on' => 'mobile', 'motion' => 'rise', 'anchor_id' => 'lien-he']],
    ]);

    expect($html)->toContain('<div class="sf-s sf-s--sch-dark sf-s--pt-l sf-s--pb-none sf-s--w-narrow sf-s--a-center sf-s--corner-soft sf-s--shadow-soft sf-s--hide-mobile sf-s--m-rise" id="lien-he">');
});

it('gives a stored value that is not listed no class and never prints it', function (): void {
    $html = styleRender([
        ['type' => 'richtext', 'props' => ['heading' => 'Đầu']],
        ['type' => 'richtext', 'props' => ['heading' => 'Một', 'bg' => 'scheme', 'scheme' => 'x" onclick="a', 'width' => 'huge', 'motion' => 'spin', 'spacing_top' => '9px', 'anchor_id' => 'a b']],
    ]);

    expect($html)->toContain('<div class="sf-s sf-s--sch-light">')
        ->and($html)->not->toContain('onclick')->not->toContain('huge')->not->toContain('spin')->not->toContain('9px')->not->toContain('id="a b"');
});

it('never moves the first block, so the main content paints at once', function (): void {
    $html = styleRender([
        ['type' => 'richtext', 'props' => ['heading' => 'Một', 'motion' => 'fade']],
        ['type' => 'richtext', 'props' => ['heading' => 'Hai', 'motion' => 'fade']],
    ]);

    expect(substr_count($html, 'sf-s--m-fade'))->toBe(1)
        ->and(app(BlockRenderer::class)->moves(['blocks' => [['type' => 'richtext', 'props' => ['motion' => 'fade']]]]))->toBeFalse()
        ->and(app(BlockRenderer::class)->moves(['blocks' => [['type' => 'richtext'], ['type' => 'richtext', 'props' => ['motion' => 'rise']]]]))->toBeTrue();
});

it('paints a background photo as a sized image under an overlay, in the one media query of the page', function (): void {
    $media = app(Tenancy::class)->enter($this->siteId, fn () => [StorefrontCards::media('band', 1600, 900), StorefrontCards::media('logo', 320, 160)]);

    DB::enableQueryLog();
    $html = styleRender([
        ['type' => 'hero', 'props' => ['heading' => 'Một', 'bg' => 'image', 'bg_image' => $media[0]->id, 'overlay' => '75']],
        ['type' => 'about', 'props' => ['heading' => 'Hai', 'image_url' => $media[1]->id]],
    ]);
    $mediaQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], '"media"'))->count();
    DB::disableQueryLog();

    expect($html)->toContain('<div class="sf-s sf-s--image sf-s--ov-75">')
        ->and($html)->toMatch('#<div class="sf-s__bg" aria-hidden="true">\s*<img class="sf-s__img" src="https://files\.test/s/library/band-[0-9a-f]{12}\.webp\?w=1280" srcset="[^"]+" sizes="100vw" alt="" width="1600" height="900" loading="eager" fetchpriority="high">#')
        ->and($mediaQueries)->toBe(1);
});

it('falls back to the colour set when the background photo is missing', function (): void {
    $html = styleRender([['type' => 'richtext', 'props' => ['heading' => 'Một', 'bg' => 'image', 'bg_image' => 999999]]]);

    expect($html)->not->toContain('sf-s__bg')->not->toContain('sf-s--image');
});

it('fits every colour set to the brand: text 4.5:1 and the button fill 3:1 on its band, hovered too', function (string $primary, ?string $ink): void {
    $variables = app(Tenancy::class)->enter($this->siteId, function () use ($primary, $ink): array {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => $primary] + ($ink === null ? [] : ['ink' => $ink])]);

        return app(ThemeResolver::class)->cssVariables();
    });

    foreach (['soft', 'brand', 'dark'] as $set) {
        $bg = $variables["sch-{$set}-bg"];
        foreach (['head', 'body', 'link', 'sec-fg'] as $role) {
            expect(ReadableColor::ratio($variables["sch-{$set}-{$role}"], $bg))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT, "{$set} {$role} on {$primary}");
        }
        expect(ReadableColor::ratio($variables["sch-{$set}-pri-bg"], $bg))->toBeGreaterThanOrEqual(3.0, "{$set} button on {$primary}")
            ->and(ReadableColor::ratio($variables["sch-{$set}-pri-fg"], $variables["sch-{$set}-pri-bg"]))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT, "{$set} button text on {$primary}")
            ->and(ReadableColor::ratio($variables["sch-{$set}-pri-fg"], $variables["sch-{$set}-pri-hover"]))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT, "{$set} hovered button text on {$primary}");
    }
    // The light set is the page itself: the brand fill as the site chose it, at rest and hovered.
    expect($variables['color-primary'])->toBe($primary)
        ->and(ReadableColor::ratio($variables['color-on-primary'], $variables['color-primary']))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT, "light button text on {$primary}")
        ->and(ReadableColor::ratio($variables['color-on-primary'], $variables['color-primary-hover']))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT, "light hovered button text on {$primary}")
        ->and($variables['color-primary-hover'])->not->toBe($primary);
})->with([
    'blue' => ['#3b82f6', null], 'amber' => ['#f59e0b', null], 'navy' => ['#0b1f4d', null], 'green' => ['#16a34a', null],
    'yellow' => ['#ffd400', null], 'red' => ['#ef4444', null], 'red, slate ink' => ['#ef4444', '#1f2937'],
    'grey, slate ink' => ['#808080', '#1f2937'], 'teal, slate ink' => ['#0d9488', '#1f2937'],
    'mid grey' => ['#7a7a7a', null], 'mid green, slate ink' => ['#2f9e6e', '#1f2937'],
]);

it('loads the motion script only on a page where a block after the first moves', function (): void {
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
        }
    });
    app(Tenancy::class)->enter($this->siteId, function (): void {
        StorefrontCards::page('/dong', 'Động', [['type' => 'richtext', 'props' => ['heading' => 'Một']], ['type' => 'richtext', 'props' => ['heading' => 'Hai', 'motion' => 'rise']]]);
        StorefrontCards::page('/tinh', 'Tĩnh', [['type' => 'richtext', 'props' => ['heading' => 'Một', 'motion' => 'rise']]]);
    });

    expect(test()->get('http://mau.platform.test/dong')->assertOk()->getContent())->toContain('src="/resources/js/sections.js"')
        ->and(test()->get('http://mau.platform.test/tinh')->assertOk()->getContent())->not->toContain('sections.js');
});

it('pads the block itself, never the photo layer behind it', function (): void {
    $css = (string) file_get_contents(resource_path('css/storefront.css'));

    expect($css)->toContain('.sf-s--pt-l > :nth-child(1 of :not(.sf-s__bg))')
        ->and($css)->toContain('.sf-s--pb-l > :nth-last-child(1 of :not(.sf-s__bg))')
        ->and($css)->not->toMatch('/\.sf-s--p[tb]-[a-z]+ > :(first|last)-child/');
});

it('derives the colour-set roles itself: a stored role never recolours the blocks', function (): void {
    $variables = app(Tenancy::class)->enter($this->siteId, function (): array {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['sch-head' => '#eeeeee', 'sch-rule' => '#eeeeee', 'sch-dark-bg' => '#eeeeee']]);

        return app(ThemeResolver::class)->cssVariables();
    });

    expect($variables)->not->toHaveKey('sch-head')->not->toHaveKey('sch-rule')
        ->and($variables['sch-dark-bg'])->toBe('#111111');
});
