<?php

use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;

/*
 * SiteTime — the per-site display-timezone funnel. Times are stored UTC (D11) and parsed/rendered
 * in the site's zone (site_settings general.timezone), defaulting to the platform GMT+7.
 */

beforeEach(function (): void {
    seedTenancy();
});

it('defaults to the platform timezone when the site has no setting', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        expect(app(SiteTime::class)->zone())->toBe('Asia/Ho_Chi_Minh');
    });
});

it('reads a per-site timezone override from site_settings', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'timezone', 'value' => ['tz' => 'Asia/Tokyo']]);

        expect(app(SiteTime::class)->zone())->toBe('Asia/Tokyo');
    });
});

it('falls back to the platform default for an invalid timezone', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'general', 'key' => 'timezone', 'value' => ['tz' => 'Not/AZone']]);

        expect(app(SiteTime::class)->zone())->toBe('Asia/Ho_Chi_Minh');
    });
});

it('parses a naive local input in the site zone and stores it as UTC', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        // Default zone Asia/Ho_Chi_Minh (UTC+7): 14:30 local → 07:30 UTC.
        $utc = app(SiteTime::class)->toStorage('2026-07-10T14:30');

        expect($utc->timezoneName)->toBe('UTC')
            ->and($utc->format('Y-m-d H:i'))->toBe('2026-07-10 07:30');
    });
});

it('renders a stored UTC instant in the site zone', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $local = app(SiteTime::class)->forDisplay(CarbonImmutable::parse('2026-07-10 07:30', 'UTC'));

        expect($local?->timezoneName)->toBe('Asia/Ho_Chi_Minh')
            ->and($local?->format('H:i'))->toBe('14:30');
    });
});

it('passes null through forDisplay unchanged', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        expect(app(SiteTime::class)->forDisplay(null))->toBeNull();
    });
});

it('returns the platform default zone with no site context', function (): void {
    expect(app(SiteTime::class)->zone())->toBe('Asia/Ho_Chi_Minh');
});
