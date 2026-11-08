<?php

use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Theme\Rendering\RichHtml;

/*
 * The CMS profile of the rich-text sanitizer (ADR-19): what a page or post body's formatted text may
 * keep. It is the full product profile with one change — a picture names a library image by id
 * (figure / figcaption / img[data-media-id|data-size|data-align|alt]) and never stores an address.
 *
 * Every output here is also checked against the storefront's quick test of clean data
 * (RichHtml::looksSanitized): what the sanitizer prints must pass it, or clean bodies would be
 * sanitized again on every page view.
 */

function cmsClean(string $html): string
{
    static $sanitizer = null;
    $sanitizer ??= new RichTextSanitizer;

    $clean = $sanitizer->cms($html);
    expect(RichHtml::looksSanitized($clean))->toBeTrue('the storefront re-sanitizes this clean output: '.$clean);

    return $clean;
}

it('strips scripts, event handlers and script links', function (string $dirty, string $gone): void {
    expect(strtolower(cmsClean($dirty)))->not->toContain($gone);
})->with([
    'script tag' => ['<p>a</p><script>alert(1)</script>', '<script'],
    'script text kept out' => ['<p>a</p><script>alert(1)</script>', 'alert'],
    'onerror' => ['<img data-media-id="5" onerror="alert(1)">', 'onerror'],
    'onclick' => ['<p onclick="alert(1)">a</p>', 'onclick'],
    'javascript link' => ['<a href="javascript:alert(1)">x</a>', 'javascript:'],
    'encoded javascript link' => ['<a href="&#106;avascript:alert(1)">x</a>', 'avascript'],
    'data link' => ['<a href="data:text/html,<script>1</script>">x</a>', 'data:'],
    'style expression' => ['<span style="width:expression(alert(1))">x</span>', 'expression'],
    'style url' => ['<p style="background:url(javascript:alert(1))">x</p>', 'url('],
    'svg' => ['<svg onload="alert(1)"><circle /></svg>', '<svg'],
    'math' => ['<math><mi>x</mi></math>', '<math'],
    'form' => ['<form action="/x"><input name="a"></form>', '<form'],
    'object' => ['<object data="x.swf"></object>', '<object'],
    'style tag' => ['<style>body{display:none}</style>', '<style'],
    'class smuggling' => ['<p class="sf-hero">x</p>', 'sf-hero'],
]);

it('keeps youtube and vimeo players only', function (): void {
    $html = cmsClean('<iframe src="https://evil.test/embed/1"></iframe>'
        .'<iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo" allowfullscreen></iframe>'
        .'<iframe src="https://player.vimeo.com/video/123456789"></iframe>');

    expect($html)->not->toContain('evil.test')
        ->and($html)->toContain('src="https://www.youtube.com/embed/SxwkhMy4Ywo"')
        ->and($html)->toContain('src="https://player.vimeo.com/video/123456789"');
});

it('never keeps an image address, of any scheme', function (string $img): void {
    expect(cmsClean($img))->not->toContain('src=');
})->with([
    'https' => '<img data-media-id="5" src="https://cdn.test/a.png" alt="a">',
    'data' => '<img data-media-id="5" src="data:image/png;base64,AAAA">',
    'protocol-relative' => '<img data-media-id="5" src="//cdn.test/a.png">',
    'no id' => '<img src="https://cdn.test/a.png">',
]);

it('keeps the article structure', function (): void {
    $html = '<h2>Câu chuyện</h2><h3>Giá trị</h3><h4>Nhỏ</h4><h5>Nhỏ hơn</h5><blockquote>Trích dẫn</blockquote>'
        .'<ul><li>Một</li></ul><ol><li>Hai</li></ol><table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'
        .'<p><strong>đậm</strong> <em>nghiêng</em> <a href="https://an-vinh.test/lien-he">liên hệ</a> m<sup>2</sup></p>';

    expect(cmsClean($html))->toBe($html);
});

it('keeps merged cells and header scopes, in the CMS and the product profile', function (): void {
    $html = '<table><thead><tr><th scope="col" colspan="2">Thông số</th></tr></thead>'
        .'<tbody><tr><th scope="row" rowspan="2">Dung sai</th><td>±0.01 mm</td></tr><tr><td>±0.005 mm</td></tr></tbody></table>';

    expect(cmsClean($html))->toBe($html)
        ->and((new RichTextSanitizer)->full($html))->toBe($html);
});

it('drops a span or scope that is not a positive whole number or a known scope', function (string $cell, string $clean): void {
    expect(cmsClean("<table><tbody><tr>{$cell}</tr></tbody></table>"))->toBe("<table><tbody><tr>{$clean}</tr></tbody></table>");
})->with([
    'zero' => ['<td colspan="0">a</td>', '<td>a</td>'],
    'negative' => ['<td rowspan="-2">a</td>', '<td>a</td>'],
    'words' => ['<td colspan="two">a</td>', '<td>a</td>'],
    'unknown scope' => ['<th scope="all">a</th>', '<th>a</th>'],
    'scope on a data cell' => ['<td scope="row">a</td>', '<td>a</td>'],
]);

