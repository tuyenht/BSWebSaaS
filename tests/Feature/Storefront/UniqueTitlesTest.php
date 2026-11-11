<?php

use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Tests\Fixtures\StorefrontCards;

/*
 * Every <title> of a site is unique: two published pages or categories of one language that share a
 * name are told apart by their parent's name ("Name · Parent"); a name that is not taken, a top-level
 * page and a title staff wrote are left as they are.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

const UT_HOST = 'http://mau.platform.test';

function utSite(): int
{
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'seo', 'key' => 'config'], ['value' => ['template' => '%tieu-de% | %ten-website%']],
    ));
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Xưởng Mẫu']],
    ));

    return $site;
}

function utTitle(string $html): string
{
    preg_match('#<title>(.*?)</title>#s', $html, $m);

    return html_entity_decode($m[1] ?? '');
}

it('adds the parent to a category whose name another category already has', function (): void {
    $site = utSite();
    app(Tenancy::class)->enter($site, function (): void {
        $products = StorefrontCards::category('Sản phẩm', 'san-pham');
        $projects = StorefrontCards::category('Dự án', 'du-an');
        StorefrontCards::category('Khuôn mẫu', 'khuon-mau-san-pham', $products);
        StorefrontCards::category('Khuôn mẫu', 'khuon-mau-du-an', $projects);
    });

    $one = utTitle($this->get(UT_HOST.'/khuon-mau-san-pham')->assertOk()->getContent());
    $two = utTitle($this->get(UT_HOST.'/khuon-mau-du-an')->assertOk()->getContent());

    expect($one)->toBe('Khuôn mẫu · Sản phẩm | Xưởng Mẫu')
        ->and($two)->toBe('Khuôn mẫu · Dự án | Xưởng Mẫu')
        ->and($one)->not->toBe($two)
        // The top-level categories keep the plain form.
        ->and(utTitle($this->get(UT_HOST.'/du-an')->getContent()))->toBe('Dự án | Xưởng Mẫu');
});

it('leaves a child whose name is not taken, and a page under a parent that shares its name only with a category, as needed', function (): void {
    $site = utSite();
    app(Tenancy::class)->enter($site, function (): void {
        $parent = StorefrontCards::category('Dịch vụ', 'dich-vu');
        StorefrontCards::category('Gia công', 'gia-cong', $parent);
        $about = StorefrontCards::page('/gioi-thieu', 'Giới thiệu', []);
        $team = StorefrontCards::page('/gioi-thieu/gia-cong', 'Gia công', []);
        $team->update(['parent_id' => $about->id]);
    });

    // The category "Gia công" and the page "Gia công" share a name: both add their parent.
    expect(utTitle($this->get(UT_HOST.'/gia-cong')->getContent()))->toBe('Gia công · Dịch vụ | Xưởng Mẫu')
        ->and(utTitle($this->get(UT_HOST.'/gioi-thieu/gia-cong')->getContent()))->toBe('Gia công · Giới thiệu | Xưởng Mẫu')
        ->and(utTitle($this->get(UT_HOST.'/gioi-thieu')->getContent()))->toBe('Giới thiệu | Xưởng Mẫu');
});

it('keeps a child page’s plain title when its name is unique', function (): void {
    $site = utSite();
    app(Tenancy::class)->enter($site, function (): void {
        $about = StorefrontCards::page('/gioi-thieu', 'Giới thiệu', []);
        StorefrontCards::page('/gioi-thieu/lich-su', 'Lịch sử', [])->update(['parent_id' => $about->id]);
    });

    expect(utTitle($this->get(UT_HOST.'/gioi-thieu/lich-su')->getContent()))->toBe('Lịch sử | Xưởng Mẫu');
});

it('never changes a title staff wrote', function (): void {
    $site = utSite();
    app(Tenancy::class)->enter($site, function (): void {
        $about = StorefrontCards::page('/gioi-thieu', 'Giới thiệu', []);
        $first = StorefrontCards::page('/gioi-thieu/doi-ngu', 'Đội ngũ', []);
        $first->update(['parent_id' => $about->id]);
        $first->translations()->update(['seo_title' => 'Đội ngũ kỹ sư của chúng tôi']);
        StorefrontCards::page('/doi-ngu', 'Đội ngũ', []);
        $products = StorefrontCards::category('Sản phẩm', 'san-pham');
        $own = StorefrontCards::category('Đội ngũ', 'doi-ngu-sp', $products);
        TaxonomyTranslation::query()->where('taxonomy_id', $own->id)->update(['seo_title' => 'Đội ngũ bán hàng']);
    });

    expect(utTitle($this->get(UT_HOST.'/gioi-thieu/doi-ngu')->getContent()))->toBe('Đội ngũ kỹ sư của chúng tôi')
        ->and(utTitle($this->get(UT_HOST.'/doi-ngu-sp')->getContent()))->toBe('Đội ngũ bán hàng');
});
