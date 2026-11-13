<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\BrandPalette;
use App\Modules\Theme\Theming\ReadableColor;
use App\Modules\Theme\Theming\ThemeResolver;
use Tests\Fixtures\StorefrontCss;

beforeEach(fn () => seedTenancy());

it('returns the six safe defaults when a site has no theme setting', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        expect(app(ThemeResolver::class)->tokens())->toBe([
            'primary' => '#3b82f6',
            'surface' => '#ffffff',
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

it('keeps brand text readable on the footer card as well as on the ink (factory area, chip, map hover)', function (): void {
    // The default blue reads 5.1:1 on the ink but 3.7:1 on the raised card (88% ink + white) the
    // factory cards are drawn on; axe flagged the card's map button in its hover color.
    $default = app(Tenancy::class)->enter(1, fn (): array => app(ThemeResolver::class)->cssVariables());
    $card = ReadableColor::mix($default['ink'], '#ffffff', 0.88);

    expect($card)->not->toBeNull()
        ->and(ReadableColor::ratio($default['primary'], (string) $card))->toBeLessThan(ReadableColor::AA_NORMAL_TEXT)
        ->and(ReadableColor::ratio($default['color-primary-on-dark'], (string) $card))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
        ->and(ReadableColor::ratio($default['color-primary-on-dark'], $default['ink']))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);

    // A site's own card color is the one measured; a value that is not a hex color is left out.
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            'value' => ['primary' => '#2563eb', 'ink' => '#0f172a', 'color-dark-raised' => '#334155'],
        ]);
    });
    $own = app(Tenancy::class)->enter(1, fn (): array => app(ThemeResolver::class)->cssVariables());
    expect(ReadableColor::ratio($own['color-primary-on-dark'], '#334155'))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);

    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->where('group_name', 'theme')->where('key', 'tokens')->firstOrFail()
            ->update(['value' => ['primary' => '#2563eb', 'ink' => '#0f172a', 'color-dark-raised' => 'var(--x)']]);
    });
    $unreadable = app(Tenancy::class)->enter(1, fn (): array => app(ThemeResolver::class)->cssVariables());
    expect($unreadable['color-primary-on-dark'])->toBe(ReadableColor::onAll('#2563eb', ['#0f172a']) ?? $unreadable['surface']);
});

it('draws brand text on the dark footer in the color derived for it, never the raw brand', function (): void {
    $css = (string) file_get_contents(resource_path('css/storefront.css'));

    foreach (['.sf-footer__heading a:hover', '.sf-factory__map:focus-visible', '.sf-factory__chip', '.sf-factory__area', '.sf-footer__bottom a:hover strong'] as $selector) {
        $start = strpos($css, $selector.' {');
        expect($start)->not->toBeFalse();
        $rule = substr($css, (int) $start, (int) strpos($css, '}', (int) $start) - (int) $start);
        expect($rule)->toContain('color: var(--color-primary-on-dark);')
            ->and($rule)->not->toMatch('/(?<!-)color: var\(--color-primary\);/');
    }
    expect($css)->toContain('background: var(--color-dark-line, var(--color-dark));');
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

    expect((string) StorefrontCss::all())
        ->toMatch("/body \\{[^}]*background: var\\(--page, color-mix\\(in srgb, var\\(--color-surface\\) {$share}%, var\\(--color-dark\\)\\)\\);/");
});

it('measures the brand text against the site’s own page colour when it sets one', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            'value' => ['primary' => '#f59e0b', 'ink' => '#0f172a', 'page' => '#e2e8f0'],
        ]);

        $variables = app(ThemeResolver::class)->cssVariables();

        expect($variables['page'])->toBe('#e2e8f0')
            ->and(ReadableColor::ratio($variables['primary-text'], '#e2e8f0'))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
            ->and(ReadableColor::ratio($variables['primary-text'], $variables['surface']))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
});