it('drops h1 and h6 to their text (the page owns its h1)', function (): void {
    expect(cmsClean('<h1>Tiêu đề</h1><h6>Nhỏ</h6>'))->toBe('Tiêu đềNhỏ');
});

it('keeps a library picture in a figure with its caption', function (): void {
    expect(cmsClean('<figure><img data-media-id="12" data-size="md" data-align="left" alt="Máy phay"><figcaption>Xưởng <b>CNC</b></figcaption></figure>'))
        ->toBe('<figure><img data-media-id="12" data-size="md" data-align="left" alt="Máy phay" /><figcaption>Xưởng <b>CNC</b></figcaption></figure>');
});

it('keeps every size and alignment the editor offers', function (string $size, string $align): void {
    expect(cmsClean("<img data-media-id=\"3\" data-size=\"{$size}\" data-align=\"{$align}\">"))
        ->toBe("<img data-media-id=\"3\" data-size=\"{$size}\" data-align=\"{$align}\" />");
})->with(RichTextSanitizer::IMAGE_SIZES)->with(RichTextSanitizer::IMAGE_ALIGNS);

it('drops a size or alignment outside the list, and an alt-less picture keeps no invented alt', function (): void {
    expect(cmsClean('<img data-media-id="3" data-size="huge" data-align="middle">'))->toBe('<img data-media-id="3" />');
});

it('invents no alt from an address it drops', function (): void {
    expect(cmsClean('<img data-media-id="5" src="https://evil.test/x.png">'))->toBe('<img data-media-id="5" />')
        ->and(cmsClean('<p><img data-media-id="5" src="https://evil.test/x.png" alt="Của tôi"></p>'))->toBe('<p><img data-media-id="5" alt="Của tôi" /></p>');
});

it('drops a picture whose id is not a positive whole number', function (string $id): void {
    expect(cmsClean("<p>a<img data-media-id=\"{$id}\" alt=\"x\">b</p>"))->toBe('<p>ab</p>');
})->with(['0', '-3', '12abc', '1.5', '', 'abc']);

it('keeps a caption only inside a figure', function (): void {
    expect(cmsClean('<p><figcaption>lạc</figcaption></p>'))->toBe('<p>lạc</p>');
});

it('leaves the full (product) profile unchanged: images there keep their library address', function (): void {
    expect((new RichTextSanitizer)->full('<img src="https://cdn.test/a.png" alt="a" data-media-id="5">'))
        ->toBe('<img src="https://cdn.test/a.png" alt="a" />')
        ->and((new RichTextSanitizer)->full('<img src="https://cdn.test/a.png">'))
        ->toBe('<img src="https://cdn.test/a.png" alt="a.png" />');
});

it('keeps what the storefront\'s quick test must accept', function (string $html): void {
    expect(cmsClean($html))->not->toBe('');
})->with([
    'styles the editor writes' => '<p style="color:red;background-color:#fff;font-size:12px;text-align:center;margin-left:40px;padding-left:2em;width:50%;height:10px">a</p>',
    'colour functions' => '<p style="color: rgb(1, 2, 3)">a</p><p style="color:rgba(1,2,3,0.5)">b</p><span style="color:hsl(1,2%,3%)">c</span>',
    'nested lists' => '<ul><li><p>x</p><ul><li>y</li></ul></li></ul><ol> <li>z</li> </ol>',
    'table with spacing' => '<table> <thead><tr><th style="width:10px">A</th></tr></thead> <tbody><tr><td><p>x</p><ul><li>a</li></ul></td></tr></tbody> </table>',
    'linked picture' => '<p><a href="/x"><img data-media-id="3"></a><br>sau</p><hr>',
    'figure in a list item' => '<ul><li><figure><p>x</p><img data-media-id="1"><figcaption><a href="/x">c</a></figcaption></figure></li></ul>',
    'quote and box' => '<blockquote>x<p>y</p></blockquote><div><p>a</p>b</div>',
    'nested inline' => '<p><span><span>x</span></span><sub><sup>a</sup></sub><strong><em>b</em></strong></p>',
    'video in a paragraph' => '<p>x<iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo"></iframe></p>',
    'entities and line breaks' => "<p>x &amp; y &lt; &gt; \" © \n<b>b</b></p>",
    'split by the purifier' => '<p><div>x</div></p><a href="/x"><p>khối</p></a><h3><p>x</p></h3>',
]);

it('is stable: cleaning clean output changes nothing', function (): void {
    $once = cmsClean('<h2>T</h2><figure><img data-media-id="7" data-size="sm" alt="&quot;x&quot;"><figcaption>c</figcaption></figure><p style="text-align:center">p</p>'
        .'<div class="video-embed"><iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo" allowfullscreen></iframe></div>');

    expect(cmsClean($once))->toBe($once);
});
