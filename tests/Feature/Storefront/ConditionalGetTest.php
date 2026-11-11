<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * ConditionalGet: ETag + 304 on storefront pages, sitemap.xml and robots.txt.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Other Co', siteName: 'Other Co', hostname: 'other.platform.test',
        ownerEmail: 'owner@other.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
});

dataset('crawlable', ['/', '/lien-he', '/sitemap.xml', '/robots.txt']);

it('sends the same weak ETag for two identical requests', function (string $path): void {
    $first = $this->get('http://other.platform.test'.$path)->assertOk();
    $second = $this->get('http://other.platform.test'.$path)->assertOk();

    $etag = $first->headers->get('ETag');
    expect($etag)->toMatch('~^W/"[0-9a-f]{32}"$~')   // weak: the bytes may be compressed on the way out
        ->and($second->headers->get('ETag'))->toBe($etag)
        ->and($first->headers->get('Cache-Control'))->toContain('no-cache');
})->with('crawlable');

it('answers If-None-Match with 304 and an empty body', function (string $path): void {
    $etag = $this->get('http://other.platform.test'.$path)->assertOk()->headers->get('ETag');

    $response = $this->get('http://other.platform.test'.$path, ['If-None-Match' => $etag]);

    $response->assertStatus(304);
    expect($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBe($etag)
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');

    $this->get('http://other.platform.test'.$path, ['If-None-Match' => '"stale"'])->assertOk();
})->with('crawlable');

it('gives a changed page a different ETag', function (): void {
    $before = $this->get('http://other.platform.test/lien-he')->assertOk()->headers->get('ETag');

    $site = Site::query()->firstWhere('name', 'Other Co');
    app(Tenancy::class)->enter($site->id, function (): void {
        $entry = Entry::query()->whereHas('translations', fn ($q) => $q->where('slug', 'lien-he'))->firstOrFail();
        $entry->translations()->where('locale', 'vi')->update(['title' => 'Liên hệ với chúng tôi ngay']);
    });

    $after = $this->get('http://other.platform.test/lien-he', ['If-None-Match' => $before])->assertOk();

    expect($after->headers->get('ETag'))->not->toBe($before);
});

it('leaves HEAD alone: it answers 200 with no body and no tag computed from an empty body', function (): void {
    // The router empties a HEAD response before route middleware sees it, so there is no body to hash.
    $head = $this->call('HEAD', 'http://other.platform.test/');

    $head->assertOk()->assertHeaderMissing('ETag');
    expect($head->getContent())->toBe('');
});

it('puts no ETag on a 404, on a POST or on the admin pages', function (): void {
    $this->get('http://other.platform.test/khong-co-trang-nay')->assertNotFound()
        ->assertHeaderMissing('ETag');
    $this->get('http://other.platform.test/admin/login')->assertHeaderMissing('ETag');
    $this->post('http://other.platform.test/forms/none', [])->assertHeaderMissing('ETag');
});

it('keeps sending the session cookies on a 304', function (): void {
    $etag = $this->get('http://other.platform.test/')->assertOk()->headers->get('ETag');

    $response = $this->get('http://other.platform.test/', ['If-None-Match' => $etag])->assertStatus(304);

    expect(collect($response->headers->getCookies())->map->getName()->all())->toContain('XSRF-TOKEN');
});

it('matches a weak tag, a list and a star as a proxy or crawler sends them', function (string $form): void {
    $etag = $this->get('http://other.platform.test/lien-he')->assertOk()->headers->get('ETag');
    $opaque = preg_replace('~^W/~', '', (string) $etag);
    $header = ['weak' => 'W/'.$opaque, 'strong' => $opaque, 'list' => '"other", '.$etag, 'star' => '*'][$form];

    expect($etag)->toStartWith('W/"');
    $this->get('http://other.platform.test/lien-he', ['If-None-Match' => $header])->assertStatus(304);
})->with(['weak', 'strong', 'list', 'star']);
