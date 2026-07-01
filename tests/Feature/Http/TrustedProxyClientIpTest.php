<?php

use Illuminate\Support\Facades\Route;

/*
 * The app sits behind the edge Caddy (Docker-internal, a private-range peer). Caddy resolves the
 * real visitor — directly for gray hosts, via CF-Connecting-IP for Cloudflare-proxied ones — and
 * hands it down in X-Forwarded-For. bootstrap/app.php trusts the private proxy ranges, so
 * request()->ip() must read the client from that header when the peer is trusted, and MUST ignore a
 * forged header from an untrusted peer (someone hitting the origin directly). This locks that
 * behaviour: it's the app half of the client-IP resolution the rate-limits and audit log depend on.
 */

it('reads the client IP from X-Forwarded-For when the peer is a trusted proxy, and ignores it otherwise', function () {
    /** @var \Tests\TestCase $this — Pest rebinds the closure to the TestCase; Larastan can't infer it. */
    Route::get('/__ip_probe', fn () => request()->ip());

    // Trusted edge (REMOTE_ADDR inside the private ranges) → client taken from the forwarded header.
    $this->call('GET', '/__ip_probe', [], [], [], [
        'REMOTE_ADDR' => '172.16.0.5',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
    ])->assertOk()->assertSee('203.0.113.7');

    // Untrusted peer (a public client reaching the origin directly) → its forged header is discarded.
    $this->call('GET', '/__ip_probe', [], [], [], [
        'REMOTE_ADDR' => '8.8.8.8',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
    ])->assertOk()->assertSee('8.8.8.8');
});