it('prints exactly the same variables as before for a site with no settings', function (): void {
    $variables = app(Tenancy::class)->enter(1, fn (): array => app(ThemeResolver::class)->cssVariables());

    $expected = [
        'primary' => '#3b82f6', 'surface' => '#ffffff', 'font' => 'system-ui, sans-serif',
        'ink' => '#111111', 'body' => '#333333', 'primary-text' => '#326fd1', 'primary-on-ink' => '#5895f7',
        'color-primary' => '#3b82f6', 'color-on-primary' => '#031c44', 'color-primary-hover' => '#5895f7', 'color-primary-text' => '#326fd1',
        'color-primary-on-dark' => '#5895f7', 'color-surface' => '#ffffff', 'color-text' => '#333333', 'color-text-secondary' => '#5c5c5c',
        'color-text-strong' => '#111111', 'color-dark' => '#111111', 'font-body' => 'system-ui, sans-serif',
    ];
    $palette = ['50' => '#f0f6ff', '100' => '#deebff', '200' => '#c4dbff', '300' => '#9cc2ff', '400' => '#659fff',
        '500' => '#3b82f6', '600' => '#226adb', '700' => '#1b5abd', '800' => '#12499e', '900' => '#103e86'];
    foreach ($palette as $step => $color) {
        $expected["brand-{$step}"] = $color;
        $expected["color-brand-{$step}"] = $color;
    }
    $expected += [
        'space-unit' => '1', 'space-s' => '8px', 'space-m' => '16px', 'space-l' => '32px', 'space-xl' => '64px',
        'radius-soft' => '16px', 'radius-round' => '9999px',
        'shadow-soft' => '0 16px 36px -18px color-mix(in srgb, var(--color-dark) 35%, transparent)',
        'shadow-medium' => '0 6px 16px color-mix(in srgb, var(--color-dark) 16%, transparent)',
        'gradient-1' => 'linear-gradient(135deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 55%, var(--color-dark)))',
        'gradient-2' => 'linear-gradient(135deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 55%, white))',
        'gradient-3' => 'linear-gradient(135deg, var(--color-dark), var(--color-primary))',
        'motion-duration' => '250ms', 'motion-distance' => '24px',
        'sch-soft-bg' => '#f0f6ff', 'sch-soft-head' => '#111111', 'sch-soft-body' => '#333333', 'sch-soft-link' => '#2f68c5',
        'sch-soft-pri-bg' => '#3b82f6', 'sch-soft-pri-fg' => '#111111', 'sch-soft-sec-fg' => '#111111', 'sch-soft-pri-hover' => '#5895f7',
        'sch-brand-bg' => '#3b82f6', 'sch-brand-head' => '#111111', 'sch-brand-body' => '#111111', 'sch-brand-link' => '#111111',
        'sch-brand-pri-bg' => '#ffffff', 'sch-brand-pri-fg' => '#326fd1', 'sch-brand-sec-fg' => '#111111', 'sch-brand-pri-hover' => '#f7f7f7',
        'sch-dark-bg' => '#111111', 'sch-dark-head' => '#ffffff', 'sch-dark-body' => '#cbcbcb', 'sch-dark-link' => '#3b82f6',
        'sch-dark-pri-bg' => '#3b82f6', 'sch-dark-pri-fg' => '#111111', 'sch-dark-sec-fg' => '#ffffff', 'sch-dark-pri-hover' => '#5895f7',
    ];

    expect($variables)->toBe($expected);
});

it('accepts the optional size and dark-scale tokens but not one that breaks out of its value', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            'value' => ['text-base' => '1.0625rem', 'color-dark-raised' => '#1e293b', 'color-on-dark' => '17px;}'],
        ]);

        $tokens = app(ThemeResolver::class)->tokens();

        expect($tokens['text-base'])->toBe('1.0625rem')
            ->and($tokens['color-dark-raised'])->toBe('#1e293b')
            ->and($tokens)->not->toHaveKey('color-on-dark');
    });
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

        expect(array_filter(array_keys(app(ThemeResolver::class)->cssVariables()), fn (string $key): bool => str_starts_with($key, 'brand-') || str_starts_with($key, 'color-brand-')))->toBe([]);
    });
});

/*
 * docs/notes/design-tokens.md §7: during the rename the storefront prints the standard names AND
 * the old ones storefront.css still reads, each pair with the very same value.
 */
dataset('brand seeds', [
    'the defaults' => [[]],
    'a light brand on a dark ink' => [['primary' => '#f59e0b', 'ink' => '#0f172a', 'body' => '#334155']],
    'a dark brand' => [['primary' => '#1e3a5f', 'surface' => '#fafafa']],
]);

it('prints every standard token name next to its old name, with the same value', function (array $seeds): void {
    app(Tenancy::class)->enter(1, function () use ($seeds): void {
        if ($seeds !== []) {
            SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => $seeds]);
        }

        $variables = app(ThemeResolver::class)->cssVariables();

        expect($variables['color-primary'])->toBe($variables['primary'])
            ->and($variables['color-primary-text'])->toBe($variables['primary-text'])
            ->and($variables['color-primary-on-dark'])->toBe($variables['primary-on-ink'])
            ->and($variables['color-surface'])->toBe($variables['surface'])
            ->and($variables['color-text'])->toBe($variables['body'])
            ->and($variables['color-dark'])->toBe($variables['ink'])
            ->and($variables['color-text-strong'])->toBe($variables['ink'])
            ->and($variables['font-body'])->toBe($variables['font']);

        foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as $step) {
            expect($variables["color-brand-{$step}"])->toBe($variables["brand-{$step}"]);
        }
    });
})->with('brand seeds');

