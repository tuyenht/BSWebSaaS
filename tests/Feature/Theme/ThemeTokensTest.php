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
    expect(renderHome(2))->toContain('--primary:#0a66c2');
});

it('isolates tokens per site', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteSetting::create([
        'group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => '#ff0000'],
    ]));

    expect(renderHome(1))->toContain('--primary:#ff0000');
    expect(renderHome(2))->toContain('--primary:#0a66c2')->not->toContain('#ff0000');
});

it('sanitizes a malicious token value (no CSS breakout)', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteSetting::create([
        'group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => 'red;}body{display:none'],
    ]));

    $html = renderHome(1);
    expect($html)->not->toContain('display:none')->toContain('--primary:#0a66c2'); // rejected → default
});
