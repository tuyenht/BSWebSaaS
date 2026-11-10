<?php

use App\Modules\Media\Actions\UploadMedia;

// The media Worker serves a key only when its extension is in deploy/media-worker/src/keys.js, with
// the type listed there. A type the library starts to accept but the Worker does not list would be
// stored fine and then answer 404 on the public host, so the two lists are checked together here.
// keys.js is read as text: every entry of the TYPES map (and any `.set(` call) must parse as a pair
// of string literals, whatever the quotes, or the test fails instead of silently skipping it.

/** @return array{source: string, map: string} keys.js, and the text inside its `new Map([ … ])` for TYPES */
function mediaWorkerKeysJs(): array
{
    $source = (string) file_get_contents(base_path('deploy/media-worker/src/keys.js'));
    expect(preg_match('/const\s+TYPES\s*=\s*new\s+Map\(\[(.*?)\]\);/s', $source, $map))
        ->toBe(1, 'keys.js no longer builds TYPES as `const TYPES = new Map([ … ]);`');

    return ['source' => $source, 'map' => $map[1]];
}

/** @return array<string, string> extension => Content-Type, as the Worker lists them */
function mediaWorkerTypes(): array
{
    ['source' => $source, 'map' => $map] = mediaWorkerKeysJs();
    // Two string literals in any of the three JS quotes (no escapes, no ${…}); groups 2 and 4 hold them.
    $pair = '\s*([\'"`])([^\'"`\\\\$]+)\1\s*,\s*([\'"`])([^\'"`\\\\$]+)\3\s*';
    preg_match_all('/\['.$pair.'\]/', $map, $entries, PREG_SET_ORDER);
    preg_match_all('/\.set\('.$pair.'\)/', $source, $sets, PREG_SET_ORDER);

    $written = substr_count($map, '[') + substr_count($source, '.set(');
    $read = count($entries) + count($sets);
    expect($read)->toBe($written, "keys.js writes {$written} type entries but only {$read} read as two plain strings");

    $types = [];
    foreach ([...$entries, ...$sets] as $match) {
        $types[$match[2]] = $match[4];
    }

    return $types;
}

it('serves every file type the media library accepts, under the same type', function (): void {
    $types = mediaWorkerTypes();

    expect($types)->not->toBeEmpty();
    foreach (UploadMedia::MIME_EXT as $mime => $ext) {
        expect($types)->toHaveKey($ext);
        expect($types[$ext])->toBe($mime);
    }
});

it('never lists a type a browser could run as a page or a script', function (): void {
    foreach (mediaWorkerTypes() as $ext => $type) {
        expect($type)->not->toContain('html')
            ->and($type)->not->toContain('xml')
            ->and($type)->not->toContain('javascript')
            ->and($ext)->not->toBeIn(['html', 'htm', 'svg', 'xml', 'js', 'mjs']);
    }
});

it('never mentions svg, html, xml or script anywhere in the type list, however it is quoted', function (): void {
    ['source' => $source, 'map' => $map] = mediaWorkerKeysJs();
    preg_match_all('/\.set\([^)]*\)/', $source, $sets);

    expect($map."\n".implode("\n", $sets[0]))
        ->not->toMatch('/svg|html?\b|xml|xhtml|javascript|ecmascript|\bm?js\b/i');
});
