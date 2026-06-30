<?php

/*
 * Platform-plane auth parity (website.baoson.net). The operator login gains the "Forgot
 * password" link + the forgot/reset pages (plane-aware via AdminPlane), matching the tenant
 * admin. The reset-email URL + post-reset redirect resolve to platform.* on the platform host
 * by reusing the AdminPlane::prefix() pattern (already exercised by the login challenge routing).
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
});

it('shows the forgot-password link on the platform login', function () {
    $host = (string) config('tenancy.platform_host');

    $this->get("http://{$host}/login")
        ->assertOk()
        ->assertSee(route('platform.password.request'));
});

it('still shows the forgot-password link on the tenant admin login', function () {
    $this->get('http://a.test/admin/login')
        ->assertOk()
        ->assertSee(route('admin.password.request'));
});

it('renders the platform forgot-password page', function () {
    $host = (string) config('tenancy.platform_host');

    $this->get("http://{$host}/forgot-password")
        ->assertOk()
        ->assertSee(__('auth.forgot_title'));
});

it('renders the platform reset-password page', function () {
    $host = (string) config('tenancy.platform_host');

    $this->get("http://{$host}/reset-password?token=tok&email=op@baoson.net")->assertOk();
});
