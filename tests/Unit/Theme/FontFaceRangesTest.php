<?php

/*
 * Faces of one font family in storefront.css must declare the same weight and style. Chrome picks a
 * face by weight and style before it looks at unicode-range, so a face with a wider weight range
 * wins every character and the faces split by unicode-range never combine: the page then paints
 * the fallback font for all text.
 */

it('gives every face of one family the same weight and style', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/storefront.css');
    preg_match_all('/@font-face\s*\{([^}]*)\}/', $css, $faces);

    $byFamily = [];
    foreach ($faces[1] as $body) {
        $read = static fn (string $name): string => preg_match('/(?:^|;|\s)'.$name.'\s*:\s*([^;]+);/', $body, $m) === 1
            ? trim(preg_replace('/\s+/', ' ', $m[1]) ?? '', " \"'")
            : 'normal';
        $byFamily[$read('font-family')][] = $read('font-weight').' / '.$read('font-style');
    }

    expect($byFamily)->toHaveKey('Inter');
    expect(count($byFamily['Inter']))->toBeGreaterThan(1);
    foreach ($byFamily as $family => $descriptors) {
        expect(array_unique($descriptors))->toHaveCount(1, "{$family}: ".implode(', ', $descriptors));
    }
});
