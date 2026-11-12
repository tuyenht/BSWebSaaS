<?php

use App\Modules\Provisioning\Actions\SeedMeasurementSite;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Rendering\LayoutSlots;
use Tests\Fixtures\StorefrontCards;

/*
 * The CI speed and accessibility job measures only what the measurement site draws. A block kind the
 * registry knows but no measurement page holds would ship un-measured, so this fails until the
 * seed gains a page (or a slot) that draws it.
 */

/** @return list<string> */
function measuredBlockKinds(): array
{
    $photos = array_fill_keys(array_keys(SeedMeasurementSite::PHOTOS), 1);
    $lists = array_column(SeedMeasurementSite::extraPages($photos), 1);
    foreach (SeedMeasurementSite::slotBlocks() as $blocks) {
        $lists[] = $blocks;
    }
    // The first page (/do-kiem) is seeded by seedPage: its kinds are pinned in SeedMeasurementCommandTest.
    $types = ['hero_slider', 'entry_grid', 'product_grid', 'features', 'cta', 'contact'];
    foreach ($lists as $blocks) {
        foreach ($blocks as $block) {
            $types[] = $block['type'];
        }
    }

    return array_values(array_unique($types));
}

it('draws every registered block kind on a measurement page', function (): void {
    $registered = app(ComponentRegistry::class)->types();
    $missing = array_values(array_diff($registered, measuredBlockKinds()));

    expect($missing)->toBe([], 'Block kinds no measurement page draws: '.implode(', ', $missing).'. Add them to SeedMeasurementSite::extraPages() or slotBlocks().');
});

it('names only registered kinds and puts slot blocks in slots that take them', function (): void {
    $registry = app(ComponentRegistry::class);
    foreach (SeedMeasurementSite::slotBlocks() as $slot => $blocks) {
        expect(LayoutSlots::has($slot))->toBeTrue();
        foreach ($blocks as $block) {
            expect($registry->has($block['type']))->toBeTrue()
                ->and(LayoutSlots::accepts($slot, $block['type']))->toBeTrue();
        }
    }
    $photos = array_fill_keys(array_keys(SeedMeasurementSite::PHOTOS), 1);
    foreach (SeedMeasurementSite::extraPages($photos) as [, $blocks]) {
        foreach ($blocks as $block) {
            expect($registry->has($block['type']))->toBeTrue()
                ->and($registry->slotOnly($block['type']))->toBeFalse();
        }
    }
});

it('serves every extra measurement page', function (): void {
    $siteId = StorefrontCards::site('Đo tốc độ', 'cwv.platform.test');
    expect($siteId)->toBeInt();
    $this->artisan('site:seed-measurement', ['--host' => 'cwv.platform.test'])->assertSuccessful();
    config(['media.cloudflare.base' => 'http://cwv.platform.test/cwv-media']);

    foreach ([SeedMeasurementSite::ABOUT_PATH, SeedMeasurementSite::CONTENT_PATH, SeedMeasurementSite::COMMERCE_PATH, SeedMeasurementSite::POST_PATH, SeedMeasurementSite::NEWS_PATH] as $path) {
        $this->get('http://cwv.platform.test'.$path)->assertOk();
    }
});
