<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * EntryGridComponent: a reusable data-bound grid of published posts in ONE taxonomy (by slug),
 * RLS-scoped, with a rich card (image/title/short_outcome/sector). Generic — tested with a
 * plain post_cat + sector, independent of the An Vinh seeder.
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

function gridProvision(string $name, string $host, string $email): Site
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $name, siteName: $name, hostname: $host,
        ownerEmail: $email, ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    return Site::query()->firstWhere('name', $name);
}

/** Seed a "du-an" post_cat + one "sector" child + a published project attached to both. */
function gridSeedProject(string $email): void
{
    $owner = User::query()->firstWhere('email', $email);

    $cat = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
    $cat->translations()->create(['locale' => 'vi', 'name' => 'Dự án', 'slug' => 'du-an']);

    $sector = Taxonomy::query()->create(['vocabulary' => 'sector', 'parent_id' => $cat->id, 'position' => 0]);
    $sector->translations()->create(['locale' => 'vi', 'name' => 'Nhiệt điện', 'slug' => 'nhiet-dien']);

    $project = Entry::query()->create([
        'type' => 'post', 'status' => 'draft', 'author_id' => $owner->id,
        'meta' => ['image_url' => 'https://cdn.example/mong-duong-1.avif'],
    ]);
    $project->translations()->create([
        'locale' => 'vi', 'title' => 'Mông Dương 1', 'slug' => 'mong-duong-1', 'status' => 'draft',
        'excerpt' => 'Bảo ôn cách nhiệt nhà máy nhiệt điện', 'body' => ['blocks' => []],
    ]);
    $project->assignCategories([$cat->id, $sector->id]);   // du-an primary (pos 0), sector secondary
    app(PublishEntry::class)->handle($project->fresh(), 'vi', $owner->fresh());

    // A draft project (must be excluded).
    $draft = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id]);
    $draft->translations()->create(['locale' => 'vi', 'title' => 'Nháp', 'slug' => 'nhap', 'status' => 'draft', 'body' => ['blocks' => []]]);
    $draft->assignCategories([$cat->id]);
}

it('maps a published project card and excludes drafts', function () {
    $site = gridProvision('Grid Co', 'grid.test', 'owner@grid.test');

    app(Tenancy::class)->enter($site->id, function () {
        gridSeedProject('owner@grid.test');

        $data = app(EntryGridComponent::class)->data(['taxonomy_slug' => 'du-an', 'limit' => 6, 'locale' => 'vi']);

        expect($data['items'])->toHaveCount(1);                 // draft excluded
        expect($data['items'][0])->toMatchArray([
            'title' => 'Mông Dương 1',
            'url' => '/du-an/mong-duong-1',                      // routes via du-an primary category
            'image' => 'https://cdn.example/mong-duong-1.avif',  // from entry.meta
            'short_outcome' => 'Bảo ôn cách nhiệt nhà máy nhiệt điện', // from translation.excerpt
            'sector' => 'Nhiệt điện',                            // from the sector-vocabulary term
        ]);
    });
});

it('clamps limit and returns empty for an unknown slug', function () {
    $site = gridProvision('Grid Co', 'grid.test', 'owner@grid.test');

    app(Tenancy::class)->enter($site->id, function () {
        gridSeedProject('owner@grid.test');
        $component = app(EntryGridComponent::class);

        // Unknown slug → empty, no error.
        expect($component->data(['taxonomy_slug' => 'khong-co', 'locale' => 'vi'])['items'])->toBe([]);
        // Empty slug → empty.
        expect($component->data(['locale' => 'vi'])['items'])->toBe([]);
        // Forged huge limit is clamped (still returns the one project, no unbounded query).
        expect($component->data(['taxonomy_slug' => 'du-an', 'limit' => 9999, 'locale' => 'vi'])['items'])->toHaveCount(1);
    });
});

it('isolates the grid between sites (RLS)', function () {
    $siteA = gridProvision('Site A', 'a.test', 'owner@a.test');
    gridProvision('Site B', 'b.test', 'owner@b.test');

    app(Tenancy::class)->enter($siteA->id, fn () => gridSeedProject('owner@a.test'));

    // Site B has no "du-an" taxonomy of its own → empty (cannot see site A's content).
    $siteB = Site::query()->firstWhere('name', 'Site B');
    app(Tenancy::class)->enter($siteB->id, function () {
        expect(app(EntryGridComponent::class)->data(['taxonomy_slug' => 'du-an', 'locale' => 'vi'])['items'])->toBe([]);
    });
});

it('resolves the grid in a fixed query count as cards scale (no N+1)', function () {
    $site = gridProvision('Grid Co', 'grid.test', 'owner@grid.test');

    app(Tenancy::class)->enter($site->id, function () {
        $owner = User::query()->firstWhere('email', 'owner@grid.test');

        $cat = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
        $cat->translations()->create(['locale' => 'vi', 'name' => 'Dự án', 'slug' => 'du-an']);
        $sector = Taxonomy::query()->create(['vocabulary' => 'sector', 'parent_id' => $cat->id, 'position' => 0]);
        $sector->translations()->create(['locale' => 'vi', 'name' => 'Nhiệt điện', 'slug' => 'nhiet-dien']);

        // Three published cards — each with a sector + image + excerpt. If the sector/translation/
        // route lookups were not eager-loaded, the query count would grow per card.
        foreach (['Dự án A' => 'du-an-a', 'Dự án B' => 'du-an-b', 'Dự án C' => 'du-an-c'] as $title => $slug) {
            $p = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id, 'meta' => ['image_url' => 'https://cdn.example/x.avif']]);
            $p->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'excerpt' => 'Outcome', 'body' => ['blocks' => []]]);
            $p->assignCategories([$cat->id, $sector->id]);
            app(PublishEntry::class)->handle($p->fresh(), 'vi', $owner->fresh());
        }

        $component = app(EntryGridComponent::class);
        $result = null;
        $count = countQueries(function () use ($component, &$result) {
            $result = $component->data(['taxonomy_slug' => 'du-an', 'limit' => 6, 'locale' => 'vi']);
        });

        expect($result['items'])->toHaveCount(3);
        // Fixed handful (resolve + entries + eager translations/routes/sectors/sector-translations).
        // An N+1 over the cards would push this well past the bound.
        expect($count)->toBeLessThanOrEqual(7);
    });
});
