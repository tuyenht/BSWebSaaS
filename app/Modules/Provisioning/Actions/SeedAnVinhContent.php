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
            $this->firstOrCreateProject($locale, $project, $category, $sectors[$project['sector']]);
        }

        $this->addMenuItem($locale, $category);
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
     * @param  array{title: string, slug: string, sector: string}  $project
     */
    private function firstOrCreateProject(string $locale, array $project, Taxonomy $category, Taxonomy $sector): void
    {
        $exists = EntryTranslation::query()->where('locale', $locale)->where('slug', $project['slug'])->exists();
        if ($exists) {
            return;
        }

        $entry = Entry::query()->create([
            'type' => 'post',
            'status' => 'published',
            'published_at' => now(),
            'meta' => ['image_url' => ''],   // pre-optimized R2 image lands at T6 (Option C)
        ]);
        $entry->translations()->create([
            'locale' => $locale,
            'title' => $project['title'],
            'slug' => $project['slug'],
            'status' => 'published',
            'excerpt' => self::OUTCOME,
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => [self::OUTCOME]]]]],
        ]);

        // du-an is the PRIMARY category (pos 0) → routes at /du-an/{slug}; the sector is secondary.
        $entry->assignCategories([$category->id, $sector->id]);

        try {
            $this->routes->sync($entry, $locale, $project['slug']);
        } catch (RouteConflictException) {
            // A page already owns this path; leave it.
        }
    }

    private function addMenuItem(string $locale, Taxonomy $category): void
    {
        $menu = Menu::query()->where('handle', 'main-'.$locale)->first();
        if ($menu === null) {
            return;
        }

        $item = MenuItem::query()->firstOrCreate(
            ['menu_id' => $menu->id, 'link_type' => 'taxonomy', 'routable_type' => 'taxonomy', 'routable_id' => $category->id],
            ['position' => 2],
        );
        $item->translations()->firstOrCreate(['locale' => $locale], ['label' => 'Dự án']);
    }
}
