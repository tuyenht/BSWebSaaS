<?php

/*
 * Below the first screen the storefront lets the browser skip layout and paint (content-visibility:
 * auto). The rule must stay scoped: never the header, the title or the first two blocks of <main>
 * (they are the first screen), only on screen (print draws everything), and never on a page that jumps to
 * a #target (storefront.js adds sf-anchored first: the browser must scroll with the real sizes of the
 * blocks above the target, not their estimates).
 */

/** @return list<array{media: string, selectors: string, body: string}> */
function deferredLayoutRules(): array
{
    $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/storefront.css');
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    $rules = [];
    // top-level rules and the rules inside one level of @media
    preg_match_all('~(@media[^{]+)\{((?:[^{}]*\{[^{}]*\})+)\s*\}|([^{}@]+)\{([^{}]*)\}~s', $css, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        if (($m[1] ?? '') !== '') {
            preg_match_all('~([^{}]+)\{([^{}]*)\}~', $m[2], $inner, PREG_SET_ORDER);
            foreach ($inner as $i) {
                $rules[] = ['media' => trim($m[1]), 'selectors' => trim($i[1]), 'body' => $i[2]];
            }
        } elseif (isset($m[3])) {
            $rules[] = ['media' => '', 'selectors' => trim($m[3]), 'body' => $m[4]];
        }
    }

    return array_values(array_filter($rules, static fn (array $r): bool => str_contains($r['body'], 'content-visibility')));
}

it('defers only below-the-fold blocks and the footer, on screen only', function (): void {
    $rules = deferredLayoutRules();
    expect($rules)->not->toBeEmpty();

    $joined = implode("\n", array_column($rules, 'selectors'));
    expect($joined)->toContain('main > article ~ *')
        ->and($joined)->toContain('body > footer');

    foreach ($rules as $rule) {
        expect($rule['media'])->toStartWith('@media screen');
        expect($rule['selectors'])->toContain(':not(.sf-anchored)');
        expect($rule['selectors'])->not->toMatch('~header|\.sf-header|:first-child|:nth-child~');
    }

    // the generic block rule needs two earlier sibling blocks: the first two are never deferred
    $blocks = array_values(array_filter($rules, static fn (array $r): bool => str_contains($r['selectors'], 'main > :not(')));
    expect($blocks)->toHaveCount(1);
    expect(substr_count($blocks[0]['selectors'], ' ~ :not(h1, .page__crumbs)'))->toBe(2);
    expect($blocks[0]['body'])->toContain('content-visibility: auto')->toContain('contain-intrinsic-size: auto');
});

it('keeps content-visibility out of the block stylesheets', function (): void {
    foreach (glob(dirname(__DIR__, 3).'/resources/css/sf-*.css') ?: [] as $file) {
        expect((string) file_get_contents($file))->not->toContain('content-visibility', basename($file));
    }
});

it('turns the deferral off before a jump to a #target', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront.js');

    // the CSS rules are scoped with :not(.sf-anchored): the script is what adds the class
    expect($js)->toContain("root.classList.add('sf-anchored')")
        ->and($js)->toContain("addEventListener('hashchange'")
        // a reload or a step back keeps the place the browser restores
        ->and($js)->toContain("'back_forward'");
});
