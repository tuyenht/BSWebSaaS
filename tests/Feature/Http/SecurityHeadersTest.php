<?php

/*
 * Security gap-fix: HTTP response headers.
 *
 * Invariants:
 *   1. Every web response carries the 5 baseline hardening headers.
 *   2. Storefront responses also carry a strict Content-Security-Policy.
 *   3. Admin and platform responses carry the 5 baselines but NO strict CSP —
 *      Livewire 4 / Alpine.js require 'unsafe-eval'; a strict policy breaks them.
 *   4. Admin and platform pages still render 200 (CSP omission did not break them).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
});

// ── Storefront — baseline headers ────────────────────────────────────────────

it('storefront response carries all 5 baseline security headers', function (): void {
    seedHomepage(1, 'Trang chủ');

    $this->get('http://a.test/')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
});

// ── Storefront — allow-list CSP ──────────────────────────────────────────────

it('storefront response carries the allow-list Content-Security-Policy', function (): void {
    seedHomepage(1, 'Trang chủ');

    $response = $this->get('http://a.test/');
    $response->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy', '');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("img-src 'self' https://files.baoson.net data:")
        ->toContain("style-src 'self' 'unsafe-inline'")
        // Cloudflare Turnstile (lead-form anti-spam, §23/R20) loads a script + iframe from
        // challenges.cloudflare.com → both directives must allow that one trusted origin.
        ->toContain("script-src 'self' https://challenges.cloudflare.com")
        ->toContain('frame-src https://www.google.com https://challenges.cloudflare.com')
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'");
});

// ── Admin plane — baseline only, no strict CSP ───────────────────────────────

it('admin login carries baseline headers but no Content-Security-Policy', function (): void {
    // The admin login is guest-accessible (no auth required); tenant context is
    // resolved by SetCurrentSite via the a.test host seeded in beforeEach.
    $this->get('http://a.test/admin/login')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
        ->assertHeaderMissing('Content-Security-Policy');
});

// ── Platform plane — baseline only, no strict CSP ────────────────────────────

it('platform login carries baseline headers but no Content-Security-Policy', function (): void {
    // The platform login has no tenant context (dedicated host, no SetCurrentSite).
    $this->get('http://platform.bswebsaas.test/login')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
        ->assertHeaderMissing('Content-Security-Policy');
});
