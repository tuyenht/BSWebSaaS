<?php

/*
 * Admin preview renderer — a TOOL, not a test of behaviour.
 *
 * It renders a set of admin screens to storage/app/admin-preview/*.html as a freshly seeded site
 * owner, so a reviewer (or Claude, checking a theme/contrast/layout change) can screenshot the admin
 * WITHOUT logging into any real environment: no production, no 2FA, no bypass route, no real tenant
 * data. Authentication is Pest's actingAs() against an ephemeral test-database owner; every request
 * is a GET, so nothing is mutated.
 *
 * It lives in tests/Tools/, which is outside the four configured PHPUnit suites (Unit, Feature, Arch,
 * Concurrency in phpunit.xml), so `composer qa` and CI never run it. Run it explicitly — normally
 * through scripts/admin-preview/run.sh, or directly:
 *
 *   docker compose run --rm --no-deps app vendor/bin/pest tests/Tools/RenderAdminPreviewTest.php
 *
 * Output goes to storage/app/admin-preview/. Override the target with ADMIN_PREVIEW_DIR.
 *
 * Unlike the real suites, this file opts Vite back IN (TestCase disables it): the whole point is to
 * render with the real compiled CSS/JS, so build the front end first (pnpm run build).
 */

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/** The admin screens worth a look after a chrome/theme change: dashboard, a list, a form, the kit. */
const ADMIN_PREVIEW_SCREENS = [
    'dashboard' => '/admin',
    'list' => '/admin/content',
    'form' => '/admin/settings/email',
    'gallery' => '/admin/_gallery',
];

const ADMIN_PREVIEW_HOST = 'preview.platform.test';

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         platform_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();

    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Preview', siteName: 'Công ty Demo', hostname: ADMIN_PREVIEW_HOST,
        ownerEmail: 'owner@'.ADMIN_PREVIEW_HOST, ownerName: 'Nguyễn Văn Demo',
        ownerPassword: 'a-strong-password', locales: ['vi', 'en'], modules: ['cms', 'media'],
    ));
});

it('renders the admin screens to HTML for screenshotting', function (): void {
    // Belt for the "someone ran the whole suite" case: this tool needs the built front end, and the
    // CI/`composer qa` pest job never builds it. tests/Tools sits outside the configured suites so it
    // is not collected there anyway — but if it ever were, skip instead of failing on a missing Vite
    // manifest.
    if (! is_file(public_path('build/manifest.json'))) {
        $this->markTestSkipped('admin preview tool: run `pnpm run build` first (needs the compiled assets).');
    }

    $this->withVite(); // render with the real built assets, not the test stub

    $owner = User::query()->where('email', 'owner@'.ADMIN_PREVIEW_HOST)->firstOrFail();

    // A few entries in mixed states so the list screen shows real rows, not an empty state; the site
    // has two languages and one page is translated, so the rows show both language-chip states.
    app(Tenancy::class)->enter(1, function () use ($owner): void {
        foreach ([['Trang chủ', 'trang-chu', 'published'], ['Giới thiệu', 'gioi-thieu', 'published'], ['Liên hệ', 'lien-he', 'draft'], ['Tuyển dụng', 'tuyen-dung', 'scheduled']] as [$title, $slug, $status]) {
            $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'author_id' => $owner->id]);
            $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            if ($slug === 'gioi-thieu') {
                $entry->translations()->create(['locale' => 'en', 'title' => 'About us', 'slug' => 'about-us', 'status' => 'draft', 'body' => ['blocks' => []]]);
            }
        }
    });

    $dir = env('ADMIN_PREVIEW_DIR', storage_path('app/admin-preview'));
    @mkdir($dir, 0777, true);
    array_map('unlink', glob($dir.'/*.html') ?: []);

    foreach (ADMIN_PREVIEW_SCREENS as $name => $path) {
        $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.$path)->assertOk()->getContent();
        file_put_contents($dir.'/'.$name.'.html', $html);
    }

    // The platform Audit log, as a support operator, with a member's row and a support sign-in row.
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful(); // the platform roles
    $operator = User::factory()->create(['name' => 'Lê Hoàng Phúc']);
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';
    AuditLog::query()->create(['action' => 'rbac.role.assigned', 'actor_id' => $owner->id, 'actor_label' => $owner->email, 'site_id' => 1,
        'ip_address' => '113.161.72.18', 'user_agent' => $agent, 'meta' => ['role' => 'Biên tập viên']]);
    $support = AuditLog::query()->create(['action' => 'cms.menu.saved', 'actor_id' => $owner->id, 'actor_label' => $owner->email, 'site_id' => 1,
        'impersonator_id' => $operator->id, 'impersonator_label' => $operator->email, 'ip_address' => '14.232.10.5', 'user_agent' => $agent,
        'meta' => ['name' => 'Trình đơn chính']]);
    $this->flushSession(); // the owner's session would sign the operator out (session guard)
    $platform = 'http://'.config('tenancy.platform_host');
    foreach (['audit' => '/audit', 'audit-detail' => '/audit?open='.$support->ulid] as $name => $path) {
        $html = $this->actingAs($operator)->get($platform.$path)->assertOk()->getContent();
        // served by serve.mjs like the other screens, which rewrites only the preview host
        file_put_contents($dir.'/'.$name.'.html', str_replace($platform, 'http://'.ADMIN_PREVIEW_HOST, $html));
    }

    expect(glob($dir.'/*.html'))->toHaveCount(count(ADMIN_PREVIEW_SCREENS) + 2);
});
