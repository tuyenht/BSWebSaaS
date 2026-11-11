<?php

use App\Modules\Cms\Actions\Taxonomies\SetCategoryLayoutBlocks;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\SectionProps;
use Illuminate\Support\Facades\View;
use Tests\Fixtures\StorefrontCards;

/*
 * Pages assembled from blocks: the capability list (numbered rows, bands before and after), the job
 * list (its heading line and the empty state of its own), and the block looks the pages choose
 * (cta card, numbered steps, captions on the photo, a wide video with its source line).
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

function assemblyCta(string $heading): array
{
    return ['vi' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => $heading]]]]];
}

it('lists a capability category as numbered rows between its before and after bands', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $category = StorefrontCards::category('Năng lực', 'nang-luc', null, 'grid-capability');
        StorefrontCards::post($category, 'Gia công CNC', 'gia-cong-cnc', null, [], 'Phay tiện chính xác.');
        StorefrontCards::post($category, 'Khuôn mẫu', 'khuon-mau', null, [], 'Thiết kế khuôn.');
        app(SetCategoryLayoutBlocks::class)->handle($category, ['before_list' => assemblyCta('Trước danh sách'), 'after_list' => assemblyCta('Sau danh sách')]);
    });

    $html = test()->get('http://mau.platform.test/nang-luc')->assertOk()->getContent();

    expect(substr_count($html, 'class="sf-cap'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('sf-cap--flip')
        ->and($html)->toContain('Gia công CNC')
        ->and(strpos($html, 'Trước danh sách'))->toBeLessThan(strpos($html, 'Gia công CNC'))
        ->and(strpos($html, 'Gia công CNC'))->toBeLessThan(strpos($html, 'Sau danh sách'));
});

it('gives an empty job list its own message and a way to send a CV', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        StorefrontCards::category('Tuyển dụng', 'tuyen-dung', null, 'grid-job');
    });

    test()->get('http://mau.platform.test/tuyen-dung')->assertOk()
        ->assertSee('sf-empty-box--jobs', false)
        ->assertSee(__('storefront.no_jobs'))
        ->assertSee(__('storefront.jobs_empty_hint'));
});

it('draws the cta as a card, the steps numbered, captions on the photo and a wide video with its source', function (): void {
    $cta = View::make('theme::sections.cta', ['props' => ['variant' => 'card', 'heading' => 'Không thấy sản phẩm bạn cần?', 'button_label' => 'Gửi', 'button_url' => '/lien-he', 'button2_label' => 'Zalo', 'button2_url' => '/z', 'button2_tone' => 'zalo']])->render();
    expect($cta)->toContain('cta--card')->toContain('cta__button--zalo');

    $steps = View::make('theme::sections.process', ['props' => ['variant' => 'numeral', 'steps' => [['title' => 'Một'], ['title' => 'Hai'], ['title' => 'Ba']]]])->render();
    expect($steps)->toContain('sf-steps--numeral');

    $video = View::make('theme::sections.video', ['props' => ['url' => 'https://www.youtube.com/watch?v=FpYQc5zMLzM', 'player_width' => 'wide', 'caption' => 'Nguồn kênh mẫu, CC BY']])->render();
    expect($video)->toContain('sf-video-block--wide')->toContain('Nguồn kênh mẫu, CC BY');
});

it('keeps the new block choices inside their lists', function (): void {
    expect(SectionProps::CTA_VARIANTS)->toContain('card')
        ->and(SectionProps::GALLERY_CAPTIONS)->toBe(['below', 'overlay'])
        ->and(SectionProps::VIDEO_WIDTHS)->toBe(['narrow', 'wide']);
});
