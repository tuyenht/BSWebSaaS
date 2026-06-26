<?php

use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Support\Facades\View;

/*
 * Theme section rendering: the about block (a static company-intro section), and the entry_grid
 * card's SMART featured image — a glyph key renders an inline SVG, a URL/path renders an <img>
 * (one image_url concept). Services + projects are data-bound posts shown via entry_grid; each
 * card links to the post's detail page.
 */

it('registers the about block with url-typed sinks', function () {
    $registry = app(ComponentRegistry::class);

    expect($registry->has('about'))->toBeTrue();
    $about = $registry->get('about')->schema();
    expect($about['image_url']['type'])->toBe('url');
    expect($about['cta_url']['type'])->toBe('url');
});

it('renders the about block with paragraphs, image and CTA', function () {
    $props = [
        'heading' => 'Về An Vinh',
        'paragraphs' => ['Đoạn một.', 'Đoạn hai.'],
        'image_url' => 'https://cdn.example/about.jpg',
        'cta_label' => 'Tìm hiểu thêm', 'cta_url' => '/gioi-thieu',
    ];

    $html = View::make('theme::sections.about', ['props' => $props])->render();

    expect($html)
        ->toContain('Về An Vinh')
        ->toContain('Đoạn một.')->toContain('Đoạn hai.')
        ->toContain('src="https://cdn.example/about.jpg"')
        ->toContain('href="/gioi-thieu">Tìm hiểu thêm');
});

it('renders the entry_grid card image smartly: glyph key → SVG, URL → <img>, card → detail link', function () {
    $view = ['props' => ['heading' => 'Dịch vụ'], 'items' => [
        ['image' => 'shield', 'title' => 'Cách nhiệt', 'url' => '/dich-vu/cach-nhiet', 'short_outcome' => '', 'sector' => ''],
        ['image' => 'https://cdn.example/forklift.jpg', 'title' => 'Xe nâng', 'url' => '/dich-vu/xe-nang', 'short_outcome' => '', 'sector' => ''],
    ]];

    $html = View::make('theme::sections.entry_grid', $view)->render();

    expect($html)
        ->toContain('Dịch vụ')
        ->toContain('<svg')                                       // glyph key → inline SVG (service icon)
        ->toContain('src="https://cdn.example/forklift.jpg"')     // URL → <img> (photo)
        ->toContain('href="/dich-vu/cach-nhiet">Cách nhiệt');     // card links to the service detail page
});

it('renders no icon for an unknown glyph key (no injection surface)', function () {
    $view = ['props' => [], 'items' => [
        ['image' => 'definitely-not-a-glyph-key', 'title' => 'X', 'url' => '/x', 'short_outcome' => '', 'sector' => ''],
    ]];

    $html = View::make('theme::sections.entry_grid', $view)->render();

    expect($html)->not->toContain('<svg')->not->toContain('<img');
});

it('registers the partners block with url-typed logo list fields', function () {
    $registry = app(ComponentRegistry::class);

    expect($registry->has('partners'))->toBeTrue();
    $logos = $registry->get('partners')->schema()['logos'];
    expect($logos['type'])->toBe('list');
    expect($logos['of']['image_url']['type'])->toBe('url');
    expect($logos['of']['url']['type'])->toBe('url');
});

it('renders the partners block as a logo grid, linking a logo only when it has both a url and a name', function () {
    $props = ['heading' => 'Đối tác', 'logos' => [
        ['image_url' => 'https://cdn.example/p1.png', 'name' => 'Đối tác Một', 'url' => 'https://p1.test'],
        ['image_url' => 'https://cdn.example/p2.png', 'name' => 'Đối tác Hai'],   // no url → image only
    ]];

    $html = View::make('theme::sections.partners', ['props' => $props])->render();

    expect($html)
        ->toContain('Đối tác')
        ->toContain('src="https://cdn.example/p1.png"')->toContain('alt="Đối tác Một"')
        ->toContain('href="https://p1.test"')
        ->toContain('src="https://cdn.example/p2.png"')->toContain('alt="Đối tác Hai"');
    // exactly one anchor: the linked logo. The url-less logo renders bare (no dead href).
    expect(substr_count($html, '<a '))->toBe(1);
});

