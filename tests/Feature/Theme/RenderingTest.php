<?php

use App\Modules\Theme\Rendering\BlockRenderer;
use App\Modules\Theme\Rendering\PageRenderer;
use App\Modules\Theme\Rendering\TemplateResolver;

/*
 * P1.2 — the theme renders a dynamic Page-JSON body to SSR HTML through an
 * allow-listed Section Library. No customer code runs (ADR-04); props are escaped.
 */

it('renders allow-listed blocks and skips unknown ones', function () {
    $html = app(BlockRenderer::class)->render(['blocks' => [
        ['type' => 'hero', 'props' => ['heading' => 'An Vinh', 'subheading' => 'Co khi']],
        ['type' => 'evil', 'props' => ['x' => 1]],   // not allow-listed → skipped
    ]]);

    expect($html)->toContain('An Vinh')->toContain('Co khi')->not->toContain('evil');
});

it('escapes block props (no stored XSS)', function () {
    $html = app(BlockRenderer::class)->render(['blocks' => [
        ['type' => 'hero', 'props' => ['heading' => '<script>alert(1)</script>']],
    ]]);

    expect($html)->not->toContain('<script>alert')->toContain('&lt;script&gt;');
});

it('renders a full SSR page with title + sections', function () {
    $html = app(PageRenderer::class)->render('page', null, 'Trang chu An Vinh', [
        'blocks' => [['type' => 'hero', 'props' => ['heading' => 'Xin chao']]],
    ], ['description' => 'Mo ta SEO']);

    expect($html)->toContain('<title>Trang chu An Vinh</title>')
        ->toContain('Xin chao')
        ->toContain('name="description" content="Mo ta SEO"');
});

it('resolves the layout via the override → type → theme chain', function () {
    $resolver = app(TemplateResolver::class);

    expect($resolver->layoutFor('page'))->toBe('theme::layouts.page')
        ->and($resolver->layoutFor('post'))->toBe('theme::layouts.post')
        ->and($resolver->layoutFor('page', 'landing'))->toBe('theme::layouts.landing');
});

it('renders a post on the post layout (title heading + blocks)', function () {
    $html = app(PageRenderer::class)->render('post', null, 'Bài 1', [
        'blocks' => [['type' => 'richtext', 'props' => ['heading' => 'Mở đầu', 'paragraphs' => ['Đoạn 1']]]],
    ], ['description' => 'SEO bài 1']);

    expect($html)->toContain('<title>Bài 1</title>')
        ->toContain('class="post__title">Bài 1')
        ->toContain('Mở đầu')
        ->toContain('Đoạn 1');
});

it('threads a per-category detail_template through to the layout', function () {
    // The category template ('page', an existing layout) must beat the 'post' type:
    // proves PageRenderer forwards $categoryTemplate to TemplateResolver::layoutFor.
    $html = app(PageRenderer::class)->render('post', null, 'X', [
        'blocks' => [['type' => 'hero', 'props' => ['heading' => 'Y']]],
    ], [], 'page');

    expect($html)->toContain('<main>')->not->toContain('class="post"');
});
