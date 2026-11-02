<?php

declare(strict_types=1);

use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\StorefrontSettings;
use Illuminate\Container\Container;

beforeEach(fn () => seedTenancy());

it('reads a setting saved earlier in the same request, also in the app clone Octane serves from', function (): void {
    $base = Container::getInstance();
    $clone = clone $base;
    Container::setInstance($clone);

    try {
        $value = app(Tenancy::class)->enter(1, function () use ($clone): mixed {
            $settings = $clone->make(StorefrontSettings::class);
            expect($settings->get('storefront', 'header'))->toBeNull();   // memoized: no header yet

            SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'header', 'value' => ['topbar' => true]]);

            return $settings->get('storefront', 'header');
        });
    } finally {
        Container::setInstance($base);
    }

    expect($value)->toBe(['topbar' => true]);
});
