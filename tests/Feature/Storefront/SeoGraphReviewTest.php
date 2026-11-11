<?php

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Storefront\Support\ProductSpecs;
use App\Modules\Storefront\Support\TitleDisambiguator;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Seo\StructuredData;
use Tests\Fixtures\StorefrontCards;

/*
 * Edge cases of the structured-data graph and the unique titles: what the title check must ignore,
 * the typed organization references, the home ids, long category descriptions, specification header
 * rows, the preview and a site without a name.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

const SG_HOST = 'http://mau.platform.test';

/** The title of $name under $parent as the check decides it, inside the site's context. */
function sgTitle(int $site, string $name, string $locale = 'vi', ?int $entryId = null, ?int $taxonomyId = null): string
{
    return app(Tenancy::class)->enter($site, fn () => app(TitleDisambiguator::class)->title($name, 'Cha', $locale, $entryId, $taxonomyId));
}

it('compares a title only with published pages and listed categories of the same site and language', function (): void {
    $site = StorefrontCards::site();
    $other = StorefrontCards::site('Xưởng Khác', 'khac.platform.test');
    app(Tenancy::class)->enter($other, fn () => StorefrontCards::page('/trung-ten', 'Trùng tên', []));
    app(Tenancy::class)->enter($site, function (): void {
        $en = StorefrontCards::page('/trung-ten-en', 'Trùng tên', []);
        $en->translations()->update(['locale' => 'en']);
        $draft = StorefrontCards::page('/ban-nhap', 'Trùng tên', []);
        $draft->update(['status' => 'draft']);
        $draft->translations()->update(['status' => 'draft']);
        $trashed = StorefrontCards::page('/da-xoa', 'Trùng tên', []);
        $trashed->delete();
        StorefrontCards::post(StorefrontCards::news(), 'Trùng tên', 'trung-ten-bai');
        $tag = Taxonomy::query()->create(['vocabulary' => 'tag']);
        $tag->translations()->create(['locale' => 'vi', 'name' => 'Trùng tên', 'slug' => 'trung-ten-tag']);
    });

    expect(sgTitle($site, 'Trùng tên'))->toBe('Trùng tên');

    app(Tenancy::class)->enter($site, fn () => StorefrontCards::page('/that', 'TRÙNG TÊN', []));
    expect(sgTitle($site, 'Trùng tên'))->toBe('Trùng tên · Cha');
});

it('does not compare a page with its own other translation', function (): void {
    $site = StorefrontCards::site();
    $id = app(Tenancy::class)->enter($site, function (): int {
        $page = StorefrontCards::page('/gioi-thieu', 'Về chúng tôi', []);
        $page->translations()->create(['locale' => 'en', 'title' => 'Về chúng tôi', 'slug' => 'about', 'status' => 'published', 'body' => ['blocks' => []]]);

        return $page->id;
    });

    expect(sgTitle($site, 'Về chúng tôi', 'en', $id))->toBe('Về chúng tôi')
        ->and(sgTitle($site, 'Về chúng tôi', 'en'))->toBe('Về chúng tôi · Cha');
});

it('prints the product brand by name and never a manufacturer', function (): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Xưởng Mẫu']]);
        StorefrontCards::product('Sản phẩm thử', 'san-pham-thu');
    });

    $product = ldNode($this->get(SG_HOST.'/san-pham-thu')->assertOk()->getContent(), 'Product');

    expect($product['brand'])->toBe(['@type' => 'Brand', 'name' => 'Xưởng Mẫu'])->and($product)->not->toHaveKey('manufacturer');
});

it('types and names the organization in Article, publisher and JobPosting nodes of an inner page', function (): void {
    $page = [
        'brand' => ['name' => 'Xưởng Mẫu', 'logo' => 'https://files.test/logo.png'],
        'canonical' => url('/tin-tuc/bai'),
        'articleLd' => ['@type' => 'Article', 'headline' => 'Bài', 'author' => ['@type' => 'Organization', 'name' => 'Xưởng Mẫu']],
        'jobLd' => ['@type' => 'JobPosting', 'title' => 'Thợ', 'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Xưởng Mẫu']],
    ];
    $graph = StructuredData::build($page)['@graph'];
    $article = collect($graph)->firstWhere('@type', 'Article');
    $job = collect($graph)->firstWhere('@type', 'JobPosting');
    $org = ['@type' => 'Organization', '@id' => rtrim(url('/'), '/').'/#organization', 'name' => 'Xưởng Mẫu'];

    expect($article['author'])->toBe($org)
        ->and($article['publisher'])->toBe($org + ['logo' => ['@type' => 'ImageObject', 'url' => 'https://files.test/logo.png']])
        ->and($job['hiringOrganization'])->toBe($org);
});

it('ends the home page id with a slash like the organization url', function (): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Xưởng Mẫu']]));

    $html = $this->get(SG_HOST.'/')->assertOk()->getContent();

    expect(ldNode($html, 'WebPage')['@id'])->toBe(SG_HOST.'/#webpage')
        ->and(ldNode($html, 'Organization')['url'])->toBe(SG_HOST.'/');
});

it('cuts a long category description to 160 characters for the meta description', function (): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        $category = StorefrontCards::category('Khuôn ép', 'khuon-ep');
        TaxonomyTranslation::query()->where('taxonomy_id', $category->id)->update(['description' => trim(str_repeat('từ ngữ dài ', 100))]);
    });

    $html = $this->get(SG_HOST.'/khuon-ep')->assertOk()->getContent();
    preg_match('/<meta name="description" content="([^"]*)">/', $html, $m);
    $description = html_entity_decode($m[1]);

    expect(mb_strlen($description))->toBeLessThanOrEqual(160)->toBeGreaterThan(100)->and($description)->toEndWith('ngữ');
});

it('skips a header row of th cells in the specification rows', function (): void {
    $rows = ProductSpecs::properties('<table><tr><th>Thông số</th><th>Giá trị</th></tr><tr><th>Chất liệu</th><td>Thép</td></tr><tr><td>Độ cứng</td><td>58 HRC</td></tr></table>');

    expect($rows)->toBe([
        ['@type' => 'PropertyValue', 'name' => 'Chất liệu', 'value' => 'Thép'],
        ['@type' => 'PropertyValue', 'name' => 'Độ cứng', 'value' => '58 HRC'],
    ]);
});

it('prints no JSON-LD in the preview and still prints valid JSON-LD for a site without a name', function (): void {
    $page = ['canonical' => SG_HOST.'/gioi-thieu', 'title' => 'Giới thiệu', 'websiteLd' => null];

    expect(StructuredData::json($page + ['preview' => true, 'brand' => ['name' => 'Xưởng Mẫu']]))->toBeNull();

    $json = StructuredData::json($page + ['brand' => [], 'articleLd' => ['@type' => 'Article', 'headline' => "Bài \xB1 lỗi"]]);
    $data = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

    expect($data['@graph'])->not->toBeEmpty()
        ->and(collect($data['@graph'])->firstWhere('@type', 'Article')['headline'])->toContain('Bài');
});
