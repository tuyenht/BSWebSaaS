<?php

use App\Modules\Theme\Components\ListDisplay;
use Illuminate\Support\Facades\View;

/*
 * The card and rail looks that follow the design's boards: the short news card of a block with the
 * display group, the phone's swipe hint, and the partners' note. Blocks saved before the display group
 * keep their bytes (the golden files of ListDisplayOldBlocksTest).
 */

/** @param array<string, mixed> $data */
function cardView(string $view, array $data): string
{
    app()->setLocale('vi');

    return View::make($view, $data)->render();
}

/** @return array<string, mixed> */
function newsCard(): array
{
    return ['title' => 'Tin số một ạ', 'url' => '/tin-1', 'image' => '', 'srcset' => null, 'icon' => '', 'excerpt' => 'Đoạn trích dài của tin', 'date' => '01/06/2024', 'datetime' => '2024-06-01', 'label' => 'Tin nội bộ'];
}

it('draws the short news card of a block: the date, the title and read more, no chip and no excerpt', function (): void {
    $html = cardView('theme::partials.sf-card', ['card' => newsCard(), 'kind' => 'news', 'short' => true]);

    expect($html)->toContain('sf-card__meta sf-card__meta--plain')->toContain('01/06/2024')->toContain('Tin số một ạ')
        ->not->toContain('sf-card__chip')->not->toContain('Tin nội bộ')->not->toContain('sf-card__text')->not->toContain('Đoạn trích');
});

it('keeps the chip and the excerpt on a list page card and on a card of a block without the display group', function (array $extra): void {
    $html = cardView('theme::partials.sf-card', ['card' => newsCard(), 'kind' => 'news'] + $extra);

    expect($html)->toContain('sf-card__chip')->toContain('Tin nội bộ')->toContain('Đoạn trích dài của tin')->not->toContain('sf-card__meta--plain');
})->with([
    'a list page card' => [['list' => true, 'short' => true]],
    'no short flag' => [[]],
]);

it('draws a news block with the display group short and one without it as before', function (): void {
    $props = ['heading' => 'Tin', 'variant' => 'news-cards'];
    $items = [newsCard()];
    $with = cardView('theme::sections.entry_grid', ['props' => $props + ['layout' => 'grid'], 'variant' => 'news-cards', 'items' => $items, 'display' => ListDisplay::plan('news', ['layout' => 'grid'], 1, 4)]);
    $without = cardView('theme::sections.entry_grid', ['props' => $props, 'variant' => 'news-cards', 'items' => $items, 'display' => null]);

    expect($with)->toContain('sf-card__meta--plain')->not->toContain('Đoạn trích')
        ->and($without)->toContain('sf-card__chip')->toContain('Đoạn trích dài của tin');
});

it('says "swipe sideways" under a phone swipe row, but not under a row whose arrows always show', function (): void {
    $swipe = ['mobile' => 'swipe', 'arrows' => 'auto', 'interval' => 0, 'scrolls' => false, 'bar' => '', 'shown' => 3];
    $row = fn (array $display): string => cardView('theme::partials.sf-rail-close', ['display' => $display]);

    expect($row($swipe))->toContain('sf-rail__hint')->toContain('Vuốt ngang để xem thêm')
        ->and($row(['arrows' => 'always'] + $swipe))->not->toContain('sf-rail__hint')
        ->and($row(['mobile' => 'one'] + $swipe))->not->toContain('sf-rail__hint');
});

it('prints the partners note under the logos only when it is set, as plain text', function (): void {
    $logos = [['image_url' => 'https://files.test/l/1.webp', 'name' => 'A ư']];
    $with = cardView('theme::sections.partners', ['props' => ['layout' => 'grid', 'logos' => $logos, 'note' => 'Logo <b>theo</b> hồ sơ']]);
    $without = cardView('theme::sections.partners', ['props' => ['layout' => 'grid', 'logos' => $logos]]);

    expect($with)->toContain('<p class="partners__note">Logo &lt;b&gt;theo&lt;/b&gt; hồ sơ</p>')->and($without)->not->toContain('partners__note');
});
