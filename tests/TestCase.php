<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Admin views use @vite; under test there is no asset build (the pest CI job does
        // not run vite), so stub Vite to render nothing instead of throwing on a missing
        // manifest. Class/text assertions are unaffected (they read the Blade markup).
        $this->withoutVite();
    }
}
