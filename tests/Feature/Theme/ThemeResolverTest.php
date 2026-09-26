<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\BrandPalette;
use App\Modules\Theme\Theming\ReadableColor;
use App\Modules\Theme\Theming\ThemeResolver;

beforeEach(fn () => seedTenancy());

it('returns the six safe defaults when a site has no theme setting', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        expect(app(ThemeResolver::class)->tokens())->toBe([
            'primary' => '#3b82f6',
            'surface' => '#ffffff',
            'text' => '#111111',
            'font' => 'system-ui, sans-serif',
            'ink' => '#111111',
            'body' => '#333333',
        ]);
    });
});

it('overrides only the keys a site actually customized', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            'value' => ['primary' => '#f59e0b', 'ink' => '#0f172a'],
        ]);

        $tokens = app(ThemeResolver::class)->tokens();

        expect($tokens['primary'])->toBe('#f59e0b')
            ->and($tokens['ink'])->toBe('#0f172a')
            // Untouched keys keep the default — a partial override is not a reset.
            ->and($tokens['surface'])->toBe('#ffffff')
            ->and($tokens['body'])->toBe('#333333');
    });
});

it('drops a token value that could break out of the CSS custom property', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            // `}` would close the :root block; the rest would land as raw CSS/JS.
            'value' => ['primary' => '#fff;}body{background:url(javascript:alert(1))'],
        ]);

        expect(app(ThemeResolver::class)->tokens()['primary'])->toBe('#3b82f6');
    });
});

it('drops a token key that is not a plain CSS custom property name', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            'value' => ['primary; --evil' => '#000000'],
        ]);

        expect(app(ThemeResolver::class)->tokens())->toHaveKey('primary', '#3b82f6')
            ->not->toHaveKey('primary; --evil');
    });
});

it('falls back to safe defaults with no site context at all', function (): void {
    expect(app(ThemeResolver::class)->tokens())->toBe([
        'primary' => '#3b82f6',
        'surface' => '#ffffff',
        'text' => '#111111',
        'font' => 'system-ui, sans-serif',
        'ink' => '#111111',
        'body' => '#333333',
    ]);
});

it('prints the seeds plus a brand text color that stays readable on the surface', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            // A light brand, and an attempt to pin the derived role from settings.
            'value' => ['primary' => '#f59e0b', 'primary-text' => '#f59e0b'],
        ]);

        $variables = app(ThemeResolver::class)->cssVariables();

        expect($variables['primary'])->toBe('#f59e0b') // fills and borders keep the brand as chosen
            ->and($variables['primary-text'])->not->toBe('#f59e0b') // the role is always derived
            ->and(ReadableColor::ratio($variables['primary-text'], $variables['surface']))
            ->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
});

it('keeps the brand text color readable on the page background too, where breadcrumb and contact details sit', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            'value' => ['primary' => '#f59e0b', 'ink' => '#0f172a'],
        ]);

        $variables = app(ThemeResolver::class)->cssVariables();
        $page = (string) ReadableColor::mix($variables['surface'], $variables['ink'], ThemeResolver::PAGE_SURFACE_SHARE);

        expect($page)->toBe('#f8f8f9')
            ->and(ReadableColor::ratio($variables['primary-text'], $variables['surface']))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
            ->and(ReadableColor::ratio($variables['primary-text'], $page))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
});

it('lifts a dark brand to read as text on the dark ink surface (footer, hero, sub-menu)', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        // A dark brand: its own primary fails on the dark ink, so primary-on-ink must lighten it.
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            'value' => ['primary' => '#1e3a5f', 'ink' => '#0f172a'],
        ]);

        $variables = app(ThemeResolver::class)->cssVariables();

        expect(ReadableColor::ratio($variables['primary'], $variables['ink']))->toBeLessThan(ReadableColor::AA_NORMAL_TEXT)
            ->and($variables['primary-on-ink'])->not->toBe($variables['primary'])
            ->and(ReadableColor::ratio($variables['primary-on-ink'], $variables['ink']))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
});

it('leaves a light brand unchanged on the ink surface', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            'value' => ['primary' => '#f59e0b', 'ink' => '#0f172a'],
        ]);

        $variables = app(ThemeResolver::class)->cssVariables();

        // Amber already clears 4.5 on the dark ink, so on-ink stays the seed.
        expect($variables['primary-on-ink'])->toBe('#f59e0b')
            ->and(ReadableColor::ratio($variables['primary-on-ink'], $variables['ink']))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
});

it('measures the page background the storefront stylesheet actually paints', function (): void {
    $share = (int) round(ThemeResolver::PAGE_SURFACE_SHARE * 100);

    expect((string) file_get_contents(resource_path('css/storefront.css')))
        ->toMatch("/body \\{[^}]*background: color-mix\\(in srgb, var\\(--surface\\) {$share}%, var\\(--ink\\)\\);/");
});

it('falls back to the ink color when the brand seed is not a hex color', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => 'rebeccapurple']]);

        expect(app(ThemeResolver::class)->cssVariables()['primary-text'])->toBe('var(--ink)');
    });
});

it('prints the ten-step brand palette derived from the brand seed, never read from settings', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => '#f59e0b', 'brand-500' => '#000000']]);

        $variables = app(ThemeResolver::class)->cssVariables();

        foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as $step) {
            expect($variables)->toHaveKey("brand-{$step}")
                ->and($variables["brand-{$step}"])->toBe(BrandPalette::from('#f59e0b')[$step]);
        }
        expect($variables['brand-500'])->not->toBe('#000000');
    });
});

it('prints no brand palette when the brand seed is not a hex color', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => 'rebeccapurple']]);

        expect(array_filter(array_keys(app(ThemeResolver::class)->cssVariables()), fn (string $key): bool => str_starts_with($key, 'brand-')))->toBe([]);
    });
});
