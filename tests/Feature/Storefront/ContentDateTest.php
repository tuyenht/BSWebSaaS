<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Storefront\Support\ArticleData;
use App\Modules\Storefront\Support\PostDetail;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * One content date for a page (its content mark, its updated_at while it has none, never earlier than
 * the page went public): a post's Article dateModified and its "Cập nhật" line show it, and the
 * sitemap's lastmod is the later of it and the route's own change, so a new address shows in the
 * sitemap without dating the content.
 */

const CONTENT_DATE_HOST = 'http://mau.platform.test';

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** The lastmod the sitemap prints for the URL ending in $path, or null when it prints none. */
function sitemapLastmod(string $xml, string $path): ?string
{
    return preg_match('#'.preg_quote($path, '#').'</loc><lastmod>([^<]+)</lastmod>#', $xml, $m) === 1 ? $m[1] : null;
}

/** The Article data in a page head. @return array<string, mixed> */
function contentDateArticle(string $html): array
{
    preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $found);
    foreach ($found[1] as $json) {
        $data = json_decode($json, true);
        if (is_array($data) && ($data['@type'] ?? null) === 'Article') {
            return $data;
        }
    }

    throw new RuntimeException('No Article data in the page.');
}

it('keeps a post\'s dates when only its address moves, while the sitemap shows the move', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $post = StorefrontCards::post(StorefrontCards::news(), 'Ấn phẩm máy phay mới về phố', 'an-pham-may-phay', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));
        DB::table('entry_translations')->where('entry_id', $post->id)->update(['content_changed_at' => '2026-10-05 09:30:00+00', 'updated_at' => '2026-10-05 09:30:00+00']);
        DB::table('routes')->where('entry_id', $post->id)->update(['updated_at' => '2026-10-01 08:00:00+00']);

        // The address moves as an editor save of the slug moves it: the language's slug, then its route.
        EntryTranslation::query()->where('entry_id', $post->id)->firstOrFail()->update(['slug' => 'an-pham-dia-chi-moi']);
        SiteRoute::query()->where('entry_id', $post->id)->firstOrFail()->update(['path' => '/tin-tuc/an-pham-dia-chi-moi']);
    });

    $html = $this->get(CONTENT_DATE_HOST.'/tin-tuc/an-pham-dia-chi-moi')->assertOk()->getContent();
    expect(contentDateArticle($html)['dateModified'])->toBe('2026-10-05T09:30:00+00:00')
        ->and($html)->toContain('Cập nhật: <time datetime="2026-10-05">');

    $lastmod = sitemapLastmod($this->get(CONTENT_DATE_HOST.'/sitemap.xml')->assertOk()->getContent(), '/tin-tuc/an-pham-dia-chi-moi');
    expect($lastmod)->not->toBeNull()
        ->and(CarbonImmutable::parse((string) $lastmod)->greaterThan(CarbonImmutable::parse('2026-10-05 09:30:00', 'UTC')))->toBeTrue();
});

it('dates a language published after the post by its own content, and its sitemap entry by its route', function (): void {
    $siteId = StorefrontCards::site();
    [$post, $en] = app(Tenancy::class)->enter($siteId, function (): array {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $post = StorefrontCards::post(StorefrontCards::news(), 'Ấn phẩm máy phay mới về phố', 'an-pham-may-phay', CarbonImmutable::parse('2026-01-01 08:00', 'UTC'));
        // Written on 2 January, published on 1 March (the route is made when the language goes public).
        $en = $post->translations()->create(['locale' => 'en', 'title' => 'Ấn phẩm tiếng Anh về phố', 'slug' => 'an-pham-en', 'status' => 'published', 'body' => ['blocks' => []]]);
        DB::table('entry_translations')->where('id', $en->id)->update(['content_changed_at' => '2026-01-02 08:00:00+00', 'updated_at' => '2026-03-01 08:00:00+00']);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/tin-tuc/an-pham-en', 'entry_id' => $post->id]);
        DB::table('routes')->where('locale', 'en')->update(['updated_at' => '2026-03-01 08:00:00+00']);

        return [$post->fresh(), EntryTranslation::query()->findOrFail($en->id)];
    });

    app(Tenancy::class)->enter($siteId, function () use ($post, $en): void {
        expect(ArticleData::ld($post, $en, CONTENT_DATE_HOST.'/en/tin-tuc/an-pham-en', CONTENT_DATE_HOST.'/en', 'en', null, null)['dateModified'])->toBe('2026-01-02T08:00:00+00:00')
            ->and(app(PostDetail::class)->band($post, $en, 'en')['updatedDatetime'])->toBe('2026-01-02');
    });
    app()->forgetScopedInstances();

    expect(sitemapLastmod($this->get(CONTENT_DATE_HOST.'/sitemap.xml')->assertOk()->getContent(), '/tin-tuc/an-pham-en'))->toBe('2026-03-01T08:00:00+00:00');
});

it('never dates a sitemap entry before the page went public, and leaves an empty publish time out', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        StorefrontCards::product('Đồ gá kiểm tra độ', 'do-ga-kiem', publishedAt: CarbonImmutable::parse('2026-04-05 06:07:08', 'UTC'));
        $unpublished = StorefrontCards::product('Khuôn dập mới ở', 'khuon-dap');
        Product::query()->whereKey($unpublished->id)->toBase()->update(['published_at' => null]);
        DB::table('product_translations')->update(['content_changed_at' => '2026-02-03 04:05:06+00', 'updated_at' => '2026-12-01 00:00:00+00']);
        DB::table('routes')->update(['updated_at' => '2026-01-02 03:04:05+00']);
        DB::table('entry_translations')->update(['content_changed_at' => '2026-01-02 03:04:05+00']);
        DB::table('entries')->update(['published_at' => '2026-01-01 00:00:00+00']);
    });

    $xml = $this->get(CONTENT_DATE_HOST.'/sitemap.xml')->assertOk()->getContent();

    expect(sitemapLastmod($xml, '/do-ga-kiem'))->toBe('2026-04-05T06:07:08+00:00')   // published after its text was written
        ->and(sitemapLastmod($xml, '/khuon-dap'))->toBe('2026-02-03T04:05:06+00:00')   // no publish time: the mark, not updated_at
        ->and(sitemapLastmod($xml, '/lien-he'))->toBe('2026-01-02T03:04:05+00:00');
});

it('starts every language a new site is given with a mark', function (): void {
    $siteId = StorefrontCards::site();

    app(Tenancy::class)->enter($siteId, function (): void {
        expect(EntryTranslation::query()->count())->toBeGreaterThan(0)
            ->and(EntryTranslation::query()->whereNull('content_changed_at')->count())->toBe(0)
            ->and(ProductTranslation::query()->whereNull('content_changed_at')->count())->toBe(0);
    });
});
