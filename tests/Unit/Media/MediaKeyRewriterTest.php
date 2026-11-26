<?php

use App\Modules\Media\Support\MediaKeyRewriter;

/*
 * MediaKeyRewriter rewrites a stored document after media:rekey-site moved its files: a string that
 * is a moved key, or an address on a media host whose path ends with one, gets the new key (base
 * path and query kept); everything else stays, and strings under an old folder that name no moved
 * file are counted, as are addresses on another host that look like a moved (or old) key.
 */

function rekeyRewriter(): MediaKeyRewriter
{
    return new MediaKeyRewriter([
        'an-vinh/hero/x-ab12.jpg' => 'q7k2m9/x-ab12.jpg',
        'ten-cu/library/logo-cd34.png' => 'q7k2m9/logo-cd34.png',
    ], ['ten-cu', 'cu-khac'], ['files.example', 'f.example']);
}

it('rewrites a bare key and an address ending with one, keeping its base path and query', function (string $from, string $to): void {
    expect(rekeyRewriter()->rewriteString($from))->toBe($to);
})->with([
    'bare key' => ['an-vinh/hero/x-ab12.jpg', 'q7k2m9/x-ab12.jpg'],
    'https address with a base path' => ['https://files.example/media/an-vinh/hero/x-ab12.jpg', 'https://files.example/media/q7k2m9/x-ab12.jpg'],
    'address with a width' => ['https://files.example/ten-cu/library/logo-cd34.png?w=64', 'https://files.example/q7k2m9/logo-cd34.png?w=64'],
    'protocol-relative address' => ['//files.example/an-vinh/hero/x-ab12.jpg#top', '//files.example/q7k2m9/x-ab12.jpg#top'],
    'host in capitals' => ['https://FILES.example/an-vinh/hero/x-ab12.jpg', 'https://FILES.example/q7k2m9/x-ab12.jpg'],
]);

it('leaves alone a string that only mentions a key or names another file', function (string $value): void {
    expect(rekeyRewriter()->rewriteString($value))->toBeNull();
})->with([
    'text around a key' => 'ảnh an-vinh/hero/x-ab12.jpg trong bài',
    'a key with a longer name' => 'an-vinh/hero/x-ab12.jpgx',
    'a key inside a query only' => 'https://files.example/khac.jpg?src=an-vinh/hero/x-ab12.jpg',
    'a media-host path that only ends alike' => 'https://files.example/media/hero/x-ab12.jpg',
    'not an address' => 'mailto:an-vinh/hero/x-ab12.jpg',
    'an address on another host' => 'https://cdn.other.example/an-vinh/hero/x-ab12.jpg',
    'a host that only ends with a media host' => 'https://evilfiles.example/an-vinh/hero/x-ab12.jpg',
    'a path without a host' => '/cwv-media/an-vinh/hero/x-ab12.jpg',
]);

it('walks a whole document, keeps empty objects as objects and counts what it rewrote and what it could not', function (): void {
    $rewriter = rekeyRewriter();
    $document = json_decode('{"image_url":"an-vinh/hero/x-ab12.jpg","style":{},"blocks":[{"props":{"do":{"k":"file","file":"ten-cu/library/logo-cd34.png"},"img":"https://f.example/cu-khac/library/mat-ef56.png","n":3}}],"tags":[]}', false);

    $out = $rewriter->rewrite($document);

    expect(json_encode($out, JSON_UNESCAPED_SLASHES))->toBe('{"image_url":"q7k2m9/x-ab12.jpg","style":{},"blocks":[{"props":{"do":{"k":"file","file":"q7k2m9/logo-cd34.png"},"img":"https://f.example/cu-khac/library/mat-ef56.png","n":3}}],"tags":[]}')
        ->and($rewriter->rewritten)->toBe(2)
        ->and($rewriter->unmatched)->toBe(['https://f.example/cu-khac/library/mat-ef56.png'])
        ->and($rewriter->folders())->toBe(['ten-cu', 'cu-khac', 'an-vinh']);

    $again = $rewriter->rewrite($out);
    expect($rewriter->rewritten)->toBe(0)->and($again)->toEqual($out);
});

it('reports an address on another host that ends with a moved key, without rewriting it', function (): void {
    $rewriter = rekeyRewriter();
    $document = json_decode('{"a":"https://cdn.other.example/an-vinh/hero/x-ab12.jpg","b":"//cdn.other.example/x/ten-cu/library/logo-cd34.png?w=1","c":"https://cdn.other.example/khac-ab12.jpg"}', false);

    $out = $rewriter->rewrite($document);

    expect($out)->toEqual($document)
        ->and($rewriter->rewritten)->toBe(0)
        ->and($rewriter->unmatched)->toBe([])
        ->and($rewriter->otherHost)->toBe(['https://cdn.other.example/an-vinh/hero/x-ab12.jpg', '//cdn.other.example/x/ten-cu/library/logo-cd34.png?w=1']);
});

it('reports every address under an old folder when no media host is known', function (): void {
    $rewriter = new MediaKeyRewriter(['an-vinh/hero/x-ab12.jpg' => 'q7k2m9/x-ab12.jpg'], ['ten-cu'], []);
    $document = json_decode('["https://files.example/an-vinh/hero/x-ab12.jpg","https://files.example/ten-cu/library/khong-co-1234.png","https://files.example/khac/y-1234.png","an-vinh/hero/x-ab12.jpg"]', false);

    $out = $rewriter->rewrite($document);

    expect($out)->toEqual(['https://files.example/an-vinh/hero/x-ab12.jpg', 'https://files.example/ten-cu/library/khong-co-1234.png', 'https://files.example/khac/y-1234.png', 'q7k2m9/x-ab12.jpg'])
        ->and($rewriter->rewritten)->toBe(1)
        ->and($rewriter->otherHost)->toBe(['https://files.example/an-vinh/hero/x-ab12.jpg', 'https://files.example/ten-cu/library/khong-co-1234.png']);
});
