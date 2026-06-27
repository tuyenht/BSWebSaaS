<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Actions\SeedAnVinhContent;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteProfile;
use Illuminate\Support\Facades\DB;

/*
 * SeedAnVinhContent: Cơ Khí Chính Xác An Vinh content bootstrap (precision mechanical pivot).
 * Replaces the insulation/Sơn-Hà clone content with the real An Vinh identity:
 * san-pham (6 products + 3 sub-cats), du-an (6 projects), dich-vu (4 services),
 * tin-tuc (6 news posts), /gioi-thieu, /doi-tac pages, home/contact body updates,
 * and real NAP on SiteProfile. Idempotent.
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

function seedPrecisionSite(): Site
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    return Site::query()->firstWhere('name', 'An Vinh');
}

it('seeds san-pham with 3 sub-categories and 6 products', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        $sanPhamTranslation = TaxonomyTranslation::query()->where('slug', 'san-pham')->first();
        expect($sanPhamTranslation)->not->toBeNull();
        $sanPham = Taxonomy::query()->findOrFail($sanPhamTranslation->taxonomy_id);
        expect($sanPham->vocabulary)->toBe('post_cat');

        // 3 sub-categories as children of san-pham (vocabulary = 'product_cat')
        $subCats = Taxonomy::query()->where('vocabulary', 'product_cat')->where('parent_id', $sanPham->id)->get();
        expect($subCats->count())->toBe(3);
        $subSlugs = TaxonomyTranslation::query()->whereIn('taxonomy_id', $subCats->pluck('id'))->pluck('slug')->sort()->values();
        expect($subSlugs->all())->toBe(['chi-tiet-may', 'do-ga', 'khuon-mau']);

        // 6 published products under san-pham, each routed at /san-pham/{slug}
        expect($sanPham->entries()->where('entries.type', 'post')->count())->toBe(6);
        expect(SiteRoute::query()->where('path', '/san-pham')->exists())->toBeTrue();
        expect(SiteRoute::query()->where('path', 'like', '/san-pham/%')->count())->toBe(6);

        // Product body contains richtext blocks with specs
        $product = Entry::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'truc-may-cnc'))
            ->first();
        expect($product)->not->toBeNull();
        expect($product->meta['image_url'])->toBe('an-vinh/san-pham/sp-truc-may-cnc-92f8d329e8dc.jpg');
        $productTranslation = $product->translationFor('vi');
        expect($productTranslation)->not->toBeNull();
        $bodyBlocks = $productTranslation->body['blocks'] ?? [];
        $types = array_column($bodyBlocks, 'type');
        expect(in_array('richtext', $types, true))->toBeTrue();

        // Admin nav section registered
        expect(ContentSection::query()->where('key', 'products')->exists())->toBeTrue();
        expect(MenuItem::query()->where('routable_type', 'taxonomy')->where('routable_id', $sanPham->id)->exists())->toBeTrue();
    });
});

it('seeds du-an with 6 projects, route, and no sectors', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        $duAnTranslation = TaxonomyTranslation::query()->where('slug', 'du-an')->first();
        expect($duAnTranslation)->not->toBeNull();
        $duAn = Taxonomy::query()->findOrFail($duAnTranslation->taxonomy_id);
        expect($duAn->vocabulary)->toBe('post_cat');

        // Precision An Vinh has NO sector sub-terms (not an insulation plant company)
        expect(Taxonomy::query()->where('vocabulary', 'sector')->where('parent_id', $duAn->id)->count())->toBe(0);

        // 6 published project posts, each routed at /du-an/{slug}
        expect($duAn->entries()->where('entries.type', 'post')->count())->toBe(6);
        expect(SiteRoute::query()->where('path', '/du-an')->exists())->toBeTrue();
        expect(SiteRoute::query()->where('path', 'like', '/du-an/%')->count())->toBe(6);

        // Real precision-mechanical projects (no insulation/plant references)
        $project = Entry::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'day-chuyen-lap-rap-tu-dong'))
            ->first();
        expect($project)->not->toBeNull();
        expect($project->meta['image_url'])->toBe('an-vinh/du-an/da-day-chuyen-lap-rap-tu-dong-5a538d6e5aeb.jpg');

        expect(ContentSection::query()->where('key', 'projects')->exists())->toBeTrue();
        expect(MenuItem::query()->where('routable_type', 'taxonomy')->where('routable_id', $duAn->id)->exists())->toBeTrue();
    });
});

it('seeds dich-vu with 4 precision-mechanical services and glyph icons', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        $dichVuTranslation = TaxonomyTranslation::query()->where('slug', 'dich-vu')->first();
        expect($dichVuTranslation)->not->toBeNull();
        $dichVu = Taxonomy::query()->findOrFail($dichVuTranslation->taxonomy_id);
        expect($dichVu->vocabulary)->toBe('post_cat');

        // 4 precision-mechanical services (not 5 insulation services)
        expect(SiteRoute::query()->where('path', 'like', '/dich-vu/%')->count())->toBe(4);

        // Each service carries a glyph key as its featured image (no slash → rendered as inline icon)
        $service = Entry::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'thiet-ke-jig-do-ga'))
            ->first();
        expect($service)->not->toBeNull();
        expect($service->meta['image_url'])->toBe('cog');

        $service2 = Entry::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'gia-cong-phay-tien-cnc'))
            ->first();
        expect($service2)->not->toBeNull();
        expect($service2->meta['image_url'])->toBe('box');

        expect(ContentSection::query()->where('key', 'services')->exists())->toBeTrue();
        expect(MenuItem::query()->where('routable_type', 'taxonomy')->where('routable_id', $dichVu->id)->exists())->toBeTrue();
    });
});

it('seeds 6 tin-tuc news posts under the ProvisionSite news category', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        // tin-tuc taxonomy already existed from ProvisionSite — not created again
        expect(TaxonomyTranslation::query()->where('slug', 'tin-tuc')->count())->toBe(1);

        $tinTucTranslation = TaxonomyTranslation::query()->where('slug', 'tin-tuc')->first();
        $tinTuc = Taxonomy::query()->findOrFail($tinTucTranslation->taxonomy_id);

        // 6 news posts with real An Vinh content
        expect($tinTuc->entries()->where('entries.type', 'post')->count())->toBe(6);
        expect(SiteRoute::query()->where('path', 'like', '/tin-tuc/%')->count())->toBe(6);

        $newsPost = Entry::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'an-vinh-to-chuc-team-building-2024'))
            ->first();
        expect($newsPost)->not->toBeNull();
        expect($newsPost->meta['image_url'])->toBe('an-vinh/tin-tuc/tt-team-building-2024-5a967c5f98dd.jpg');
    });
});

it('updates the home page body with 4 entry_grid blocks covering all collections', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        $homeRoute = SiteRoute::query()->where('path', '/')->first();
        expect($homeRoute)->not->toBeNull();

        $homeEntry = Entry::query()->find($homeRoute->entry_id);
        expect($homeEntry)->not->toBeNull();

        $translation = $homeEntry->translationFor('vi');
        expect($translation)->not->toBeNull();

        $bodyJson = (string) json_encode($translation->body, JSON_UNESCAPED_UNICODE);

        // All 4 collection entry_grid blocks present
        expect($bodyJson)->toContain('"taxonomy_slug":"dich-vu"');
        expect($bodyJson)->toContain('"taxonomy_slug":"san-pham"');
        expect($bodyJson)->toContain('"taxonomy_slug":"du-an"');
        expect($bodyJson)->toContain('"taxonomy_slug":"tin-tuc"');

        // Hero section with An Vinh branding
        expect($bodyJson)->toContain('"heading":"Cơ Khí Chính Xác An Vinh"');

        // About section with pre-resolved image URL (from MediaUrlResolver, not a raw key)
        expect($bodyJson)->toContain('"image_url":"http');

        // Contact section with real NAP
        expect($bodyJson)->toContain('0915 66 83 83');
        expect($bodyJson)->toContain('quybv@ckanvinh.vn');
    });
});

it('updates the contact page with real NAP and factory list', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        $contactRoute = SiteRoute::query()->where('path', '/lien-he')->first();
        expect($contactRoute)->not->toBeNull();

        $contactEntry = Entry::query()->find($contactRoute->entry_id);
        $translation = $contactEntry->translationFor('vi');
        $bodyJson = (string) json_encode($translation->body, JSON_UNESCAPED_UNICODE);

        expect($bodyJson)->toContain('0915 66 83 83');
        expect($bodyJson)->toContain('quybv@ckanvinh.vn');
        expect($bodyJson)->toContain('Xuân Sơn');  // 'Xuân Sơn' in JSON

        // Factory list in richtext block
        expect($bodyJson)->toContain('Thạch Thất');  // 'Thạch Thất' — factory 3 address
    });
});

it('creates /gioi-thieu page with route, nav item, and about content', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        expect(SiteRoute::query()->where('path', '/gioi-thieu')->exists())->toBeTrue();

        $route = SiteRoute::query()->where('path', '/gioi-thieu')->first();
        $entry = Entry::query()->find($route->entry_id);
        expect($entry->type)->toBe('page');

        $translation = $entry->translationFor('vi');
        expect($translation->title)->toBe('Giới thiệu');

        $bodyJson = (string) json_encode($translation->body, JSON_UNESCAPED_UNICODE);
        // About section, vision/mission, core values, ISO section
        expect($bodyJson)->toContain('Tầm Nhìn');   // 'Tầm Nhìn'
        expect($bodyJson)->toContain('Sứ Mệnh');    // 'Sứ Mệnh'
        expect($bodyJson)->toContain('ISO 9001:2015');

        // Nav item linking to this entry
        expect(
            MenuItem::query()
                ->where('link_type', 'entry')
                ->where('routable_type', 'entry')
                ->where('routable_id', $entry->id)
                ->exists()
        )->toBeTrue();
    });
});

it('creates /doi-tac page with route, nav item, partner names, and testimonials', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        expect(SiteRoute::query()->where('path', '/doi-tac')->exists())->toBeTrue();

        $route = SiteRoute::query()->where('path', '/doi-tac')->first();
        $entry = Entry::query()->find($route->entry_id);
        $translation = $entry->translationFor('vi');
        $bodyJson = (string) json_encode($translation->body, JSON_UNESCAPED_UNICODE);

        // 10 real partner names present
        expect($bodyJson)->toContain('VinFast');
        expect($bodyJson)->toContain('Hutchinson');
        expect($bodyJson)->toContain('Mitsuboshi');
        expect($bodyJson)->toContain('Vinmanutech');

        // 2 testimonials present
        expect($bodyJson)->toContain('Trưởng phòng Kỹ thuật');  // 'Trưởng phòng Kỹ thuật'

        // Nav item for /doi-tac
        expect(
            MenuItem::query()
                ->where('link_type', 'entry')
                ->where('routable_type', 'entry')
                ->where('routable_id', $entry->id)
                ->exists()
        )->toBeTrue();
    });
});

it('sets brand and NAP on SiteProfile', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        $profile = SiteProfile::query()->first();
        expect($profile)->not->toBeNull();
        expect($profile->brand_name)->toBe('Cơ Khí Chính Xác An Vinh');
        expect($profile->legal_name)->toBe('CÔNG TY TNHH THƯƠNG MẠI VÀ DỊCH VỤ THIẾT KẾ GIA CÔNG CƠ KHÍ AN VINH');
        expect($profile->tax_code)->toBe('0108649706');
        expect($profile->phone)->toBe('0915 66 83 83');
        expect($profile->email)->toBe('quybv@ckanvinh.vn');
    });
});

it('contains no insulation, Formosa, or Mông Dương strings', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        // Entry titles/excerpts: no insulation text
        expect(EntryTranslation::query()->where('title', 'like', '%cách nhiệt%')->count())->toBe(0);
        expect(EntryTranslation::query()->where('title', 'like', '%bảo ôn%')->count())->toBe(0);
        expect(EntryTranslation::query()->where('title', 'like', '%giàn giáo%')->count())->toBe(0);
        expect(EntryTranslation::query()->where('title', 'like', '%Formosa%')->count())->toBe(0);
        expect(EntryTranslation::query()->where('title', 'like', '%Mông Dương%')->count())->toBe(0);

        // Taxonomy names: no sector (insulation industry classification)
        expect(Taxonomy::query()->where('vocabulary', 'sector')->count())->toBe(0);
    });
});

it('is idempotent (re-run does not duplicate)', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();
        app(SeedAnVinhContent::class)->handle();  // second run

        // 6 products + 6 projects + 4 services + 6 news = 22 posts
        expect(Entry::query()->where('type', 'post')->count())->toBe(22);

        // tin-tuc (ProvisionSite) + san-pham + du-an + dich-vu = 4
        expect(Taxonomy::query()->where('vocabulary', 'post_cat')->count())->toBe(4);

        // do-ga + khuon-mau + chi-tiet-may = 3
        expect(Taxonomy::query()->where('vocabulary', 'product_cat')->count())->toBe(3);

        // Routes per collection
        expect(SiteRoute::query()->where('path', 'like', '/san-pham/%')->count())->toBe(6);
        expect(SiteRoute::query()->where('path', 'like', '/du-an/%')->count())->toBe(6);
        expect(SiteRoute::query()->where('path', 'like', '/dich-vu/%')->count())->toBe(4);
        expect(SiteRoute::query()->where('path', 'like', '/tin-tuc/%')->count())->toBe(6);

        // New pages created exactly once
        expect(SiteRoute::query()->where('path', '/gioi-thieu')->count())->toBe(1);
        expect(SiteRoute::query()->where('path', '/doi-tac')->count())->toBe(1);

        // Exactly 5 admin content sections: pages + posts + products + services + projects
        expect(ContentSection::query()->count())->toBe(5);
    });
});

it('repositions the tin-tuc menu item to position 8 (after all An Vinh sections)', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        app(SeedAnVinhContent::class)->handle();

        // ProvisionSite seeds tin-tuc at position 1 (right after Trang chủ at 0).
        // SeedAnVinhContent must move it to position 8 so the final left→right order is:
        // Trang chủ(0) · Giới thiệu(2) · Dịch vụ(3) · Sản phẩm(4) · Dự án(5) · Đối tác(6) · Liên hệ(7) · Tin tức(8)
        $tinTucTranslation = TaxonomyTranslation::query()->where('slug', 'tin-tuc')->first();
        expect($tinTucTranslation)->not->toBeNull();
        $tinTuc = Taxonomy::query()->findOrFail($tinTucTranslation->taxonomy_id);

        $item = MenuItem::query()
            ->where('link_type', 'taxonomy')
            ->where('routable_type', 'taxonomy')
            ->where('routable_id', $tinTuc->id)
            ->first();

        expect($item)->not->toBeNull();
        expect($item->position)->toBe(8);
    });
});

it('creates the contact page when the site predates /lien-he (prod av1 parity)', function () {
    $site = seedPrecisionSite();

    app(Tenancy::class)->enter($site->id, function () {
        // Prod av1 was provisioned before ProvisionSite seeded a contact page — it had only
        // / + /tin-tuc. Drop /lien-he so the seeder must CREATE it (a plain "update" would no-op
        // and leave the home/about CTAs dangling on a 404).
        $route = SiteRoute::query()->where('path', '/lien-he')->first();
        if ($route !== null) {
            $entryId = $route->entry_id;
            $route->delete();
            Entry::query()->whereKey($entryId)->delete();
        }
        expect(SiteRoute::query()->where('path', '/lien-he')->exists())->toBeFalse();

        app(SeedAnVinhContent::class)->handle();

        // Recreated with the real NAP + factory list + a nav item — the CTA target now resolves.
        $route = SiteRoute::query()->where('path', '/lien-he')->first();
        expect($route)->not->toBeNull();
        $body = json_encode(Entry::query()->findOrFail($route->entry_id)->translationFor('vi')->body, JSON_UNESCAPED_UNICODE);
        expect($body)->toContain('0915 66 83 83')->toContain('quybv@ckanvinh.vn')->toContain('Nhà máy 3');
        expect(MenuItem::query()->where('routable_type', 'entry')->where('routable_id', $route->entry_id)->exists())->toBeTrue();
    });
});
