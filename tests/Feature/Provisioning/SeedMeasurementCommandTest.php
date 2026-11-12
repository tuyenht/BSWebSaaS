<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Media\Models\Media;
use App\Modules\Provisioning\Actions\SeedMeasurementSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Tests\Fixtures\StorefrontCards;

/*
 * site:seed-measurement fills a measurement site with the pages the CI speed and accessibility job
 * measures (a slider page and the news card grid). It runs only on a platform subdomain and never
 * in production, writes nothing to another site, and running it twice changes nothing.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig('static');
});

/** @return array<string, int> */
function measurementCounts(int $siteId): array
{
    return app(Tenancy::class)->enter($siteId, fn (): array => [
        'entries' => Entry::query()->count(),
        'taxonomies' => Taxonomy::query()->count(),
        'products' => Product::query()->count(),
        'media' => Media::query()->count(),
        'settings' => SiteSetting::query()->count(),
    ]);
}

it('seeds the measurement page and the news card grid', function (): void {
    $siteId = StorefrontCards::site('Đo tốc độ', 'cwv.platform.test');

    $this->artisan('site:seed-measurement', ['--host' => 'cwv.platform.test'])->assertSuccessful();

    // The CI job serves the fixture files from the site's own origin (the serving steps set this).
    config(['media.cloudflare.base' => 'http://cwv.platform.test/cwv-media']);
    $page = $this->get('http://cwv.platform.test/do-kiem')->assertOk()->getContent();
    expect($page)->toContain('data-sf-slider')
        ->and(substr_count($page, 'role="group" aria-roledescription="slide"'))->toBe(3)
        ->and($page)->toContain('src="http://cwv.platform.test/cwv-media/cwv/library/slide-1.webp"')
        ->and($page)->toContain('sf-cards--icon')
        ->and($page)->toContain('sf-cards--product-overlay')
        ->and($page)->toContain('sf-features')
        ->and($page)->toContain('cta--rounded')
        ->and(substr_count($page, '<li class="sf-contact-card">'))->toBe(3)
        ->and(substr_count($page, '<h1'))->toBe(1)
        // Two card rails with their arrows: the cwv job taps those arrows on a phone (tap-check.mjs).
        ->and(substr_count($page, 'data-sf-rail '))->toBe(2)
        ->and(substr_count($page, 'data-sf-rail-prev'))->toBe(2);

    $news = $this->get('http://cwv.platform.test'.SeedMeasurementSite::NEWS_PATH)->assertOk()->getContent();
    expect(substr_count($news, '<article class="sf-card sf-card--news '))->toBeGreaterThanOrEqual(6)
        ->and($news)->toContain('<a class="sf-chips__chip" href="/tin-cong-ty">Tin công ty</a>')
        ->and($news)->toContain('<a class="sf-chips__chip" href="/tin-nganh">Tin ngành</a>');
    $this->get('http://cwv.platform.test/tin-cong-ty')->assertOk();
    expectProductSearchWordsCurrent($siteId);
});

it('points every photo row at a fixture file the CI job copies', function (): void {
    $siteId = StorefrontCards::site('Đo tốc độ', 'cwv.platform.test');
    $this->artisan('site:seed-measurement', ['--host' => 'cwv.platform.test'])->assertSuccessful();

    $keys = app(Tenancy::class)->enter($siteId, fn () => Media::query()->orderBy('storage_key')->pluck('storage_key')->all());
    $files = array_map(fn (string $name): string => SeedMeasurementSite::KEY_PREFIX.$name.'.webp', array_keys(SeedMeasurementSite::PHOTOS));
    sort($files);

    expect($keys)->toBe($files);
    foreach (array_keys(SeedMeasurementSite::PHOTOS) as $name) {
        expect(base_path("tests/Fixtures/cwv/{$name}.webp"))->toBeFile();
    }
});

it('changes nothing when run again', function (): void {
    $siteId = StorefrontCards::site('Đo tốc độ', 'cwv.platform.test');
    $this->artisan('site:seed-measurement', ['--host' => 'cwv.platform.test'])->assertSuccessful();
    $first = measurementCounts($siteId);
    $page = $this->get('http://cwv.platform.test/do-kiem')->getContent();

    $this->artisan('site:seed-measurement', ['--host' => 'cwv.platform.test'])->assertSuccessful();

    expect(measurementCounts($siteId))->toBe($first)
        ->and($this->get('http://cwv.platform.test/do-kiem')->getContent())->toBe($page)
        ->and(categoryCountDrift($siteId))->toBe([]);   // the category totals follow every seeded post and product
});

it('writes nothing to another site', function (): void {
    $other = StorefrontCards::site('Site khác', 'khac.platform.test');
    $before = measurementCounts($other);
    StorefrontCards::site('Đo tốc độ', 'cwv.platform.test');

    $this->artisan('site:seed-measurement', ['--host' => 'cwv.platform.test'])->assertSuccessful();

    expect(measurementCounts($other))->toBe($before);
    $this->get('http://khac.platform.test/do-kiem')->assertNotFound();
});

it('refuses a host outside the platform base domains, an unknown site and production', function (): void {
    $siteId = StorefrontCards::site('Đo tốc độ', 'cwv.platform.test');
    $before = measurementCounts($siteId);

    $this->artisan('site:seed-measurement', ['--host' => 'shop.example.com'])->assertFailed();
    $this->artisan('site:seed-measurement', ['--host' => ''])->assertFailed();
    $this->artisan('site:seed-measurement', ['--host' => 'khong-co.platform.test'])->assertFailed();

    $this->app->detectEnvironment(fn (): string => 'production');
    $this->artisan('site:seed-measurement', ['--host' => 'cwv.platform.test'])->assertFailed();

    expect(measurementCounts($siteId))->toBe($before);
});
