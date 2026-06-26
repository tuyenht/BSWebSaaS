<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;

/**
 * Bootstrap An Vinh's project content — kept OUT of the generic ProvisionSite so the other
 * 10k tenants stay clean: the "Dự án" (du-an) post category, its industrial sector terms, the
 * real project references (§6), the storefront route, and the nav item. Idempotent — anchored
 * on each localized slug (taxonomies/entries have no natural key), safe to re-run on dev + prod.
 *
 * Bootstrap stubs only: real per-project text + pre-optimized R2 images land with the WP seed
 * (Stream-4 T6/T8). Runs INSIDE the tenant context (the caller enters it) so site_id is stamped
 * and RLS-scoped.
 */
final class SeedAnVinhContent
{
    private const PROJECTS_SLUG = 'du-an';

    /** A generic, true-for-every-reference outcome line; replaced by real WP copy at T8. */
    private const OUTCOME = 'Cung cấp giải pháp cách nhiệt – bảo ôn công nghiệp.';

    /** @var array<string, string> sector slug => display name */
    private const SECTORS = [
        'nhiet-dien' => 'Nhiệt điện',
        'loc-hoa-dau' => 'Lọc hóa dầu',
        'xi-mang' => 'Xi măng',
        'phan-bon' => 'Phân bón',
        'thep' => 'Thép',
    ];

    /** @var list<array{title: string, slug: string, sector: string}> real An Vinh projects (§6) */
    private const PROJECTS = [
        ['title' => 'Formosa Hà Tĩnh', 'slug' => 'formosa-ha-tinh', 'sector' => 'thep'],
        ['title' => 'Nhiệt điện Mông Dương 1', 'slug' => 'nhiet-dien-mong-duong-1', 'sector' => 'nhiet-dien'],
        ['title' => 'Lọc hóa dầu Nghi Sơn', 'slug' => 'loc-hoa-dau-nghi-son', 'sector' => 'loc-hoa-dau'],
        ['title' => 'Xi măng Xuân Thành', 'slug' => 'xi-mang-xuan-thanh', 'sector' => 'xi-mang'],
        ['title' => 'Nhiệt điện Sông Hậu 1', 'slug' => 'nhiet-dien-song-hau-1', 'sector' => 'nhiet-dien'],
        ['title' => 'Nhà máy phân bón Brunei', 'slug' => 'nha-may-phan-bon-brunei', 'sector' => 'phan-bon'],
        ['title' => 'Nhiệt điện Vũng Áng II', 'slug' => 'nhiet-dien-vung-ang-ii', 'sector' => 'nhiet-dien'],
        ['title' => 'Vận hành & bảo trì Vũng Áng II', 'slug' => 'van-hanh-bao-tri-vung-ang-ii', 'sector' => 'nhiet-dien'],
        ['title' => 'Điện – Đạm Brunei', 'slug' => 'dien-dam-brunei', 'sector' => 'phan-bon'],
    ];

    private const SERVICES_SLUG = 'dich-vu';

    /** @var list<array{title: string, slug: string, image: string, outcome: string}> An Vinh services (§6); image = a curated glyph key */
    private const SERVICES = [
        ['title' => 'Bảo ôn – cách nhiệt vật tư', 'slug' => 'bao-on-cach-nhiet-vat-tu', 'image' => 'shield', 'outcome' => 'Vật tư bảo ôn, cách nhiệt công nghiệp.'],
        ['title' => 'Cách nhiệt & giàn giáo', 'slug' => 'cach-nhiet-gian-giao', 'image' => 'box', 'outcome' => 'Thi công cách nhiệt kèm lắp dựng giàn giáo.'],
        ['title' => 'Nhân lực O&M nhà máy', 'slug' => 'nhan-luc-om-nha-may', 'image' => 'users', 'outcome' => 'Cung ứng nhân lực vận hành & bảo trì nhà máy.'],
        ['title' => 'Thiết bị & vật tư thay thế', 'slug' => 'thiet-bi-vat-tu-thay-the', 'image' => 'cog', 'outcome' => 'Cung cấp thiết bị, vật tư thay thế công nghiệp.'],
        ['title' => 'Cho thuê xe nâng', 'slug' => 'cho-thue-xe-nang', 'image' => 'truck', 'outcome' => 'Dịch vụ cho thuê xe nâng các tải trọng.'],
    ];

    public function __construct(private readonly RouteSync $routes) {}

