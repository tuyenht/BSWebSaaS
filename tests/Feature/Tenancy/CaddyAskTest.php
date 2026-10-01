<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The certificate gate Caddy asks before requesting a certificate for a host (design.md §28):
 * fail-closed. A platform subdomain always passes; a customer's own domain only once it is
 * verified AND its owner turned HTTPS on; a name that is merely attached never does — otherwise
 * anyone could make the platform request certificates for names it does not serve.
 */

beforeEach(function (): void {
    seedTenancy();
    Cache::flush();
});

/** @param array<string, mixed> $overrides */
function askDomain(array $overrides): void
{
    DB::connection('pgsql_admin')->table('domains')->insert($overrides + [
        'site_id' => 1, 'kind' => 'custom', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('allows a certificate for a platform subdomain', function (): void {
    $this->get('http://a.test/caddy/ask?domain=a.test')->assertOk();
});

it('allows a certificate for a verified customer domain with HTTPS turned on', function (): void {
    askDomain(['hostname' => 'shop.example', 'verified_at' => now(), 'ssl_status' => 'pending']);

    $this->get('http://a.test/caddy/ask?domain=shop.example')->assertOk();
});

it('refuses a verified customer domain whose HTTPS is not turned on', function (): void {
    askDomain(['hostname' => 'shop.example', 'verified_at' => now()]);

    $this->get('http://a.test/caddy/ask?domain=shop.example')->assertNotFound();
});

it('refuses a domain that is attached but not verified, even with HTTPS requested', function (): void {
    askDomain(['hostname' => 'waiting.example', 'ssl_status' => 'pending']);

    $this->get('http://a.test/caddy/ask?domain=waiting.example')->assertNotFound();
});

it('refuses a host nobody attached', function (): void {
    $this->get('http://a.test/caddy/ask?domain=unknown.example')->assertNotFound();
});

it('rejects a missing or malformed host', function (string $query): void {
    $this->get('http://a.test/caddy/ask'.$query)->assertStatus(400);
})->with([
    'missing' => [''],
    'empty' => ['?domain='],
    'IP address' => ['?domain=203.0.113.7'],
    'single label' => ['?domain=localhost'],
    'array' => ['?domain[]=a.test'],
]);