it('puts white on a brand fill it reads on, else a dark shade of the brand hue, and never moves the fill', function (string $primary, ?string $expected): void {
    app(Tenancy::class)->enter(1, function () use ($primary, $expected): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => $primary, 'ink' => '#111111']]);

        $variables = app(ThemeResolver::class)->cssVariables();

        expect($variables['color-on-primary'])->when($expected !== null, fn ($e) => $e->toBe($expected))
            ->and($variables['color-primary'])->toBe($primary)
            ->and(ReadableColor::ratio($variables['color-on-primary'], $primary))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
})->with([
    'the default blue, where white reads 3.7:1, takes a deep blue' => ['#3b82f6', null],
    'an amber brand takes a deep brown' => ['#f59e0b', null],
    'a dark blue brand takes white' => ['#1e3a8a', '#ffffff'],
    'a navy brand takes white' => ['#1e3a5f', '#ffffff'],
    'a mid-tone grey brand takes a deep grey' => ['#7a7a7a', null],
]);

it('derives the standard names even when settings try to pin them', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => [
            'primary' => '#f59e0b',
            'color-primary-text' => '#f59e0b',
            'color-on-primary' => '#ffffff',
            'color-brand-500' => '#000000',
        ]]);

        $variables = app(ThemeResolver::class)->cssVariables();

        expect($variables['color-primary-text'])->toBe($variables['primary-text'])->not->toBe('#f59e0b')
            ->and($variables['color-on-primary'])->toBe('#452c03')
            ->and($variables['color-brand-500'])->toBe(BrandPalette::from('#f59e0b')[500]);
    });
});

it('falls back to the ink and surface colours themselves when the brand seed is not a hex color', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => 'rebeccapurple', 'ink' => '#0f172a']]);

        $variables = app(ThemeResolver::class)->cssVariables();

        // The old names keep var(--ink) / var(--surface); the new ones carry the same colour without
        // pointing back at an old name.
        expect($variables['primary-text'])->toBe('var(--ink)')
            ->and($variables['color-primary-text'])->toBe('#0f172a')
            ->and($variables['primary-on-ink'])->toBe('var(--surface)')
            ->and($variables['color-primary-on-dark'])->toBe('#ffffff')
            ->and($variables['color-on-primary'])->toBe('#0f172a');
    });
});

it('reads only roles in the storefront stylesheet, never a seed', function (): void {
    $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) StorefrontCss::all());

    expect(preg_match_all('/var\(--(?:primary|primary-text|primary-on-ink|ink|body|surface|font)[,)]/', $css, $seeds))->toBe(0, implode(', ', $seeds[0]));
});

it('rounds every spacing step to the 4px grid at each density', function (string $density, array $expected, string $unit): void {
    app(Tenancy::class)->enter(1, function () use ($density, $expected, $unit): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['density' => $density]]);

        $variables = app(ThemeResolver::class)->cssVariables();

        expect($variables['space-unit'])->toBe($unit);
        foreach ($expected as $step => $px) {
            expect($variables["space-{$step}"])->toBe("{$px}px")
                ->and($px % ThemeResolver::SPACE_GRID)->toBe(0);
        }
    });
})->with([
    'compact' => ['compact', ['s' => 8, 'm' => 12, 'l' => 28, 'xl' => 56], '0.85'],
    'normal' => ['normal', ['s' => 8, 'm' => 16, 'l' => 32, 'xl' => 64], '1'],
    'airy' => ['airy', ['s' => 8, 'm' => 20, 'l' => 40, 'xl' => 76], '1.2'],
    'an unknown value means normal' => ['huge', ['s' => 8, 'm' => 16, 'l' => 32, 'xl' => 64], '1'],
]);

it('derives the scales even when settings try to pin them, and keeps motion within 250ms', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['space-m' => '99px', 'motion-duration' => '2s']]);

        $variables = app(ThemeResolver::class)->cssVariables();

        expect($variables['space-m'])->toBe('16px')
            ->and($variables['motion-duration'])->toBe('250ms');
    });
});

