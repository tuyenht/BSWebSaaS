<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Admin views use @vite; under test there is no asset build (the pest CI job does
        // not run vite), so stub Vite to render nothing instead of throwing on a missing
        // manifest. Class/text assertions are unaffected (they read the Blade markup).
        $this->withoutVite();

        // The array cache store is a singleton that survives between tests, so RateLimiter
        // counters (login brute-force, OTP send caps, presign throttle) would accumulate across
        // the suite and trip on shared keys (e.g. per-IP). Flush per test to keep them isolated.
        Cache::flush();
    }
}
