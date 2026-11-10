<?php

use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * The opt-in looks of existing blocks (`variant`, `bg`): services as icon cards, products as photo
 * cards with their category, a rounded CTA with a sub-line, contact cards, top-rule features, a
 * form beside a map, project and news cards. Every card is one stretched link; a stored look that
 * is not in the list renders the default look.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    config(['cms.map_hosts' => ['www.google.com']]);
});

/** Seeds services, products and news, then renders one page holding $blocks. */
function variantPage(array $blocks): string
{
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($blocks): void {
        $photo = StorefrontCards::media('card', 960, 720);
        $services = StorefrontCards::category('Dịch vụ', 'dich-vu');
        StorefrontCards::post($services, 'Thiết kế đồ gá', 'thiet-ke-do-ga', CarbonImmutable::parse('2026-10-02 08:00', 'UTC'), ['image_url' => 'lock'], 'Đồ gá tối ưu.');
        StorefrontCards::post($services, 'Gia công CNC', 'gia-cong-cnc', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), ['image_url' => 'adjust'], 'Phay, tiện.');

        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham');
        StorefrontCards::catalog($catalog);
        $jigs = StorefrontCards::category('Đồ gá', 'do-ga', $catalog);
        StorefrontCards::product('Jig kiểm VF7', 'jig-vf7', $jigs, $photo, CarbonImmutable::parse('2026-10-02 08:00', 'UTC'));

        $news = StorefrontCards::news();
        StorefrontCards::post($news, 'Khánh thành xưởng', 'khanh-thanh', CarbonImmutable::parse('2026-10-05 23:30', 'UTC'), ['image_url' => $photo->storage_key], 'Xưởng mới.');

        StorefrontCards::page('/khoi', 'Các khối', $blocks);
    });

    return test()->get('http://mau.platform.test/khoi')->assertOk()->getContent();
}

it('renders services as icon cards with one stretched link each', function (): void {
    $html = variantPage([['type' => 'entry_grid', 'props' => [
        'heading' => 'Dịch vụ', 'description' => 'Giải pháp trọn gói.', 'taxonomy_slug' => 'dich-vu', 'variant' => 'icon-cards', 'bg' => 'tint',
        'view_all_label' => 'Xem tất cả dịch vụ', 'view_all_url' => '/dich-vu',
    ]]]);

    expect($html)->toContain('<section class="sf-band sf-band--tint sf-cards sf-cards--icon">')
        ->and($html)->toContain('<h2 class="sf-section-head__title">Dịch vụ</h2>')
        ->and($html)->toContain('<p class="sf-section-head__text">Giải pháp trọn gói.</p>')
        ->and($html)->toContain('<h3 class="sf-card__title"><a class="sf-card__link" href="/dich-vu/thiet-ke-do-ga">Thiết kế đồ gá</a></h3>')
        ->and($html)->toContain('M16.5 10.5V6.75')                       // the lock glyph
        ->and($html)->toContain('stroke-width="1.5"')
        ->and($html)->toContain('<span class="sf-card__more" aria-hidden="true">Xem chi tiết')
        ->and($html)->toContain('<a class="sf-view-all__link" href="/dich-vu">Xem tất cả dịch vụ</a>')
        ->and(substr_count($html, 'class="sf-card__link"'))->toBe(2);
});

it('shows the view-all button only when both its label and link are set', function (array $props): void {
    $html = variantPage([['type' => 'entry_grid', 'props' => ['taxonomy_slug' => 'dich-vu', 'variant' => 'icon-cards'] + $props]]);

    expect($html)->not->toContain('sf-view-all');
})->with([
    'no label' => [['view_all_url' => '/dich-vu']],
    'no link' => [['view_all_label' => 'Xem tất cả']],
    'an unsafe link' => [['view_all_label' => 'Xem', 'view_all_url' => 'javascript:alert(1)']],
]);

it('renders products as photo cards with their category over a 4:3 frame', function (): void {
    $html = variantPage([['type' => 'product_grid', 'props' => ['heading' => 'Sản phẩm', 'variant' => 'overlay', 'bg' => 'surface']]]);

    expect($html)->toContain('sf-cards--product-overlay')
        ->and($html)->toContain('<div class="sf-card__frame sf-card__frame--4x3">')
        ->and($html)->toContain('<p class="sf-card__label">Đồ gá</p>')
        ->and($html)->toContain('<a class="sf-card__link" href="/jig-vf7">Jig kiểm VF7</a>')
        ->and($html)->toMatch('#srcset="https://files\.test/s/library/card-[0-9a-f]{12}\.webp\?w=320 320w, [^"]+960w"#')
        ->and($html)->not->toContain('1280w');                           // never wider than the 960px master
});

