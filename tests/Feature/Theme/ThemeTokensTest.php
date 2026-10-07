<?php

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\PageRenderer;

/*
 * P1.6 — per-site customization over the SHARED master template (design.md §19):
 * every site renders the same theme Blade, but its own design tokens (config, not
 * code — ADR-04) are applied as CSS variables. Tokens are CSS-sanitized.
 */

beforeEach(fn () => seedTenancy());

function renderHome(int $site): string
{
    return app(Tenancy::class)->enter($site, fn () => app(PageRenderer::class)->render('page', null, 'X', ['blocks' => []]));
}

it('applies a site-specific design token over the shared template', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteSetting::create([
        'group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => '#ff0000'],
    ]));

    expect(renderHome(1))->toContain(':root{')->toContain('--primary:#ff0000');
});

it('falls back to default tokens when a site has none', function (): void {
    expect(renderHome(2))->toContain('--primary:#3b82f6');
});

it('isolates tokens per site', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteSetting::create([
        'group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => '#ff0000'],
    ]));

    expect(renderHome(1))->toContain('--primary:#ff0000');
    expect(renderHome(2))->toContain('--primary:#3b82f6')->not->toContain('#ff0000');
});

it('sanitizes a malicious token value (no CSS breakout)', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteSetting::create([
        'group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => 'red;}body{display:none'],
    ]));

    $html = renderHome(1);
    expect($html)->not->toContain('display:none')->toContain('--primary:#3b82f6'); // rejected → default
});

it('prints the standard token names next to the old ones storefront.css still reads', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteSetting::create([
        'group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => '#1e3a5f', 'surface' => '#fafafa', 'body' => '#222222'],
    ]));

    $html = renderHome(1);

    expect($html)->toContain('--primary:#1e3a5f;')->toContain('--color-primary:#1e3a5f;')
        ->toContain('--surface:#fafafa;')->toContain('--color-surface:#fafafa;')
        ->toContain('--body:#222222;')->toContain('--color-text:#222222;')
        ->toContain('--color-on-primary:#ffffff;') // white reads on this navy, the ink does not
        ->toMatch('/--primary-text:(#[0-9a-f]{6});.*--color-primary-text:\1;/')
        ->toMatch('/--primary-on-ink:(#[0-9a-f]{6});.*--color-primary-on-dark:\1;/')
        ->toMatch('/--brand-500:(#[0-9a-f]{6});.*--color-brand-500:\1;/');
});

it('prints the derived, readable brand text color next to the brand seed', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteSetting::create([
        'group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => '#f59e0b'],
    ]));

    $html = renderHome(1);

    expect($html)->toContain('--primary:#f59e0b')
        ->toMatch('/--primary-text:#[0-9a-f]{6};/')
        ->not->toContain('--primary-text:#f59e0b');
});
