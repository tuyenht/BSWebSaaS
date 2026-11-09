<?php

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * The page layout with a table of contents (an opt-in page template, e.g. a privacy policy): the
 * contents list and the heading anchors and numbers are made at render time from the page's own
 * text blocks, and the title band shows the page's effective and updated days. A page on the
 * default layout renders exactly as before.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
});

/**
 * A policy-like page: a title band, two plain text blocks, one formatted-text block with two h2.
 *
 * @param  list<array<string, mixed>>|null  $blocks
 */
function tocPage(?string $template, ?array $blocks = null): void
{
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($template, $blocks): void {
        $entry = StorefrontCards::page('/chinh-sach-bao-mat', 'Chính sách bảo mật', $blocks ?? [
            ['type' => 'page_header', 'props' => ['subtitle' => 'Cách chúng tôi bảo vệ thông tin']],
            ['type' => 'richtext', 'props' => ['heading' => 'Về chính sách này', 'paragraphs' => ['Đoạn một.']]],
            ['type' => 'richtext', 'props' => ['heading' => 'Đơn vị chịu trách nhiệm', 'paragraphs' => ['Đoạn hai.']]],
            ['type' => 'richtext_html', 'props' => ['html' => '<h2>Thông tin thu thập</h2><p>Ba.</p><h3>Chi tiết</h3><h2>Quyền của bạn</h2><p>Bốn.</p>']],
        ]);
        $entry->forceFill(['template_override' => $template, 'published_at' => CarbonImmutable::parse('2026-10-31 18:00', 'UTC')])->save();
        // Both instants fall on the next day in the site's zone (GMT+7), so a day taken in UTC shows.
        EntryTranslation::query()->where('entry_id', $entry->id)->update(['content_changed_at' => CarbonImmutable::parse('2026-11-04 20:00', 'UTC')]);
    });
}

function tocHtml(): string
{
    return test()->get('http://mau.platform.test/chinh-sach-bao-mat')->assertOk()->getContent();
}

it('draws a numbered contents list from the page’s headings, with matching anchors', function (): void {
    tocPage('page-toc');
    $html = tocHtml();

    preg_match('~<nav class="sf-doc__toc" aria-labelledby="sf-doc-toc-title">(.*?)</nav>~s', $html, $nav);
    preg_match_all('~<a class="sf-doc__toc-link" href="#([^"]+)">([^<]+)</a>~', $nav[1] ?? '', $links);

    expect($nav)->not->toBe([])
        // the name read out is the title shown
        ->and($nav[1])->toContain('<p class="sf-doc__toc-title" id="sf-doc-toc-title">Nội dung</p>')
        ->toContain('<ol class="sf-doc__toc-list">')
        ->and($links[1])->toBe(['muc-ve-chinh-sach-nay', 'muc-don-vi-chiu-trach-nhiem', 'muc-thong-tin-thu-thap', 'muc-quyen-cua-ban'])
        ->and($links[2])->toBe(['Về chính sách này', 'Đơn vị chịu trách nhiệm', 'Thông tin thu thập', 'Quyền của bạn'])
        ->and($html)->toContain('<h2 id="muc-ve-chinh-sach-nay">1. Về chính sách này</h2>')
        ->toContain('<h2 id="muc-don-vi-chiu-trach-nhiem">2. Đơn vị chịu trách nhiệm</h2>')
        ->toContain('<h2 id="muc-thong-tin-thu-thap">3. Thông tin thu thập</h2>')
        ->toContain('<h2 id="muc-quyen-cua-ban">4. Quyền của bạn</h2>')
        // h3 stays out of the list and unnumbered
        ->toContain('<h3>Chi tiết</h3>');

    // The band stays full width above the two columns; the single h1 is the band's.
    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(strpos($html, 'page-band__title'))->toBeLessThan(strpos($html, 'class="sf-doc"'));
});

it('shows the effective and updated days in the title band', function (): void {
    tocPage('page-toc');
    $html = tocHtml();

    preg_match('~<p class="page-band__dates">(.*?)</p>~s', $html, $dates);

    expect($dates)->not->toBe([])
        ->and($dates[1])->toContain('Ngày hiệu lực:')
        ->toContain('<time datetime="2026-11-01">01/11/2026</time>')
        ->toContain('Cập nhật:')
        ->toContain('<time datetime="2026-11-05">05/11/2026</time>');
});

it('leaves a page on the default layout exactly as it was', function (): void {
    tocPage(null);
    $html = tocHtml();

    expect($html)->not->toContain('sf-doc')
        ->not->toContain('page-band__dates')
        ->not->toContain('id="muc-')
        ->toContain('<h2>Về chính sách này</h2>')
        ->toContain('<h2>Thông tin thu thập</h2>');
});

