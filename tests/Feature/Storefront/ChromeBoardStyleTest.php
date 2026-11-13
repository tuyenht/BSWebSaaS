<?php

use Tests\Fixtures\StorefrontCss;

/*
 * Style contracts of the shared chrome that no render can show: the phone bottom bar whose copy ends
 * the page without a light strip, the one-row branch chooser, and the footer cards that turn one at a time.
 * They read the stylesheet the pages load.
 */

function chromeCss(): string
{
    return (string) StorefrontCss::all();
}

it('hides a combo site’s round buttons and shows the bottom bar below 768px, its copy painted so no light strip shows', function (): void {
    $css = chromeCss();
    preg_match('~@media \(max-width: 767\.98px\) \{\n    /\* combo: the bar does the job below 768px \*/\n    \.sf-fc--combo \.sf-fc__float \{\n        display: none;\n    \}~s', $css, $block);
    preg_match('~    \.sf-fc-bar--ghost \{(.*?)\n    \}~s', $css, $ghost);

    expect($block)->not->toBeEmpty()
        ->and($ghost[1] ?? '')->toContain('position: static;')->toContain('background: var(--color-dark);')
        ->and($css)->toContain("    .sf-fc-bar--ghost .sf-fc-bar__list {\n        visibility: hidden;\n    }");
});

it('keeps the branch chooser in one row whatever the number of branches', function (): void {
    expect(chromeCss())->toContain("    grid-auto-flow: column;\n    grid-auto-columns: minmax(0, 1fr);");
});

it('draws the footer cards three across from 1024px and stacked in one cell while the chooser turns them', function (): void {
    $css = chromeCss();

    expect($css)->toContain("@media (min-width: 1024px) {\n    .sf-footer__factories {\n        grid-template-columns: repeat(3, minmax(0, 1fr));")
        ->toContain(".sf-footer__factories.is-rot > .sf-factory {\n    grid-area: 1 / 1;")
        ->toContain('.sf-footer__factories.is-rot > .sf-factory[aria-hidden="true"] {');
});