it('renders a rounded CTA with its sub-line, and the sub-line on the default look too', function (): void {
    $rounded = variantPage([['type' => 'cta', 'props' => ['heading' => 'Cần báo giá?', 'text' => 'Gửi bản vẽ, nhận giá trong 24 giờ.', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he', 'variant' => 'rounded']]]);

    expect($rounded)->toContain('<section class="cta cta--rounded">')
        ->and($rounded)->toContain('<p class="cta__text">Gửi bản vẽ, nhận giá trong 24 giờ.</p>')
        ->and($rounded)->toContain('<a class="cta__button cta__button--pill" href="/lien-he">Liên hệ</a>');

    StorefrontCards::reset();
    $default = variantPage([['type' => 'cta', 'props' => ['heading' => 'Cần báo giá?', 'text' => 'Một dòng.', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he']]]);
    expect($default)->toContain('<section class="cta">')->and($default)->toContain('<p class="cta__text">Một dòng.</p>');
});

it('renders contact cards with tel and mailto links and a hidden h2 when there is no heading', function (): void {
    $html = variantPage([['type' => 'contact', 'props' => [
        'variant' => 'cards', 'address' => 'KCN Thăng Long', 'phone' => '0901234567', 'email' => 'lienhe@mau.test', 'address_label' => 'Trụ sở & Nhà máy',
        'map_embed' => 'https://www.google.com/maps/embed?pb=1',
    ]]]);

    expect(substr_count($html, '<li class="sf-contact-card">'))->toBe(3)
        ->and($html)->toContain('<h2 class="sr-only">Thông tin liên hệ</h2>')
        ->and($html)->toContain('href="tel:0901234567"')
        ->and($html)->toContain('href="mailto:lienhe@mau.test"')
        ->and($html)->toContain('<h3 class="sf-contact-card__title">Trụ sở &amp; Nhà máy</h3>')
        ->and($html)->toContain('<h3 class="sf-contact-card__title">Điện thoại</h3>')
        ->and($html)->toContain('<iframe class="sf-map" src="https://www.google.com/maps/embed?pb=1"');
});

it('renders features as top-rule cards with their glyphs', function (): void {
    $html = variantPage([['type' => 'features', 'props' => ['heading' => 'Năng lực', 'variant' => 'top-rule', 'items' => [
        ['title' => 'Máy CNC', 'text' => '20 máy', 'icon' => 'machine'], ['title' => 'Kiểm tra', 'icon' => 'not-a-glyph'],
    ]]]]);

    expect($html)->toContain('<section class="sf-band sf-band--none sf-features">')
        ->and(substr_count($html, '<li class="sf-feature">'))->toBe(2)
        ->and($html)->toContain('M21.75 17.25v-.228')                    // the machine glyph
        ->and(substr_count($html, 'sf-feature__icon'))->toBe(1);         // an unknown key draws nothing
});

it('puts the form beside an allowed map, and ignores a map on another host', function (): void {
    $html = variantPage([['type' => 'form', 'props' => ['form_key' => 'contact', 'description' => 'Chúng tôi trả lời trong ngày.', 'map_embed' => 'https://www.google.com/maps/embed?pb=2']]]);

    expect($html)->toContain('<section class="lead-form lead-form--map" id="lf-contact">')
        ->and($html)->toContain('<p class="lead-form__description">Chúng tôi trả lời trong ngày.</p>')
        ->and($html)->toContain('<iframe class="lead-form__map" src="https://www.google.com/maps/embed?pb=2" title="Bản đồ"');

    StorefrontCards::reset();
    $foreign = variantPage([['type' => 'form', 'props' => ['form_key' => 'contact', 'map_embed' => 'https://evil.example/embed']]]);
    expect($foreign)->toContain('<section class="lead-form" id="lf-contact">')->and($foreign)->not->toContain('evil.example');
});

it('renders project cards on a dark band and news cards with the local date', function (): void {
    $projects = variantPage([['type' => 'entry_grid', 'props' => ['taxonomy_slug' => 'dich-vu', 'variant' => 'project-cards', 'bg' => 'dark']]]);
    expect($projects)->toContain('sf-band--dark sf-cards sf-cards--project')
        ->and($projects)->toContain('<div class="sf-card__frame sf-card__frame--8x5">')
        ->and($projects)->toContain('<span class="sf-card__shade" aria-hidden="true"></span>');

    StorefrontCards::reset();
    $news = variantPage([['type' => 'entry_grid', 'props' => ['taxonomy_slug' => 'tin-tuc', 'variant' => 'news-cards']]]);
    expect($news)->toContain('<time class="sf-card__date" datetime="2026-10-06">06/10/2026</time>')   // 23:30 UTC is the next day in Vietnam
        ->and($news)->toContain('<span class="sf-card__more" aria-hidden="true">Đọc thêm');
});

it('renders the default look for a stored look that is not in the list', function (string $type, array $props): void {
    $default = variantPage([['type' => $type, 'props' => $props]]);
    StorefrontCards::reset();
    $bogus = variantPage([['type' => $type, 'props' => $props + ['variant' => 'not-a-look', 'bg' => 'neon']]]);

    // The page body only: Livewire injects its assets once per test process, not per page.
    $main = fn (string $html): string => (string) preg_replace('#^.*?(<main\b.*</main>).*$#s', '$1', $html);
    expect($main($bogus))->toBe($main($default))->and($main($default))->toStartWith('<main');
})->with([
    'entry_grid' => ['entry_grid', ['heading' => 'Dịch vụ', 'taxonomy_slug' => 'dich-vu']],
    'product_grid' => ['product_grid', ['heading' => 'Sản phẩm']],
    'cta' => ['cta', ['heading' => 'Cần báo giá?', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he']],
    'contact' => ['contact', ['heading' => 'Liên hệ', 'phone' => '0901234567']],
    'features' => ['features', ['heading' => 'Năng lực', 'items' => [['title' => 'Máy CNC']]]],
]);