it('keeps the author’s own numbering on every heading when any heading carries one', function (): void {
    tocPage('page-toc', [
        ['type' => 'page_header', 'props' => []],
        ['type' => 'richtext', 'props' => ['heading' => 'Giới thiệu', 'paragraphs' => ['Không.']]],
        ['type' => 'richtext', 'props' => ['heading' => '1. Phạm vi', 'paragraphs' => ['Một.']]],
        ['type' => 'richtext', 'props' => ['heading' => '2) Liên hệ', 'paragraphs' => ['Hai.']]],
    ]);
    $html = tocHtml();

    // One rule for the page: nothing is added, and the list shows the headings as written, unnumbered.
    expect($html)->toContain('<h2 id="muc-gioi-thieu">Giới thiệu</h2>')
        ->toContain('>1. Phạm vi</h2>')->not->toContain('1. 1. Phạm vi')->not->toContain('1. Giới thiệu')
        ->toContain('>2) Liên hệ</h2>')->not->toContain('3. 2) Liên hệ')
        ->toContain('<ol class="sf-doc__toc-list sf-doc__toc-list--own">')
        ->toContain('href="#muc-1-pham-vi">1. Phạm vi</a>')
        ->toContain('href="#muc-2-lien-he">2) Liên hệ</a>');
});

it('recognises the usual ways an author numbers a heading', function (string $heading): void {
    tocPage('page-toc', [
        ['type' => 'page_header', 'props' => []],
        ['type' => 'richtext', 'props' => ['heading' => $heading, 'paragraphs' => ['Một.']]],
        ['type' => 'richtext', 'props' => ['heading' => 'Mục sau', 'paragraphs' => ['Hai.']]],
    ]);
    $html = tocHtml();

    expect($html)->toContain('>'.$heading.'</h2>')
        ->toContain('<h2 id="muc-muc-sau">Mục sau</h2>')
        ->toContain('sf-doc__toc-list--own');
})->with(['Điều 1. Phạm vi', 'Chương 2 Quy định chung', '1.Phạm vi', '1.1 Phạm vi']);

it('numbers a heading that only starts with a count', function (): void {
    tocPage('page-toc', [
        ['type' => 'page_header', 'props' => []],
        ['type' => 'richtext', 'props' => ['heading' => '3 lý do chọn chúng tôi', 'paragraphs' => ['Một.']]],
        ['type' => 'richtext', 'props' => ['heading' => 'Liên hệ', 'paragraphs' => ['Hai.']]],
    ]);

    expect(tocHtml())->toContain('>1. 3 lý do chọn chúng tôi</h2>')
        ->toContain('>2. Liên hệ</h2>')
        ->not->toContain('sf-doc__toc-list--own');
});

it('escapes a heading’s markup and entities in the contents list', function (): void {
    tocPage('page-toc', [
        ['type' => 'page_header', 'props' => []],
        ['type' => 'richtext_html', 'props' => ['html' => '<h2><strong>Phí</strong> &amp; "điều kiện" &lt;mới&gt;</h2><p>A.</p><h2>Liên hệ</h2><p>B.</p>']],
    ]);
    $html = tocHtml();

    preg_match('~<nav class="sf-doc__toc"[^>]*>(.*?)</nav>~s', $html, $nav);

    expect($nav[1] ?? '')->toContain('Phí &amp; &quot;điều kiện&quot; &lt;mới&gt;</a>')
        ->not->toContain('<strong>')
        ->not->toContain('<mới>');
});

it('draws no contents list for fewer than two headings, but keeps the band and its days', function (): void {
    tocPage('page-toc', [
        ['type' => 'page_header', 'props' => []],
        ['type' => 'richtext', 'props' => ['heading' => 'Một mục', 'paragraphs' => ['Một.']]],
    ]);
    $html = tocHtml();

    expect($html)->not->toContain('sf-doc__toc')
        ->not->toContain('id="muc-')
        ->toContain('<h2>Một mục</h2>')
        ->toContain('page-band__dates');
});

it('keeps its own title and breadcrumb when no title band leads the page', function (): void {
    tocPage('page-toc', [
        ['type' => 'richtext', 'props' => ['heading' => 'Mục A', 'paragraphs' => ['A.']]],
        ['type' => 'richtext', 'props' => ['heading' => 'Mục B', 'paragraphs' => ['B.']]],
    ]);
    $html = tocHtml();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<h1 class="page__title">Chính sách bảo mật</h1>')
        ->toContain('<h2 id="muc-muc-a">1. Mục A</h2>')
        ->toContain('class="sf-doc__toc"');
});

it('is offered as a page layout only', function (): void {
    expect(TemplateCatalog::names(TemplateCatalog::ROLE_PAGE))->toBe(['page', 'page-toc'])
        ->and(TemplateCatalog::fits('page-toc', TemplateCatalog::ROLE_POST))->toBeFalse();
});

it('joins the contact cards and the form-with-map card with one 4rem gap', function (): void {
    $css = (string) file_get_contents(resource_path('css/storefront.css'));

    // The cards' 5rem bottom margin is for the map under them only; the section's bottom padding
    // and the form card's top margin do not stack where the form follows the contact block.
    expect($css)->toMatch('~\.sf-contact-cards:last-child \{\s*margin-bottom: 0;\s*\}~')
        ->toMatch('~\.contact:has\(\+ \.lead-form--map\) \{\s*padding-bottom: 0;\s*\}~')
        ->toMatch('~\.contact \+ \.lead-form\.lead-form--map \{\s*margin-top: 4rem;\s*\}~');
});