it('skips a partner logo that has no image_url (no empty <img>)', function () {
    $props = ['logos' => [['name' => 'Không ảnh', 'url' => '/x']]];

    $html = View::make('theme::sections.partners', ['props' => $props])->render();

    expect($html)->not->toContain('<img')->not->toContain('src=""');
});

it('renders a hero second CTA when cta2 props are set', function () {
    $props = [
        'heading' => 'An Vinh',
        'cta_label' => 'Yêu cầu báo giá', 'cta_url' => '/lien-he',
        'cta2_label' => 'Xem dịch vụ', 'cta2_url' => '/dich-vu',
    ];

    $html = View::make('theme::sections.hero', ['props' => $props])->render();

    expect($html)
        ->toContain('href="/lien-he">Yêu cầu báo giá')
        ->toContain('href="/dich-vu">Xem dịch vụ');
});

it('renders the hero unchanged when no second CTA is set (render-neutral)', function () {
    $props = ['heading' => 'An Vinh', 'cta_label' => 'Liên hệ', 'cta_url' => '/lien-he'];

    $html = View::make('theme::sections.hero', ['props' => $props])->render();

    expect($html)->toContain('href="/lien-he">Liên hệ')->not->toContain('hero__cta--secondary');
});

it('guards the hero h1 against an empty heading (no empty <h1>)', function () {
    // An empty <h1></h1> is an axe violation; emit the h1 only when there is a heading.
    $withHeading = View::make('theme::sections.hero', ['props' => ['heading' => 'An Vinh']])->render();
    $noHeading = View::make('theme::sections.hero', ['props' => ['cta_label' => 'X', 'cta_url' => '/x']])->render();

    expect($withHeading)->toContain('<h1>An Vinh</h1>');
    expect($noHeading)->not->toContain('<h1');
});

it('registers the contact block with url-typed phone/email/map sinks', function () {
    $contact = app(ComponentRegistry::class)->get('contact')->schema();

    expect($contact['phone']['type'])->toBe('url');
    expect($contact['email']['type'])->toBe('url');
    expect($contact['map_embed']['type'])->toBe('url');
});

it('renders the contact block with click-to-call/mail and a lazy map iframe', function () {
    $props = [
        'heading' => 'Liên hệ An Vinh',
        'address' => 'Khu CN, Hải Phòng',
        'phone' => '0901234567',
        'email' => 'lienhe@anvinh.test',
        'map_embed' => 'https://maps.example/embed?x=1',
    ];

    $html = View::make('theme::sections.contact', ['props' => $props])->render();

    expect($html)
        ->toContain('Liên hệ An Vinh')->toContain('Khu CN, Hải Phòng')
        ->toContain('href="tel:0901234567"')
        ->toContain('href="mailto:lienhe@anvinh.test"')
        ->toContain('src="https://maps.example/embed?x=1"')->toContain('loading="lazy"');
});

it('omits contact link/iframe markup for empty fields', function () {
    $html = View::make('theme::sections.contact', ['props' => ['heading' => 'Liên hệ']])->render();

    expect($html)->toContain('Liên hệ')->not->toContain('<a ')->not->toContain('<iframe');
});

it('does not link a partner logo that has a url but no name (a11y: no name-less link)', function () {
    $props = ['logos' => [['image_url' => 'https://cdn.example/p.png', 'url' => 'https://p.test']]];

    $html = View::make('theme::sections.partners', ['props' => $props])->render();

    // image renders, but no anchor — a linked logo must carry an accessible name (alt from name).
    expect($html)->toContain('src="https://cdn.example/p.png"')->not->toContain('<a ');
});

it('scans bundled theme blades for Tailwind classes so storefront utilities are not purged', function () {
    // Regression guard: the bundled theme lives at top-level themes/; the Tailwind @source must
    // cover it, else moving views out of app/Modules/ silently purges their utility classes.
    expect(file_get_contents(base_path('resources/css/app.css')))->toContain("@source '../../themes/");
});
