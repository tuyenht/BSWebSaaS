<?php

/*
 * Block layout fit, read from the stylesheets: short closing lines keep company, the side share label
 * keeps one line, a post cover keeps the page's edges, the process and card call-to-action looks keep a
 * reading column, an about block reads text first on a phone, icon cards in a row line up, an overlay
 * caption stays readable on a white photo, partner logos stay large enough on a phone, and the
 * round-number process look draws "01" on a raised-dark disc.
 */

function blockFitCss(string $file): string
{
    return (string) file_get_contents(resource_path('css/'.$file));
}

/** The body of the first rule whose selector list is exactly $selector (outside any at-rule or inside one). */
function blockFitRule(string $css, string $selector): ?string
{
    return preg_match('/(?:^|[}\s])'.preg_quote($selector, '/').'\s*\{([^}]*)\}/m', $css, $m) === 1 ? $m[1] : null;
}

it('keeps the last line of a lead, a call to action or a slide text from holding one lone word', function (string $file, string $selector): void {
    $rule = blockFitRule(blockFitCss($file), $selector);

    expect($rule)->not->toBeNull()
        ->and($rule)->toContain('text-wrap: pretty');
})->with([
    'call to action' => ['sf-cta.css', '.cta__text'],
    'map meta' => ['sf-form.css', '.sf-bmap__meta'],
    'slide' => ['sf-slider.css', '.sf-slide__text'],
    'post excerpt' => ['sf-post.css', '.post-band__excerpt'],
    'page subtitle' => ['storefront.css', '.page-band__subtitle'],
    'section head' => ['storefront.css', '.sf-section-head__text'],
]);

it('keeps the side share label on one line, centred over the buttons', function (): void {
    $rule = (string) blockFitRule(blockFitCss('sf-post.css'), '.post-share--rail .post-share__label');

    expect($rule)->toContain('white-space: nowrap')->toContain('text-align: center');
});

it('gives a post cover the same side edges as the columns below it from 1024px', function (): void {
    $css = blockFitCss('sf-post.css');

    expect($css)->toMatch('/@media \(min-width: ?1024px\) ?\{\s*\.post-cover \{\s*padding-inline: 2rem;\s*\}/')
        ->and($css)->not->toContain('.post-page--capability .post-cover');
});

it('keeps the numeral process look and the card call to action in a 64rem column, with the width choice still applied', function (string $file, string $block): void {
    $css = blockFitCss($file);

    expect(blockFitRule($css, $block.' > .sf-container'))->toContain('max-width: 64rem')
        ->and(blockFitRule($css, '.sf-s--w-narrow '.$block.' > .sf-container'))->toContain('max-width: 48rem')
        ->and(blockFitRule($css, '.sf-s--w-full '.$block.' > .sf-container'))->toContain('max-width: none');
})->with([
    'process' => ['sf-blocks.css', '.sf-steps--numeral'],
    'call to action' => ['sf-cta.css', '.cta--card'],
]);

it('reads an about block text first on a phone, in the order of its markup', function (): void {
    // Only the letter look reorders (its own rule); the plain block keeps the markup's order.
    expect(blockFitCss('sf-about.css'))->not->toMatch('/^\s*\.about__body\s*\{[^}]*order:/m');
});

it('lines up the glyph and text of icon cards in one row through a subgrid', function (): void {
    $css = blockFitCss('sf-lists.css');

    expect($css)->toMatch('/@supports \(grid-template-rows: subgrid\) \{\s*\.sf-grid > \.sf-hcard \{[^}]*grid-row: span 2;[^}]*grid-template-rows: subgrid;/')
        ->and(blockFitRule($css, '.sf-grid > .sf-hcard > .sf-hcard__head'))->toContain('margin-bottom: 0');
});

it('draws an overlay caption on a fade that is 60% black within 1rem of its top edge', function (): void {
    expect((string) blockFitRule(blockFitCss('sf-media.css'), '.sf-gallery__caption--on'))
        ->toContain('linear-gradient(to top, rgb(0 0 0 / 0.8), rgb(0 0 0 / 0.6) calc(100% - 1rem), transparent)');
});

it('shows two partner logos at a time in a phone slider, not three', function (): void {
    expect(blockFitCss('sf-rail.css'))
        ->toMatch('/@media \(max-width: 639px\) \{[^@]*\.partners \.sf-rail__list--m-slider \{\s*--sf-c: 2;\s*\}/');
});

it('numbers the round-look process steps 01, 02 in the accent on a raised-dark disc', function (): void {
    $css = blockFitCss('sf-blocks.css');
    $before = (string) blockFitRule($css, '.sf-steps:not(.sf-steps--numeral) .sf-step::before');

    expect($before)->toContain('content: counter(sf-step, decimal-leading-zero)')
        ->toContain('background: var(--color-dark-raised')
        ->toContain('color: var(--color-primary-on-dark')
        ->toContain('width: 2.5rem')
        ->and($css)->toMatch('/@media \(min-width: 64rem\) \{[^@]*\.sf-steps:not\(\.sf-steps--numeral\) \.sf-step::before \{\s*width: 3rem;\s*height: 3rem;/')
        ->and($before)->not->toMatch('/#[0-9a-f]{3,8}\b/i');
});
