<?php

use Illuminate\Support\Facades\DB;

/*
 * permalinks:reflow is a destructive ops command (it moves live URLs). --host is required with no
 * default, so a forgotten flag fails closed instead of silently targeting one hardcoded site.
 */

beforeEach(function (): void {
    seedTenancy();   // site 1 = a.test
});

it('fails closed when no --host is given (no accidental default target)', function (): void {
    $this->artisan('permalinks:reflow', ['--force' => true])
        ->expectsOutputToContain('phải chỉ định --host')
        ->assertFailed();
});

it('fails for an unknown host', function (): void {
    $this->artisan('permalinks:reflow', ['--host' => 'does-not-exist.test', '--force' => true])
        ->assertFailed();
});

it('runs a dry-run against a real site host', function (): void {
    // No content to move → an empty, zero-write preview that still exits success.
    $this->artisan('permalinks:reflow', ['--host' => 'a.test', '--dry-run' => true])
        ->assertSuccessful();

    // Dry-run wrote nothing.
    expect(DB::table('redirects')->count())->toBe(0);
});
