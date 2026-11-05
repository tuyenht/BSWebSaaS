<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The site's search settings on the storefront (group seo, key config): the <title> (own Google
 * title → the home page's default title → the title template), the description (own → excerpt →
 * the site's default in that language → the brand description), and "Cho Google tìm thấy
 * website" / the sitemap / a custom robots.txt. Without the setting every page is as before.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'Trang chủ A');
    seedHomepage(2, 'Trang chủ B');
    Cache::flush();
});

/** @param array<string, mixed> $value */
function ssSet(array $value, int $site = 1): void
{
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'seo', 'key' => 'config'], ['value' => $value],
    ));
}

function ssBrand(string $name = 'Cơ Khí A', string $description = 'Mô tả thương hiệu', int $site = 1): void
{
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => $name, 'description' => $description]],
    ));
}

/** A published page at $path with the given translation fields. */
function ssPage(string $path, string $title, array $fields = [], int $site = 1): void
{
    app(Tenancy::class)->enter($site, function () use ($path, $title, $fields): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => trim($path, '/'), 'status' => 'published',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => $title]]]],
        ] + $fields);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => $path, 'entry_id' => $entry->id]);
    });
}

function ssDescription(string $html): ?string
{
    return preg_match('/<meta name="description" content="([^"]*)">/', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
}

it('keeps today’s head without the setting', function (): void {
    ssBrand();
    ssPage('/gioi-thieu', 'Giới thiệu');

    $home = $this->get('http://a.test/')->assertOk()->getContent();
    $page = $this->get('http://a.test/gioi-thieu')->assertOk()->getContent();

    expect($home)->toContain('<title>Trang chủ A</title>')->not->toContain('name="robots"')
        ->and($page)->toContain('<title>Giới thiệu</title>')
        ->and(ssDescription($page))->toBe('Mô tả thương hiệu');
});

it('builds the title: own title, the home default, the template elsewhere, none without a template', function (): void {
    ssBrand('Cơ Khí A');
    ssPage('/gioi-thieu', 'Giới thiệu');
    ssPage('/rieng', 'Trang riêng', ['seo_title' => 'Tiêu đề Google riêng']);
    ssSet(['titles' => ['vi' => 'Cơ Khí A — gia công chính xác'], 'template' => '%tieu-de% | %ten-website%']);

    expect($this->get('http://a.test/')->getContent())->toContain('<title>Cơ Khí A — gia công chính xác</title>')
        ->and($this->get('http://a.test/gioi-thieu')->getContent())->toContain('<title>Giới thiệu | Cơ Khí A</title>')
        ->and($this->get('http://a.test/rieng')->getContent())->toContain('<title>Tiêu đề Google riêng</title>');

    // The home page without a default title follows the template too; no template keeps the page title.
    ssSet(['template' => '%tieu-de% – %ten-website%']);
    expect($this->get('http://a.test/')->getContent())->toContain('<title>Trang chủ A – Cơ Khí A</title>');
    ssSet(['titles' => ['vi' => 'Mặc định'], 'template' => '']);
    expect($this->get('http://a.test/gioi-thieu')->getContent())->toContain('<title>Giới thiệu</title>');

    // A site without a name drops the dangling separator.
    ssBrand('');
    ssSet(['template' => '%tieu-de% | %ten-website%']);
    expect($this->get('http://a.test/gioi-thieu')->getContent())->toContain('<title>Giới thiệu</title>');
});

it('trims a dangling separator without breaking the letters next to it', function (): void {
    ssBrand('');
    ssPage('/ban-do', 'Bản đồ');
    ssPage('/uu-dai', '“Ưu đãi”');
    ssSet(['template' => '%tieu-de% · %ten-website%']);

    // "ồ" ends in a byte that "–" also holds, and "“" starts with one: a byte trim cut them in half.
    expect($this->get('http://a.test/ban-do')->getContent())->toContain('<title>Bản đồ</title>')
        ->and($this->get('http://a.test/uu-dai')->getContent())->toContain('<title>“Ưu đãi”</title>');
});

it('escapes a hostile template and page title', function (): void {
    ssPage('/gioi-thieu', '<img src=x onerror=alert(2)>');
    ssSet(['template' => '<script>alert(1)</script> %tieu-de% "%ten-website%"']);

    $html = $this->get('http://a.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x')
        ->toContain('<title>&lt;script&gt;alert(1)&lt;/script&gt; &lt;img src=x onerror=alert(2)&gt; &quot;');
});

it('falls back for the description: own, excerpt, the site default, the brand description', function (): void {
    ssBrand();
    ssPage('/mot', 'Một', ['seo_description' => 'Mô tả Google riêng', 'excerpt' => 'Tóm tắt một']);
    ssPage('/hai', 'Hai', ['excerpt' => "Tóm tắt\nhai"]);
    ssPage('/ba', 'Ba');
    ssSet(['descriptions' => ['vi' => 'Mô tả mặc định của website']]);

    expect(ssDescription($this->get('http://a.test/mot')->getContent()))->toBe('Mô tả Google riêng')
        ->and(ssDescription($this->get('http://a.test/hai')->getContent()))->toBe('Tóm tắt hai')
        ->and(ssDescription($this->get('http://a.test/ba')->getContent()))->toBe('Mô tả mặc định của website');

    ssSet(['descriptions' => []]);
    expect(ssDescription($this->get('http://a.test/ba')->getContent()))->toBe('Mô tả thương hiệu');
});

it('hides the whole site from Google and brings it back', function (): void {
    ssPage('/gioi-thieu', 'Giới thiệu');
    ssSet(['indexable' => false, 'robots' => "User-agent: *\nAllow: /", 'sitemap' => true]);

    foreach (['/', '/gioi-thieu'] as $path) {
        expect($this->get('http://a.test'.$path)->assertOk()->getContent())->toContain('<meta name="robots" content="noindex, nofollow">');
    }
    $this->get('http://a.test/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /");
    $this->get('http://a.test/sitemap.xml')->assertNotFound();
    $this->get('http://a.test/sitemap-1.xml')->assertNotFound();

    ssSet(['indexable' => true]);
    expect($this->get('http://a.test/')->getContent())->not->toContain('name="robots"');
    $this->get('http://a.test/robots.txt')->assertContent("User-agent: *\nDisallow:\nSitemap: http://a.test/sitemap.xml");
    $this->get('http://a.test/sitemap.xml')->assertOk();
});

it('turns the sitemap off: a 404 and no Sitemap line', function (): void {
    ssSet(['sitemap' => false]);

    $this->get('http://a.test/sitemap.xml')->assertNotFound();
    $this->get('http://a.test/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow:");
    expect($this->get('http://a.test/')->getContent())->not->toContain('name="robots"');
});

it('drops the custom Sitemap line while the sitemap is off, so robots.txt never points at a 404', function (): void {
    ssSet(['robots' => "User-agent: *\nSitemap: http://a.test/sitemap.xml\nDisallow: /gio-hang", 'sitemap' => false]);

    $this->get('http://a.test/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /gio-hang");
});

it('renders a page from its own settings read, not the shared copy robots.txt uses', function (): void {
    ssSet(['indexable' => true]);
    // A stale shared copy (a reader that raced a write) must not reach the page's head.
    Cache::put('site-seo:v1:1', ['indexable' => false], 300);

    expect($this->get('http://a.test/')->getContent())->not->toContain('name="robots"');
    $this->get('http://a.test/robots.txt')->assertContent("User-agent: *\nDisallow: /");
});

it('serves a custom robots.txt as plain text, adds the sitemap line it lacks, and yields to Google off', function (): void {
    ssSet(['robots' => "User-agent: *\nDisallow: /gio-hang"]);

    $response = $this->get('http://a.test/robots.txt')->assertOk()
        ->assertContent("User-agent: *\nDisallow: /gio-hang\nSitemap: http://a.test/sitemap.xml");
    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    ssSet(['robots' => "User-agent: *\nDisallow:\nsitemap: https://cdn.example/s.xml"]);
    $this->get('http://a.test/robots.txt')->assertContent("User-agent: *\nDisallow:\nsitemap: https://cdn.example/s.xml");

    // A stored value is cleaned on read as well: other control characters dropped, cut at the limit.
    ssSet(['robots' => "User-agent: *\u{0007}\r\nDisallow: /a\u{001B}".str_repeat('x', 20000), 'sitemap' => false]);
    $body = $this->get('http://a.test/robots.txt')->getContent();
    expect($body)->toStartWith("User-agent: *\nDisallow: /ax")->not->toContain("\u{0007}")->not->toContain("\u{001B}")
        ->and(mb_strlen($body))->toBe(10000);

    ssSet(['robots' => "User-agent: *\nAllow: /", 'indexable' => false]);
    $this->get('http://a.test/robots.txt')->assertContent("User-agent: *\nDisallow: /");
});

it('keeps each site’s settings to itself', function (): void {
    ssSet(['indexable' => false, 'titles' => ['vi' => 'Chỉ của A']], 1);

    expect($this->get('http://a.test/')->getContent())->toContain('<title>Chỉ của A</title>')->toContain('noindex, nofollow');
    expect($this->get('http://b.test/')->getContent())->toContain('<title>Trang chủ B</title>')->not->toContain('name="robots"');
    $this->get('http://b.test/robots.txt')->assertContent("User-agent: *\nDisallow:\nSitemap: http://b.test/sitemap.xml");
    $this->get('http://b.test/sitemap.xml')->assertOk();
});

it('leaves the platform-subdomain header alone', function (): void {
    config(['tenancy.noindex_platform_subdomains' => true]);

    $on = $this->get('http://a.test/')->assertOk();
    expect($on->headers->get('X-Robots-Tag'))->toBe('noindex')->and($on->getContent())->not->toContain('name="robots"');

    ssSet(['indexable' => false]);
    $off = $this->get('http://a.test/')->assertOk();
    expect($off->headers->get('X-Robots-Tag'))->toBe('noindex')->and($off->getContent())->toContain('noindex, nofollow');
});

it('applies a saved change on the next request', function (): void {
    $this->get('http://a.test/robots.txt')->assertContent("User-agent: *\nDisallow:\nSitemap: http://a.test/sitemap.xml");   // cached

    ssSet(['sitemap' => false]);

    $this->get('http://a.test/robots.txt')->assertContent("User-agent: *\nDisallow:");
});

it('reads the settings without a new query on pages, robots.txt and the sitemap', function (): void {
    ssPage('/gioi-thieu', 'Giới thiệu', ['excerpt' => 'Tóm tắt']);
    ssSet(['titles' => ['vi' => 'Mặc định'], 'descriptions' => ['vi' => 'Mô tả'], 'template' => '%tieu-de% | %ten-website%', 'robots' => "User-agent: *\nDisallow: /x"]);

    $settingsReads = [];
    $counting = null;
    DB::listen(function ($query) use (&$settingsReads, &$counting): void {
        if ($counting !== null && str_contains($query->sql, 'site_settings')) {
            $settingsReads[$counting]++;
        }
    });
    foreach (['/', '/gioi-thieu', '/robots.txt', '/sitemap.xml'] as $path) {
        $this->get('http://a.test'.$path)->assertOk();    // warm the shared cache
        $settingsReads[$path] = 0;
        $counting = $path;
        $this->get('http://a.test'.$path)->assertOk();
        $counting = null;
    }

    // A page reads the site's settings in the one query it already made; robots.txt and the
    // sitemap read the shared copy.
    expect($settingsReads)->toBe(['/' => 1, '/gioi-thieu' => 1, '/robots.txt' => 0, '/sitemap.xml' => 0]);
});
