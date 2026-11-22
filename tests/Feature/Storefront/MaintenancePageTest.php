<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * A website its owner has put under maintenance (Cài đặt › Bảo mật & Nâng cao) answers every
 * storefront page with the maintenance page: the site's own header and footer, the owner's words in
 * the language of the address, the reopen time while it is ahead, «Tải lại trang» and the site's
 * public phone and email. 503 with Retry-After (until the reopen time, an hour without one), never
 * cached, kept out of search engines. A signed-in member of the website still sees it as usual;
 * other websites are untouched, and turning it off brings the pages back.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    seedHomepage(1, 'Trang chủ Ánh Kim Hà');
    seedHomepage(2, 'Trang chủ Ước Mơ Sứ');
    app(Tenancy::class)->enter(1, fn () => SiteProfile::query()->create(['brand_name' => 'Ánh Kim Hà', 'email' => 'lienhe@anhkimha.test', 'phone' => '0912 345 678']));
    app(Tenancy::class)->enter(2, fn () => SiteProfile::query()->create(['brand_name' => 'Ước Mơ Sứ']));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @param array<string, mixed> $value */
function mtSet(int $siteId, array $value): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'maintenance'],
        ['value' => $value],
    ));
}

/** The text of every h1 on the page. */
function mtH1s(string $html): array
{
    preg_match_all('~<h1\b[^>]*>(.*?)</h1>~s', $html, $m);

    return array_map(fn (string $text): string => trim(strip_tags($text)), $m[1]);
}

function mtEnglish(int $siteId): void
{
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => $siteId, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
}

it('shows a visitor the maintenance page: 503, an hour to wait, never cached or indexed', function (): void {
    mtSet(1, ['on' => true, 'messages' => ['vi' => 'Chúng tôi đang cập nhật chuyên mục sản phẩm.'], 'reopens_at' => null]);

    foreach (['/', '/gioi-thieu', '/tim-kiem?q=may'] as $path) {
        $response = $this->get('http://a.test'.$path);
        $response->assertStatus(503);
        expect($response->headers->get('Retry-After'))->toBe('3600');
        expect((string) $response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

        $html = (string) $response->getContent();
        expect(mtH1s($html))->toBe(['Website đang bảo trì'])
            ->and($html)->toContain('<meta name="robots" content="noindex">')
            ->toContain('Chúng tôi đang cập nhật chuyên mục sản phẩm.')
            ->toContain('Tải lại trang')
            ->toContain('href="tel:0912345678"')
            ->toContain('href="mailto:lienhe@anhkimha.test"')
            ->toContain('class="site-footer"')
            ->not->toContain('Trang chủ Ánh Kim Hà')
            ->not->toContain('sf-404__when')
            ->not->toContain('rel="canonical"');
    }
});

it('tells when the website is expected back, and waits until then', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 03:00:00', 'UTC'));
    mtSet(1, ['on' => true, 'messages' => [], 'reopens_at' => '2026-11-22T05:30:00Z']);

    $response = $this->get('http://a.test/')->assertStatus(503);

    // 05:30 UTC is 12:30 in the website's time zone (Asia/Ho_Chi_Minh by default)
    expect($response->headers->get('Retry-After'))->toBe('9000')
        ->and((string) $response->getContent())->toContain('<p class="sf-404__when">Dự kiến xong lúc 22/11/2026 12:30.</p>');
});

it('no longer tells a reopen time that has passed, and waits an hour again', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00', 'UTC'));
    mtSet(1, ['on' => true, 'messages' => [], 'reopens_at' => '2026-11-22T05:30:00Z']);

    $response = $this->get('http://a.test/')->assertStatus(503);

    expect($response->headers->get('Retry-After'))->toBe('3600')
        ->and((string) $response->getContent())->not->toContain('sf-404__when');
});

