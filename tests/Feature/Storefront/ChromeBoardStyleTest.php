<?php

/*
 * Style contracts of the shared chrome that no render can show: the dark phone bar that ends the page
 * without a light strip, the one-row branch chooser, and the footer cards that turn one at a time.
 * They read the stylesheet the pages load.
 */

function chromeCss(): string
{
    return (string) file_get_contents(resource_path('css/storefront.css'));
}

it('hides the round buttons and shows a dark phone bar below 768px, its copy painted so no light strip shows', function (): void {
    $css = chromeCss();
    preg_match('~@media \(max-width: 767\.98px\) \{\n    \.sf-fc--md \{(.*?)\n\}~s', $css, $block);

    expect($css)->toContain("    .sf-fc--md {\n        display: none;\n    }")
        ->toContain("    .sf-fc-bar--board.sf-fc-bar--ghost {\n        visibility: visible;\n    }")
        ->toContain("    .sf-fc-bar--board.sf-fc-bar--ghost .sf-fc-bar__list {\n        visibility: hidden;\n    }")
        ->toContain('background: var(--color-dark);')
        ->and($block)->not->toBeEmpty();
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
