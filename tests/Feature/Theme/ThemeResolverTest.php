<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\ThemeResolver;

beforeEach(fn () => seedTenancy());

it('returns the six safe defaults when a site has no theme setting', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        expect(app(ThemeResolver::class)->tokens())->toBe([
            'primary' => '#0a66c2',
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

        expect(app(ThemeResolver::class)->tokens()['primary'])->toBe('#0a66c2');
    });
});

it('drops a token key that is not a plain CSS custom property name', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create([
            'group_name' => 'theme',
            'key' => 'tokens',
            'value' => ['primary; --evil' => '#000000'],
        ]);

        expect(app(ThemeResolver::class)->tokens())->toHaveKey('primary', '#0a66c2')
            ->not->toHaveKey('primary; --evil');
    });
});

it('falls back to safe defaults with no site context at all', function (): void {
    expect(app(ThemeResolver::class)->tokens())->toBe([
        'primary' => '#0a66c2',
        'surface' => '#ffffff',
        'text' => '#111111',
        'font' => 'system-ui, sans-serif',
        'ink' => '#111111',
        'body' => '#333333',
    ]);
});
