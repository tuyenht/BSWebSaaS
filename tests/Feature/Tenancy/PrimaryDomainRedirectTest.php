<?php

use App\Modules\Tenancy\Actions\RemoveDomain;
use App\Modules\Tenancy\Actions\SetPrimaryDomain;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DomainHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FakeDns;

/*
 * One website, one address (design.md §28): a site's public pages are read on its primary domain;
 * every other verified host of the site answers 301 to the same path there. The admin stays where
 * the member signed in. A customer's domain commits to HTTPS for 30 days and for itself only; and
 * only a platform subdomain can be kept out of search engines.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1);
    seedHomepage(2);
    Cache::flush();
    config(['tenancy.base_domains' => ['platform.test']]);
});

/** Site 1 gets its own domain as primary (HTTPS on) plus the `www` twin; a.test stays attached. */
function giveSiteOneItsOwnDomain(): void
{
    $db = DB::connection('pgsql_admin');
    $db->table('domains')->where('site_id', 1)->update(['is_primary' => false]);

    foreach (['shop.example' => true, 'www.shop.example' => false] as $host => $primary) {
        $db->table('domains')->insert([
            'site_id' => 1, 'hostname' => $host, 'kind' => 'custom', 'is_primary' => $primary,
            'verified_at' => now(), 'ssl_status' => 'active', 'pointing_method' => 'alias',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    Cache::flush();
}

it('sends every other host of the site to the same page on the primary domain', function (string $from): void {
    giveSiteOneItsOwnDomain();

    $response = $this->get("http://{$from}/gioi-thieu?utm=x");

    $response->assertStatus(301)->assertHeader('Location', 'http://shop.example/gioi-thieu?utm=x');
    expect($response->headers->get('Cache-Control'))->toContain('max-age=3600')->toContain('public');
})->with(['a.test', 'www.shop.example']);

it('serves the site on its primary domain, which the canonical link, sitemap and robots.txt name', function (): void {
    giveSiteOneItsOwnDomain();

    $this->get('http://shop.example/')->assertOk()->assertSee('<link rel="canonical" href="http://shop.example"', false);
    expect($this->get('http://shop.example/sitemap.xml')->assertOk()->getContent())->toContain('<loc>http://shop.example')->not->toContain('a.test');
    $this->get('http://shop.example/robots.txt')->assertOk()->assertSee('Sitemap: http://shop.example/sitemap.xml');
});

it('redirects the sitemap and robots.txt of a secondary host too', function (string $path): void {
    giveSiteOneItsOwnDomain();

    $this->get('http://a.test'.$path)->assertStatus(301)->assertHeader('Location', 'http://shop.example'.$path);
})->with(['/sitemap.xml', '/robots.txt']);

it('leaves a site alone while its platform subdomain is its primary', function (): void {
    giveSiteOneItsOwnDomain();

    $this->get('http://b.test/')->assertOk();
});

it('never redirects the admin or a form post: cookies and submissions stay on the host they were made for', function (): void {
    giveSiteOneItsOwnDomain();

    $this->get('http://a.test/admin/login')->assertOk();
    expect($this->post('http://a.test/forms/contact', [])->getStatusCode())->not->toBe(301);
    expect($this->post('http://a.test/any-page', [])->getStatusCode())->not->toBe(301);
});

it('still answers 404 for a host nobody verified', function (): void {
    $this->get('http://nobody.example/')->assertNotFound();
});

it('follows a change of primary at once, on every host of the site', function (): void {
    giveSiteOneItsOwnDomain();
    $this->get('http://a.test/')->assertStatus(301);   // every host now remembers the primary

    app(Tenancy::class)->enter(1, fn () => app(SetPrimaryDomain::class)->handle('a.test'));

    $this->get('http://a.test/')->assertOk();
    $this->get('http://shop.example/')->assertStatus(301)->assertHeader('Location', 'http://a.test/');
});

it('falls back to the platform subdomain at once when the primary domain is removed', function (): void {
    giveSiteOneItsOwnDomain();
    $this->get('http://a.test/')->assertStatus(301);

    app(Tenancy::class)->enter(1, fn () => app(RemoveDomain::class)->handle('shop.example'));

    $this->get('http://a.test/')->assertOk();
    expect(app(SiteResolver::class)->resolve('a.test')?->primaryHost)->toBe('a.test');
    $this->get('http://shop.example/')->assertNotFound();
});

it('stops sending visitors to a primary domain that no longer points here, and resumes when it does', function (): void {
    giveSiteOneItsOwnDomain();
    config(['tenancy.custom_domains.target' => 'target.platform.test']);
    $dns = FakeDns::install();
    $dns->pointHere('www.shop.example');   // the primary, shop.example, resolves to nothing
    $this->get('http://a.test/')->assertStatus(301);

    app(DomainHealth::class)->sweep();

    $this->get('http://a.test/')->assertOk();
    $this->get('http://www.shop.example/')->assertOk();

    $dns->pointHere('shop.example');
    app(DomainHealth::class)->sweep();

    $this->get('http://a.test/')->assertStatus(301)->assertHeader('Location', 'http://shop.example/');
});

it('commits a customer\'s domain to HTTPS for 30 days and for itself only', function (): void {
    giveSiteOneItsOwnDomain();

    foreach (['http://shop.example/', 'http://shop.example/admin/login'] as $url) {
        expect($this->get($url)->headers->get('Strict-Transport-Security'))->toBe('max-age=2592000');
    }

    foreach (['http://b.test/', 'http://b.test/admin/login', 'http://sub.platform.test/'] as $url) {
        expect($this->get($url)->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
    }
});

it('never sends the platform\'s year-long, subdomain-wide HTTPS commitment from a customer\'s domain', function (string $method, string $url): void {
    giveSiteOneItsOwnDomain();

    // A redirect, a Livewire update, a sign-in post, a page that does not exist: none of them runs
    // the whole tenancy stack, and one strong header would bind the customer's other subdomains.
    $response = $this->call($method, $url);

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=2592000');
})->with([
    'redirect to the primary' => ['GET', 'http://www.shop.example/gioi-thieu'],
    'Livewire update' => ['POST', 'http://shop.example/livewire/update'],
    'missing page' => ['GET', 'http://shop.example/khong-co-trang-nay'],
    'a host nobody verified' => ['GET', 'http://nobody.example/'],
]);

it('keeps a platform subdomain out of search engines only while the platform switch is on', function (): void {
    giveSiteOneItsOwnDomain();

    expect($this->get('http://b.test/')->headers->has('X-Robots-Tag'))->toBeFalse();

    config(['tenancy.noindex_platform_subdomains' => true]);

    expect($this->get('http://b.test/')->headers->get('X-Robots-Tag'))->toBe('noindex')
        ->and($this->get('http://shop.example/')->headers->has('X-Robots-Tag'))->toBeFalse();
});

it('resolves a host once per request', function (): void {
    giveSiteOneItsOwnDomain();
    $this->get('http://shop.example/')->assertOk();   // warm

    $lookups = 0;
    DB::listen(function ($query) use (&$lookups): void {
        if (str_contains($query->sql, '"domains"')) {
            $lookups++;
        }
    });

    $this->get('http://shop.example/')->assertOk();

    expect($lookups)->toBe(0);
});