    public function handle(): void
    {
        $defaultLocale = SiteLocale::query()->where('is_default', true)->value('locale');
        $locale = is_string($defaultLocale) ? $defaultLocale : 'vi';

        $category = $this->firstOrCreateTaxonomy($locale, self::PROJECTS_SLUG, 'Dự án', 'post_cat');

        ContentSection::query()->firstOrCreate(
            ['key' => 'projects'],
            ['label' => 'Dự án', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 2],
        );

        // Storefront list route (/du-an). Best-effort: never fail if a page owns the path.
        $translation = $category->translationFor($locale);
        if ($translation !== null) {
            try {
                $this->routes->syncTaxonomy($category, $locale, $translation->slug);
            } catch (RouteConflictException) {
                // A page already owns this path; leave it.
            }
        }

        $sectors = [];
        foreach (self::SECTORS as $slug => $name) {
            $sectors[$slug] = $this->firstOrCreateTaxonomy($locale, $slug, $name, 'sector', $category->id);
        }

        foreach (self::PROJECTS as $project) {
            // Every project's sector is one of self::SECTORS (enforced by Larastan), so the lookup always hits.
            $this->firstOrCreatePost($locale, $project['title'], $project['slug'], self::OUTCOME, '', $category, $sectors[$project['sector']]);
        }

        $this->addMenuItem($locale, $category, 'Dự án', 2);

        $this->seedServices($locale);
    }

    /**
     * Services are POSTS (title / featured image / description) with a detail page — the homepage
     * shows them data-bound via entry_grid(taxonomy_slug='dich-vu'), each card linking to
     * /dich-vu/{slug}. Featured image here is a curated glyph key (rendered as an inline SVG icon).
     */
    private function seedServices(string $locale): void
    {
        $category = $this->firstOrCreateTaxonomy($locale, self::SERVICES_SLUG, 'Dịch vụ', 'post_cat');

        ContentSection::query()->firstOrCreate(
            ['key' => 'services'],
            ['label' => 'Dịch vụ', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1],
        );

        $translation = $category->translationFor($locale);
        if ($translation !== null) {
            try {
                $this->routes->syncTaxonomy($category, $locale, $translation->slug);
            } catch (RouteConflictException) {
                // A page already owns this path; leave it.
            }
        }

        foreach (self::SERVICES as $service) {
            $this->firstOrCreatePost($locale, $service['title'], $service['slug'], $service['outcome'], $service['image'], $category, null);
        }

        $this->addMenuItem($locale, $category, 'Dịch vụ', 1);
    }

    /** Looked up by localized slug (taxonomies have no natural unique key). */
    private function firstOrCreateTaxonomy(string $locale, string $slug, string $name, string $vocabulary, ?int $parentId = null): Taxonomy
    {
        $existing = TaxonomyTranslation::query()->where('locale', $locale)->where('slug', $slug)->first();
        if ($existing !== null) {
            return Taxonomy::query()->findOrFail($existing->taxonomy_id);
        }

        $taxonomy = Taxonomy::query()->create(['vocabulary' => $vocabulary, 'parent_id' => $parentId, 'position' => 0]);
        $taxonomy->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => $slug]);

        return $taxonomy;
    }

    /**
     * Create a published post (project / service…) under a category, optionally with a sector, and
     * route it at /{category}/{slug}. The featured image (a glyph key OR a URL) → meta['image_url'].
     */
    private function firstOrCreatePost(string $locale, string $title, string $slug, string $excerpt, string $image, Taxonomy $category, ?Taxonomy $sector): void
    {
        if (EntryTranslation::query()->where('locale', $locale)->where('slug', $slug)->exists()) {
            return;
        }

        $entry = Entry::query()->create([
            'type' => 'post',
            'status' => 'published',
            'published_at' => now(),
            'meta' => ['image_url' => $image],   // featured image: a glyph key OR a URL (Option C)
        ]);
        $entry->translations()->create([
            'locale' => $locale,
            'title' => $title,
            'slug' => $slug,
            'status' => 'published',
            'excerpt' => $excerpt,
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => [$excerpt]]]]],
        ]);

        // The category is PRIMARY (pos 0) → routes at /{category}/{slug}; the sector (if any) is secondary.
        $ids = [$category->id];
        if ($sector !== null) {
            $ids[] = $sector->id;
        }
        $entry->assignCategories($ids);

        try {
            $this->routes->sync($entry, $locale, $slug);
        } catch (RouteConflictException) {
            // A page already owns this path; leave it.
        }
    }

    private function addMenuItem(string $locale, Taxonomy $category, string $label, int $position): void
    {
        $menu = Menu::query()->where('handle', 'main-'.$locale)->first();
        if ($menu === null) {
            return;
        }

        $item = MenuItem::query()->firstOrCreate(
            ['menu_id' => $menu->id, 'link_type' => 'taxonomy', 'routable_type' => 'taxonomy', 'routable_id' => $category->id],
            ['position' => $position],
        );
        $item->translations()->firstOrCreate(['locale' => $locale], ['label' => $label]);
    }
}
