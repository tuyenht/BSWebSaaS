<?php

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * Template sets on the storefront: a category that follows its parent shows the parent's job look,
 * the reserved value never reaches a view lookup (post lists and product lists alike), a stored
 * layout of the wrong role or one the theme lacks renders the default instead of failing, and a
 * post on a job layout carries its JobPosting data but no made-up deadline.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/**
 * Job categories under "Đội ngũ", one place that follows its parent (and, with $deep, a third level
 * that follows it too), a plain news category, and the business address the JobPosting data needs.
 */
function jobSite(bool $deep = false): int
{
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($deep): void {
        $jobs = StorefrontCards::category('Đội ngũ', 'tin-tuyen-dung', null, 'grid-job');
        $jobs->forceFill(['detail_template' => 'job'])->save();
        $place = StorefrontCards::category('Ứng viên mới', 'ha-noi', $jobs, '@parent', 1);
        $place->forceFill(['detail_template' => '@parent'])->save();
        $leaf = $place;
        if ($deep) {
            $leaf = StorefrontCards::category('Đội thợ mới', 'thach-that', $place, '@parent', 1);
            $leaf->forceFill(['detail_template' => '@parent'])->save();
        }
        $at = CarbonImmutable::parse('2026-10-01 08:00', 'UTC');
        StorefrontCards::post($leaf, 'Thợ tiện CNC', 'tho-tien-cnc', $at, [], 'Vận hành máy tiện CNC.');
        StorefrontCards::post(StorefrontCards::news(), 'Tin ngày', 'tin-ngay', $at, [], 'Một tin.');
        SiteProfile::query()->updateOrCreate([], [
            'brand_name' => 'Cơ khí Mẫu', 'address_line' => 'Thôn Xuân Sơn', 'ward' => 'Xã Trung Giã', 'province' => 'TP Hà Nội',
        ]);
    });

    return $siteId;
}

function jobPage(string $path): string
{
    return test()->get('http://mau.platform.test'.$path)->assertOk()->getContent();
}

it('shows a place that follows its parent on the job card list', function (): void {
    jobSite();

    expect(jobPage('/ha-noi'))->toContain('sf-card--news')->toContain('Thợ tiện CNC')
        ->and(jobPage('/tin-tuyen-dung'))->toContain('sf-card--news');
});

it('prints JobPosting data for a post on a job layout, with no deadline until posts have one', function (): void {
    jobSite();
    $html = jobPage('/ha-noi/tho-tien-cnc');

    expect($html)->toContain('"@type":"JobPosting"')
        ->toContain('"title":"Thợ tiện CNC"')
        ->not->toContain('validThrough');
});

/** The JobPosting object printed in the page head, or null when the page carries none. */
function jobLd(string $html): ?array
{
    preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $found);
    foreach ($found[1] as $json) {
        $data = json_decode($json, true);
        if (is_array($data) && ($data['@type'] ?? null) === 'JobPosting') {
            return $data;
        }
    }

    return null;
}

it('prints the employer, the workplace address and the excerpt in the JobPosting data', function (): void {
    jobSite();
    $ld = jobLd(jobPage('/ha-noi/tho-tien-cnc'));

    expect($ld['hiringOrganization'])->toBe(['@type' => 'Organization', 'name' => 'Cơ khí Mẫu'])
        ->and($ld['jobLocation'])->toBe(['@type' => 'Place', 'address' => [
            '@type' => 'PostalAddress', 'streetAddress' => 'Thôn Xuân Sơn', 'addressLocality' => 'Xã Trung Giã',
            'addressRegion' => 'TP Hà Nội', 'addressCountry' => 'VN',
        ]])
        ->and($ld['description'])->toBe('Vận hành máy tiện CNC.');
});

it('describes a job post with no excerpt by the first paragraph of its body, not its title', function (): void {
    $siteId = jobSite();
    app(Tenancy::class)->enter($siteId, function (): void {
        $translation = EntryTranslation::query()->where('slug', 'tho-tien-cnc')->firstOrFail();
        $translation->forceFill(['excerpt' => null, 'body' => ['blocks' => [['type' => 'html', 'props' => ['html' => '<h2>Mô tả</h2><p>Tuyển thợ <b>tiện</b> CNC   có tay nghề.</p><p>Hai.</p>']]]]])->save();
    });

    expect(jobLd(jobPage('/ha-noi/tho-tien-cnc'))['description'])->toBe('Tuyển thợ tiện CNC có tay nghề.');
});

it('prints no JobPosting data when the site states no address or no organization name', function (array $profile): void {
    $siteId = jobSite();
    app(Tenancy::class)->enter($siteId, function () use ($profile): void {
        SiteProfile::query()->update($profile);
    });
    $html = jobPage('/ha-noi/tho-tien-cnc');

    expect(jobLd($html))->toBeNull()->and($html)->not->toContain('"@type":"JobPosting"');
})->with([
    'no address' => [['address_line' => null, 'ward' => null, 'province' => null]],
    'no organization name' => [['brand_name' => null]],
]);

