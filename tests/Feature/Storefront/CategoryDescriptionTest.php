<?php

use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Tests\Fixtures\StorefrontCards;

/*
 * A category list page describes itself: its own Google description, else its description, else a
 * sentence made from its name, its parent and the site's name. The one brand sentence is never the
 * description of a list.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

const CD_HOST = 'http://mau.platform.test';

function cdSite(): int
{
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Xưởng Mẫu', 'description' => 'Câu mô tả thương hiệu dùng chung']],
    ));

    return $site;
}

function cdDescription(string $html): ?string
{
    return preg_match('/<meta name="description" content="([^"]*)">/', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
}

it('describes a category by its own Google description, then its description', function (): void {
    $site = cdSite();
    app(Tenancy::class)->enter($site, function (): void {
        $own = StorefrontCards::category('Khuôn ép', 'khuon-ep');
        TaxonomyTranslation::query()->where('taxonomy_id', $own->id)->update(['seo_description' => 'Mô tả Google riêng', 'description' => 'Mô tả chuyên mục']);
        $plain = StorefrontCards::category('Khuôn dập', 'khuon-dap');
        TaxonomyTranslation::query()->where('taxonomy_id', $plain->id)->update(['description' => "Mô tả   chuyên mục\ndập"]);
    });

    expect(cdDescription($this->get(CD_HOST.'/khuon-ep')->assertOk()->getContent()))->toBe('Mô tả Google riêng')
        ->and(cdDescription($this->get(CD_HOST.'/khuon-dap')->assertOk()->getContent()))->toBe('Mô tả chuyên mục dập');
});

it('makes a sentence from the name, the parent and the site when a category says nothing', function (): void {
    $site = cdSite();
    app(Tenancy::class)->enter($site, function (): void {
        $parent = StorefrontCards::category('Dự án', 'du-an');
        StorefrontCards::category('Nhà xưởng', 'nha-xuong', $parent);
        StorefrontCards::category('Cầu đường', 'cau-duong');
    });

    $child = cdDescription($this->get(CD_HOST.'/nha-xuong')->assertOk()->getContent());
    $top = cdDescription($this->get(CD_HOST.'/cau-duong')->assertOk()->getContent());

    expect($child)->toContain('Nhà xưởng')->toContain('Dự án')->toContain('Xưởng Mẫu')
        ->and($top)->toContain('Cầu đường')->toContain('Xưởng Mẫu')->not->toContain('Dự án')
        ->and($child)->not->toBe($top)
        ->and($child)->not->toContain('Câu mô tả thương hiệu dùng chung')
        ->and($top)->not->toContain('Câu mô tả thương hiệu dùng chung');
});
