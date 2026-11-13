<?php

/*
 * The storefront never lets the browser skip the layout of a block or the footer (content-visibility).
 * An accessibility checker (axe, inside Lighthouse and PageSpeed) sizes a block that is not drawn by
 * its estimate: a dark block taller than the estimate is taken for the background of the text below
 * it, and the contrast check fails for text that visitors see on a light background.
 */

it('draws every block and the footer at once', function (string $file): void {
    $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));

    expect($css)->not->toContain('content-visibility', basename($file))
        ->and($css)->not->toContain('contain-intrinsic-size', basename($file));
})->with(fn (): array => glob(dirname(__DIR__, 3).'/resources/css/{storefront,sf-*}.css', GLOB_BRACE) ?: []);

it('has no script left that turns the deferral off before a jump to a #target', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront.js');

    expect($js)->not->toContain('sf-anchored');
});