it('prints no JobPosting data for a post on the standard layout', function (): void {
    jobSite();

    expect(jobPage('/tin-tuc/tin-ngay'))->not->toContain('JobPosting');
});

it('prints JobPosting data when the post itself picks the job layout', function (): void {
    $siteId = jobSite();
    app(Tenancy::class)->enter($siteId, function (): void {
        DB::table('entries')->where('type', 'post')->update(['template_override' => 'job']);
    });

    expect(jobPage('/tin-tuc/tin-ngay'))->toContain('JobPosting');
});

it('never lets the follow-the-parent value reach a view lookup on a post list or a product list', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        // Following a parent that has nothing usable above it, in a post tree and in the catalog.
        $orphan = StorefrontCards::category('Lẻ loi', 'le-loi', null, '@parent');
        $orphan->forceFill(['detail_template' => '@parent'])->save();
        $news = StorefrontCards::news();
        StorefrontCards::category('Tin con', 'tin-con', $news, '@parent', 1);
        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham', null, null);
        StorefrontCards::catalog($catalog);
        StorefrontCards::category('Đồ gá', 'do-ga', $catalog, '@parent', 1);
        $photo = StorefrontCards::media('anh');
        StorefrontCards::product('Jig kiểm', 'jig-kiem', $catalog, $photo);
    });

    foreach (['/le-loi', '/tin-con', '/san-pham', '/do-ga'] as $path) {
        $html = test()->get('http://mau.platform.test'.$path)->assertOk()->getContent();
        expect($html)->not->toContain('@parent');
    }
});

it('gives a product category under a post category the default list, not the post look', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham', null, 'grid-job');
        StorefrontCards::catalog($catalog);
        $kid = StorefrontCards::category('Đồ gá', 'do-ga', $catalog, '@parent', 1);
        $kid->forceFill(['vocabulary' => 'product_cat'])->save();
        StorefrontCards::product('Jig kiểm', 'jig-kiem', $kid, StorefrontCards::media('anh'));
    });

    expect(test()->get('http://mau.platform.test/do-ga')->assertOk()->getContent())->not->toContain('sf-card--news');
});

it('renders the default for a stored layout of the wrong role or one the theme no longer has', function (string $list, string $detail): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($list, $detail): void {
        $category = StorefrontCards::category('Nhóm', 'nhom', null, $list);
        $category->forceFill(['detail_template' => $detail])->save();
        StorefrontCards::post($category, 'Bài một', 'bai-mot');
    });

    test()->get('http://mau.platform.test/nhom')->assertOk()->assertDontSee('sf-card--news');
    test()->get('http://mau.platform.test/nhom/bai-mot')->assertOk()->assertSee('post-band');
})->with([
    'a post layout as the list, a list layout as the post layout' => ['post', 'grid-news'],
    'layouts the theme lacks' => ['khong-co', 'khong-co'],
    'the not-found layout' => ['not-found', 'not-found'],
]);

it('renders the default for an own layout of the wrong role stored on a post or a page', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $post = StorefrontCards::post(StorefrontCards::news(), 'Tin ngày', 'tin-ngay');
        $post->forceFill(['template_override' => 'grid-news'])->save();
        StorefrontCards::page('/gioi-thieu', 'Giới thiệu', [])->forceFill(['template_override' => 'job'])->save();
    });

    test()->get('http://mau.platform.test/tin-tuc/tin-ngay')->assertOk()->assertSee('post-band');
    test()->get('http://mau.platform.test/gioi-thieu')->assertOk();
});

it('reads the parent chain once for a page that follows a three-level chain and not at all otherwise', function (): void {
    $siteId = jobSite(deep: true);
    $count = fn (string $path): int => countQueries(fn () => test()->get('http://mau.platform.test'.$path)->assertOk());
    // Per-site reads that outlive a request are loaded by a first visit.
    $count('/thach-that');
    app()->forgetScopedInstances();
    $following = $count('/thach-that');
    app(Tenancy::class)->enter($siteId, function (): void {
        Taxonomy::query()->where('list_template', '@parent')->update(['list_template' => 'grid-job']);
    });
    app()->forgetScopedInstances();
    $plain = $count('/thach-that');

    expect($following - $plain)->toBeLessThanOrEqual(1);
});

it('reads the parent chain once for a post that follows a three-level chain', function (): void {
    $siteId = jobSite(deep: true);
    $count = fn (string $path): int => countQueries(fn () => test()->get('http://mau.platform.test'.$path)->assertOk());
    $count('/thach-that/tho-tien-cnc');
    app()->forgetScopedInstances();
    $following = $count('/thach-that/tho-tien-cnc');
    app(Tenancy::class)->enter($siteId, function (): void {
        Taxonomy::query()->where('detail_template', '@parent')->update(['detail_template' => 'job']);
    });
    app()->forgetScopedInstances();
    $plain = $count('/thach-that/tho-tien-cnc');

    expect($following - $plain)->toBeLessThanOrEqual(1);
});