it('derives a deep shade of the brand hue for text on a light brand, readable on it and under its hover fill', function (string $primary, float $hue): void {
    app(Tenancy::class)->enter(1, function () use ($primary, $hue): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => $primary, 'ink' => '#111111']]);

        $variables = app(ThemeResolver::class)->cssVariables();
        $text = $variables['color-on-primary'];
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($text, '#'), 2));
        $max = max($r, $g, $b);
        $delta = $max - min($r, $g, $b);
        $shadeHue = fmod(60 * ($max === $r ? ($g - $b) / $delta : ($max === $g ? ($b - $r) / $delta + 2 : ($r - $g) / $delta + 4)) + 360, 360);

        expect($text)->not->toBe('#ffffff')->not->toBe('#111111')
            ->and($shadeHue)->toBeGreaterThan($hue - 12)->toBeLessThan($hue + 12)
            ->and(ReadableColor::ratio($text, $primary))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
            ->and(ReadableColor::ratio($text, $variables['color-primary-hover']))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
})->with([
    'amber' => ['#f59e0b', 38.0],
    'default blue' => ['#3b82f6', 217.0],
    'a mid green' => ['#22c55e', 142.0],
]);

it('keeps white on a dark brand and its hover fill still reads', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => '#1e3a8a', 'ink' => '#111111']]);

        $variables = app(ThemeResolver::class)->cssVariables();

        expect($variables['color-on-primary'])->toBe('#ffffff')
            ->and(ReadableColor::ratio($variables['color-on-primary'], $variables['color-primary-hover']))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
});

it('makes secondary text readable on the surface, the page and the tinted band, whatever the body color', function (string $body): void {
    app(Tenancy::class)->enter(1, function () use ($body): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'theme', 'key' => 'tokens'],
            ['value' => ['primary' => '#f59e0b', 'ink' => '#0f172a', 'body' => $body]],
        );

        $variables = app(ThemeResolver::class)->cssVariables();
        $page = (string) ReadableColor::mix($variables['surface'], $variables['ink'], ThemeResolver::PAGE_SURFACE_SHARE);
        $tint = (string) ReadableColor::mix($variables['surface'], $variables['ink'], ThemeResolver::TINT_SURFACE_SHARE);
        $secondary = $variables['color-text-secondary'];

        foreach ([$variables['surface'], $page, $tint, '#f8fafc'] as $background) {
            expect(ReadableColor::ratio($secondary, $background))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
        }
        // Still a step lighter than body copy where the body color leaves room for one.
        expect($secondary)->not->toBe($variables['body']);
    });
})->with([
    'a light body color, as on An Vinh (lightened a fifth it reads 4.3:1 on the page)' => ['#4b5563'],
    'the default body color' => ['#333333'],
    'a dark slate body color' => ['#334155'],
]);

it('paints no secondary text by lightening the body color in the stylesheet', function (): void {
    $css = dirname(__DIR__, 3).'/resources/css';
    foreach ([$css.'/storefront.css', ...glob($css.'/sf-*.css') ?: []] as $file) {
        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $number => $line) {
            if (preg_match('/^\s*color:/', $line) === 1) {
                expect($line)->not->toMatch('/color-mix\(in srgb, var\(--color-text\) \d+%, white\)/', basename($file).':'.($number + 1));
            }
        }
    }
});

it('reads secondary text against a tint the site set itself', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'theme', 'key' => 'tokens'],
            ['value' => ['ink' => '#0f172a', 'body' => '#4b5563', 'color-surface-tint' => '#e2e8f0', 'color-text-secondary' => '#eeeeee']],
        );
        $variables = app(ThemeResolver::class)->cssVariables();

        // A stored value never wins over the derived one.
        expect($variables['color-text-secondary'])->not->toBe('#eeeeee')
            ->and(ReadableColor::ratio($variables['color-text-secondary'], '#e2e8f0'))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
});

it('falls back to the body color when it cannot read the body color it would lighten', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'theme', 'key' => 'tokens'], ['value' => ['body' => 'slategray']]);

        expect(app(ThemeResolver::class)->cssVariables()['color-text-secondary'])->toBe('slategray');
    });
});

it('reads secondary text on a form field even when the site paints its page and tint lighter than the field', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'theme', 'key' => 'tokens'],
            ['value' => ['ink' => '#0f172a', 'body' => '#4b5563', 'page' => '#ffffff', 'color-surface-tint' => '#ffffff']],
        );
        $variables = app(ThemeResolver::class)->cssVariables();
        $field = (string) ReadableColor::mix($variables['surface'], '#0f172a', 0.97);

        expect(ReadableColor::ratio($variables['color-text-secondary'], $field))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    });
});
