<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * P1.3 — full dynamic pipeline end-to-end: a site provisioned by the SAME action
 * that builds An Vinh is immediately reachable, rendering its DB-stored homepage as
 * SSR HTML, isolated per tenant.
 */

beforeEach(function () {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

function provision(string $name, string $host, string $email): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $name,
        siteName: $name,
        hostname: $host,
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

it('renders the provisioned An Vinh homepage as SSR HTML', function () {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');

    $html = $this->get('http://anvinh.test/')->assertOk()->getContent();

    expect($html)->toContain('<title>An Vinh</title>')
        ->toContain('<section class="hero">')
        ->toContain('An Vinh');
});

it('404s an unknown path on a real site', function () {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');

    $this->get('http://anvinh.test/khong-ton-tai')->assertNotFound();
});

it('renders a published post at its category path within budget', function () {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');

    $site = Site::query()->firstWhere('name', 'An Vinh');

    app(Tenancy::class)->enter($site->id, function () {
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->first();
        $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id]);
        $post->translations()->create([
            'locale' => 'vi',
            'title' => 'Bài viết đầu tiên',
            'slug' => 'bai-viet-dau-tien',
            'status' => 'draft',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'Chào', 'paragraphs' => ['Nội dung bài viết']]]]],
        ]);
        $post->assignCategories([$category->id]);

        app(PublishEntry::class)->handle($post->fresh(), 'vi', $owner->fresh());
    });

    // The post seeded by ProvisionSite's category routes under /tin-tuc/{slug} (PathBuilder).
    $count = countQueries(function () {
        $html = $this->get('http://anvinh.test/tin-tuc/bai-viet-dau-tien')->assertOk()->getContent();

        expect($html)->toContain('<title>Bài viết đầu tiên</title>')
            ->toContain('class="post__title">Bài viết đầu tiên')
            ->toContain('Chào')
            ->toContain('Nội dung bài viết');
    });

    // Home budget (10) + one primary-category detail_template lookup.
    expect($count)->toBeLessThanOrEqual(11);
});

it('isolates rendered content between provisioned sites', function () {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');
    provision('Other Co', 'other.test', 'owner@other.test');

    expect($this->get('http://anvinh.test/')->getContent())->toContain('An Vinh')->not->toContain('Other Co');
    expect($this->get('http://other.test/')->getContent())->toContain('Other Co')->not->toContain('An Vinh');
});
