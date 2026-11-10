<?php

/*
 * The text rules of formatted text and of a post body reach only the written text. A post body
 * (`.sf-prose--article`) also holds whole blocks — a video, an FAQ, a gallery, a call to action —
 * which keep their own look: no bullets or indent on a gallery list, no second rule under a block
 * heading, no underline on a button. Pinned on the stylesheet, since the markup does not change.
 */

it('keeps the formatted-text rules off a post body: they apply inside formatted text only', function (): void {
    $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(resource_path('css/storefront.css')));

    // ".sf-prose x" / ".sf-prose > x" written bare would also reach the blocks in a post body.
    expect(preg_match_all('~(?<![\w-])\.sf-prose(?![\w:-])(?:\s+|\s*>\s*)(?![{,\s])[^{,]*~', $css, $bare))->toBe(0, 'bare .sf-prose descendant rule: '.implode(' | ', $bare[0]))
        ->and(substr_count($css, '.sf-prose:not(.sf-prose--article) '))->toBeGreaterThan(20);
});

it('styles only the written text of a post body, and the blocks in it as the article’s own', function (): void {
    $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(resource_path('css/storefront.css')));
    preg_match_all('~(?<![\w(-])\.sf-prose--article\b([^{]*)\{~', $css, $m);

    foreach ($m[1] as $rest) {
        foreach (preg_split('~,(?![^(]*\))~', '.sf-prose--article'.$rest) ?: [] as $selector) {
            $selector = trim($selector);
            if (! str_starts_with($selector, '.sf-prose--article') || $selector === '.sf-prose--article') {
                continue;
            }
            // Allowed: the text blocks themselves, the first child, the written text inside them, and
            // the blocks of a post body by their own class.
            expect(preg_match('~^\.sf-prose--article (?:(?:\.richtext|\.sf-prose)$|:is\(\.richtext, \.sf-prose\) |\.richtext p$|\.sf-band--none |> :first-child|> :is\(\.sf-band, \.cta, \.sf-s\)|> \.page-band \+ \*$|> (?:\.sf-s > )?\.sf-band--none$)~', $selector))
                ->toBe(1, "a post-body rule that may reach a block: {$selector}");
        }
    }
});

it('puts a block heading in a post body on the article’s left edge with one rule, and no link underline on a button', function (): void {
    $css = (string) file_get_contents(resource_path('css/storefront.css'));

    expect($css)->toContain(".sf-prose--article .sf-band--none .sf-section-head {\n    max-width: none;\n    margin: 0 0 1.25rem;\n    text-align: left;")
        ->toContain(".sf-prose--article .sf-band--none .sf-section-head__rule {\n    display: none;")
        ->toContain(".sf-prose--article .sf-band--none > .sf-container,\n.sf-prose--article .sf-band--none :is(.sf-faq__list, .sf-video-block__frame) {\n    max-width: none;\n    margin-inline: 0;\n    padding-inline: 0;")
        ->toContain(".sf-prose:not(.sf-prose--article) a {\n    color: var(--color-primary-text);\n    text-decoration: underline;");
});