it('speaks the language of the address: the owner’s words there, else the platform’s own', function (): void {
    mtEnglish(1);
    mtSet(1, ['on' => true, 'messages' => ['vi' => 'Đang nâng cấp kho hàng.', 'en' => 'Upgrading our store.'], 'reopens_at' => null]);

    $en = (string) $this->get('http://a.test/en')->assertStatus(503)->getContent();
    expect(mtH1s($en))->toBe(['Website under maintenance'])
        ->and($en)->toContain('<html lang="en"')->toContain('Upgrading our store.')->toContain('Reload page')
        ->not->toContain('Đang nâng cấp kho hàng.');

    mtSet(1, ['on' => true, 'messages' => ['vi' => 'Đang nâng cấp kho hàng.'], 'reopens_at' => null]);
    expect((string) $this->get('http://a.test/en/about')->getContent())
        ->toContain('We are carrying out maintenance on the website. Please come back shortly.')
        ->not->toContain('Đang nâng cấp kho hàng.');
});

it('leaves the heading alone in the default language when the owner wrote nothing', function (): void {
    mtSet(1, ['on' => true, 'messages' => [], 'reopens_at' => null]);

    $html = (string) $this->get('http://a.test/')->assertStatus(503)->getContent();

    expect(mtH1s($html))->toBe(['Website đang bảo trì'])
        ->and($html)->not->toContain('sf-404__text')
        ->not->toContain('Chúng tôi đang bảo trì website');
});

it('lets a signed-in member of the website see it as usual, privately', function (string $role): void {
    mtSet(1, ['on' => true, 'messages' => ['vi' => 'Bảo trì'], 'reopens_at' => null]);
    $member = blockLibraryUser(1, $role);

    $response = $this->actingAs($member)->withCookie((string) config('session.cookie'), 'member')->get('http://a.test/');

    $response->assertOk();
    expect((string) $response->getContent())->toContain('Trang chủ Ánh Kim Hà')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
})->with(['owner', 'admin', 'editor', 'viewer']);

it('shows the maintenance page to anyone who is not a signed-in member of this website', function (Closure $as): void {
    mtSet(1, ['on' => true, 'messages' => ['vi' => 'Bảo trì'], 'reopens_at' => null]);

    $response = $as($this);

    $response->assertStatus(503);
    expect((string) $response->getContent())->not->toContain('Trang chủ Ánh Kim Hà');
})->with([
    'a session cookie with no one signed in' => [fn ($test) => $test->withCookie((string) config('session.cookie'), 'forged')->get('http://a.test/')],
    'a member of another website' => [fn ($test) => $test->actingAs(blockLibraryUser(2, 'owner'))->withCookie((string) config('session.cookie'), 'x')->get('http://a.test/')],
    'an account with no role here' => [fn ($test) => $test->actingAs(User::factory()->create())->withCookie((string) config('session.cookie'), 'x')->get('http://a.test/')],
]);

it('leaves another website open', function (): void {
    mtSet(1, ['on' => true, 'messages' => ['vi' => 'Bảo trì'], 'reopens_at' => null]);

    expect($this->get('http://b.test/')->assertOk()->getContent())->toContain('Trang chủ Ước Mơ Sứ');
});

it('brings the pages back once it is turned off', function (): void {
    mtSet(1, ['on' => true, 'messages' => ['vi' => 'Bảo trì'], 'reopens_at' => null]);
    $this->get('http://a.test/')->assertStatus(503);

    mtSet(1, ['on' => false, 'messages' => ['vi' => 'Bảo trì'], 'reopens_at' => null]);

    expect($this->get('http://a.test/')->assertOk()->getContent())->toContain('Trang chủ Ánh Kim Hà')->not->toContain('Website đang bảo trì');
});

it('keeps robots.txt and the sitemap answering, and gives no search suggestions', function (): void {
    mtSet(1, ['on' => true, 'messages' => [], 'reopens_at' => null]);

    $this->get('http://a.test/robots.txt')->assertOk();
    $this->get('http://a.test/sitemap.xml')->assertOk();
    $this->get('http://a.test/tim-kiem/goi-y?q=may')->assertNotFound();
});

it('reads nothing more for the maintenance page than for a site that is not open', function (): void {
    mtSet(1, ['on' => true, 'messages' => ['vi' => 'Bảo trì'], 'reopens_at' => null]);
    $this->get('http://a.test/'); // warm the caches both pages share
    $maintenance = countQueries(fn () => $this->get('http://a.test/')->assertStatus(503));

    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['status' => 'suspended']);
    $this->get('http://b.test/');
    $closed = countQueries(fn () => $this->get('http://b.test/')->assertStatus(503));

    expect($maintenance)->toBeLessThanOrEqual($closed);
});
