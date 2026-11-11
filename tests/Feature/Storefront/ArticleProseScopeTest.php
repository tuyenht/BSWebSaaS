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
        // the selector list split at its top-level commas (not those inside :is() / :not() / :has())
        preg_match_all('~(?:[^,()]|(\((?:[^()]|(?1))*\)))+~', '.sf-prose--article'.$rest, $parts);
        foreach ($parts[0] as $selector) {
            $selector = trim($selector);
            if (! str_starts_with($selector, '.sf-prose--article') || $selector === '.sf-prose--article') {
                continue;
            }
            // Allowed: the text blocks themselves, the first child, the written text inside them, and
            // the blocks of a post body by their own class.
            expect(preg_match('~^\.sf-prose--article (?:(?:\.richtext|\.sf-prose)$|:is\(\.richtext, \.sf-prose\) |\.richtext p$|\.sf-band--none |> :first-child|> :is\(\.sf-band, \.cta, \.sf-s:not\(:has\(> :is\(\.richtext, \.sf-prose\)\)\)\)|> \.sf-s(?::is\(\[class\*="sf-s--sch-"\], \.sf-s--image\))?:has\(> :is\(\.richtext, \.sf-prose\)\)$|> \.page-band \+ \*$|> (?:\.sf-s > )?\.sf-band--none$)~', $selector))
                ->toBe(1, "a post-body rule that may reach a block: {$selector}");
        }
    }
});

it('puts a block heading in a post body on the article’s left edge with one rule, and no link underline on a button', function (): void {
    $css = (string) file_get_contents(resource_path('css/storefront.css'));

    expect($css)->toContain(".sf-prose--article .sf-band--none .sf-section-head {\n    max-width: none;\n    margin: 0 0 1.25rem;\n    text-align: left;")
        ->toContain(".sf-prose--article .sf-band--none .sf-section-head__rule,\n.sf-prose--article .sf-band--none .sf-section-head > .sf-decor {\n    display: none;")
        ->toContain(".sf-prose--article .sf-band--none > .sf-container,\n.sf-prose--article .sf-band--none :is(.sf-faq__list, .sf-video-block__frame) {\n    max-width: none;\n    margin-inline: 0;\n    padding-inline: 0;")
        ->toContain(".sf-prose:not(.sf-prose--article) a {\n    color: var(--color-primary-text);\n    text-decoration: underline;");
});

it('spaces a text block that has a look set like text in a post body, not like a block', function (): void {
    // A look (padding, colour set…) wraps the block in .sf-s (BlockRenderer): in a post it keeps the
    // text's rhythm (measured: 21px from the paragraph above, like plain text) instead of the 2.5rem
    // gap of a video or FAQ; a painted one adds the paragraph gap below its box.
    $css = (string) file_get_contents(resource_path('css/storefront.css'));

    expect($css)->toContain(".sf-prose--article > :is(.sf-band, .cta, .sf-s:not(:has(> :is(.richtext, .sf-prose)))):not(:first-child),\n.sf-prose--article > .page-band + * {\n    margin-block: 2.5rem 0;")
        ->toContain(".sf-prose--article > .sf-s:has(> :is(.richtext, .sf-prose)) {\n    margin-block: 0;")
        ->toContain(".sf-prose--article > .sf-s:is([class*=\"sf-s--sch-\"], .sf-s--image):has(> :is(.richtext, .sf-prose)) {\n    margin-bottom: 1.15em;");
});
