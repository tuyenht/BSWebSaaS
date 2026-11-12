<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\EnsureMediaFromKey;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;

/**
 * Fill a provisioned measurement site with the storefront's richer looks, so the CI `cwv` job
 * measures them on real pages: theme tokens, header and footer settings, nine photo rows pointing
 * at the CI fixture files (`tests/Fixtures/cwv`, served by the static media driver), a `/do-kiem`
 * page (slider, service icon cards and product photo cards as card rails with arrows, which the
 * job's tap check needs on a phone, top-rule features, a rounded call to action, contact cards) and
 * `/ban-tin`, a news card grid with two child categories. The
 * provisioned `/tin-tuc` list is left as it is.
 *
 * White-label: neutral sample text, no brand. Idempotent: rows are looked up by slug, storage key,
 * setting key or route path and refreshed or left as they are. Runs inside the site's tenant
 * context (Tenancy::enter), like SeedAnVinhContent.
 */
final readonly class SeedMeasurementSite
{
    /** Where the CI job copies the fixture photos, under the static media base. */
    public const string KEY_PREFIX = 'cwv/library/';

    /** @var array<string, array{0: int, 1: int}> photo name => master width, height */
    public const array PHOTOS = [
        'slide-1' => [640, 360], 'slide-2' => [640, 360], 'slide-3' => [640, 360],
        'product-1' => [640, 480], 'product-2' => [640, 480], 'product-3' => [640, 480],
        'news-1' => [640, 400], 'news-2' => [640, 400], 'news-3' => [640, 400],
    ];

    public const string PAGE_PATH = '/do-kiem';

    public const string NEWS_PATH = '/ban-tin';

    public function __construct(
        private RouteSync $routes,
        private TaxonomyPaths $paths,
        private EnsureMediaFromKey $media,
    ) {}

    public function handle(): void
    {
        $defaultLocale = SiteLocale::query()->where('is_default', true)->value('locale');
        $locale = is_string($defaultLocale) ? $defaultLocale : 'vi';

        $this->seedSettings();
        $photos = $this->seedPhotos();
        $this->seedServices($locale);
        $this->seedProducts($locale, $photos);
        $this->seedNews($locale, $photos);
        $this->seedPage($locale, $photos);
    }

    private function seedSettings(): void
    {
        $settings = [
            ['theme', 'tokens', [
                'primary' => '#f59e0b', 'ink' => '#0f172a', 'font' => 'Inter, system-ui, sans-serif',
                'color-surface-tint' => '#f1f5f9', 'text-base' => '17px',
            ]],
            ['storefront', 'header', [
                'top_bar' => ['items' => [
                    ['kind' => 'phone', 'text' => '0900 000 000', 'value' => '0900000000'],
                    ['kind' => 'email', 'text' => 'lienhe@example.test', 'value' => 'lienhe@example.test'],
                ]],
                'text_size' => true,
            ]],
            ['storefront', 'footer', [
                'about' => 'Gia công cơ khí chính xác, đồ gá và khuôn mẫu theo bản vẽ.',
                'factories' => [['title' => 'Nhà máy', 'address' => 'Khu công nghiệp mẫu', 'map_url' => 'https://maps.google.com/?q=khu+cong+nghiep']],
            ]],
        ];
        foreach ($settings as [$group, $key, $value]) {
            SiteSetting::query()->updateOrCreate(['group_name' => $group, 'key' => $key], ['value' => $value]);
        }
    }

    /** @return array<string, Media> */
    private function seedPhotos(): array
    {
        $photos = [];
        foreach (self::PHOTOS as $name => [$width, $height]) {
            $photos[$name] = $this->media->handle(self::KEY_PREFIX.$name.'.webp', ['width' => $width, 'height' => $height]);
        }

        return $photos;
    }

    /** Four services whose cards draw a glyph (the post's `image_url` is a glyph key). */
    private function seedServices(string $locale): void
    {
        $services = $this->category($locale, 'Dịch vụ', 'dich-vu', 'post_cat');
        $at = CarbonImmutable::parse('2026-10-01 08:00', 'UTC');
        foreach ([
            ['Thiết kế đồ gá', 'thiet-ke-do-ga', 'lock', 'Đồ gá kiểm và đồ gá hàn theo bản vẽ, tối ưu cho dây chuyền.'],
            ['Hiệu chỉnh khuôn', 'hieu-chinh-khuon', 'adjust', 'Sửa và hiệu chỉnh khuôn dập, khuôn ép đang chạy.'],
            ['Bảo trì máy', 'bao-tri-may', 'wrench', 'Bảo trì định kỳ, thay thế phụ tùng, giảm thời gian dừng máy.'],
            ['Gia công CNC', 'gia-cong-cnc', 'machine', 'Phay, tiện CNC nhiều trục với dung sai nhỏ.'],
        ] as $n => [$title, $slug, $glyph, $excerpt]) {
            $this->post($locale, $services, $title, $slug, $glyph, $excerpt, $at->subDays($n));
        }
    }

    /** @param array<string, Media> $photos */
    private function seedProducts(string $locale, array $photos): void
    {
        $jigs = $this->category($locale, 'Đồ gá', 'do-ga-mau', 'product_cat', route: false);
        $moulds = $this->category($locale, 'Khuôn mẫu', 'khuon-mau', 'product_cat', route: false);
        $at = CarbonImmutable::parse('2026-10-01 08:00', 'UTC');
        foreach ([
            ['Đồ gá kiểm bảng táp-lô', 'do-ga-kiem-tap-lo', $jigs, 'product-1'],
            ['Đồ gá hàn khung', 'do-ga-han-khung', $jigs, 'product-2'],
            ['Chi tiết phay CNC', 'chi-tiet-phay-cnc', $moulds, 'product-3'],
        ] as $n => [$name, $slug, $category, $photo]) {
            if (ProductTranslation::query()->where('locale', $locale)->where('slug', $slug)->exists()) {
                continue;
            }
            $product = Product::query()->create(['status' => 'published', 'published_at' => $at->subDays($n)]);
            $product->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => $slug]);
            $product->assignCategories([$category->id]);   // the shared write path keeps the category totals
            $product->images()->create(['media_id' => $photos[$photo]->id, 'position' => 0]);
            try {
                $this->routes->syncProduct($product->id, null, $locale, $slug);
            } catch (RouteConflictException) {
                // A page already owns this path; leave it.
            }
        }
    }

    /**
     * `/ban-tin` as a news card grid: its own category (the provisioned `/tin-tuc` list stays
     * as provisioning made it), two routed child categories on the same layout and six posts with
     * photos, two on the parent and two on each child.
     *
     * @param  array<string, Media>  $photos
     */
    private function seedNews(string $locale, array $photos): void
    {
        $news = $this->category($locale, 'Bản tin', ltrim(self::NEWS_PATH, '/'), 'post_cat', null, 0, 'grid-news');
        $company = $this->category($locale, 'Tin công ty', 'tin-cong-ty', 'post_cat', $news, 1, 'grid-news');
        $industry = $this->category($locale, 'Tin ngành', 'tin-nganh', 'post_cat', $news, 2, 'grid-news');

        $at = CarbonImmutable::parse('2026-10-20 08:00', 'UTC');
        foreach ([
            [$news, 'Khánh thành xưởng gia công mới', 'khanh-thanh-xuong-moi', 'news-1'],
            [$news, 'Ứng dụng in 3D kim loại', 'ung-dung-in-3d-kim-loai', 'news-2'],
            [$company, 'Đào tạo vận hành máy 5 trục', 'dao-tao-may-5-truc', 'news-3'],
            [$company, 'Tự động hóa khâu cấp phôi', 'tu-dong-hoa-cap-phoi', 'news-1'],
            [$industry, 'Vật liệu mới cho khuôn dập', 'vat-lieu-moi-khuon-dap', 'news-2'],
            [$industry, 'Xu hướng gia công chính xác', 'xu-huong-gia-cong-chinh-xac', 'news-3'],
        ] as $n => [$category, $title, $slug, $photo]) {
            $this->post($locale, $category, $title, $slug, $photos[$photo]->storage_key, 'Tin mẫu cho trang đo tốc độ.', $at->subDays($n));
        }
    }

    /** @param array<string, Media> $photos */
    private function seedPage(string $locale, array $photos): void
    {
        $blocks = [
            ['type' => 'hero_slider', 'props' => [
                'slides' => [
                    ['image_url' => $photos['slide-1']->id, 'heading' => 'Gia công cơ khí chính xác', 'subheading' => 'Đồ gá, khuôn mẫu và chi tiết máy theo bản vẽ.'],
                    ['image_url' => $photos['slide-2']->id, 'heading' => 'Hàn và lắp ráp', 'subheading' => 'Dây chuyền hàn robot và hàn tay.'],
                    ['image_url' => $photos['slide-3']->id, 'heading' => 'Khuôn dập, khuôn ép', 'subheading' => 'Thiết kế, chế tạo và bảo trì khuôn.'],
                ],
                'cta_label' => 'Nhận báo giá', 'cta_url' => '/lien-he',
                'cta2_label' => 'Xem tin tức', 'cta2_url' => self::NEWS_PATH,
                'autoplay' => 5,
            ]],
            ['type' => 'entry_grid', 'props' => [
                'heading' => 'Dịch vụ', 'description' => 'Giải pháp trọn gói từ thiết kế đến bảo trì.',
                'taxonomy_slug' => 'dich-vu', 'limit' => 4, 'variant' => 'icon-cards', 'bg' => 'tint',
                'layout' => 'auto', 'arrows' => 'always',
            ]],
            ['type' => 'product_grid', 'props' => [
                'heading' => 'Sản phẩm tiêu biểu', 'limit' => 3, 'variant' => 'overlay', 'bg' => 'surface',
                'layout' => 'auto', 'arrows' => 'always',
            ]],
            ['type' => 'features', 'props' => [
                'heading' => 'Năng lực', 'variant' => 'top-rule',
                'items' => [
                    ['title' => 'Máy CNC nhiều trục', 'text' => 'Phay, tiện với dung sai nhỏ.', 'icon' => 'machine'],
                    ['title' => 'Kiểm tra chất lượng', 'text' => 'Đo kiểm từng lô trước khi giao.', 'icon' => 'eye'],
                    ['title' => 'Đội kỹ thuật', 'text' => 'Kỹ sư thiết kế và vận hành lâu năm.', 'icon' => 'wrench'],
                ],
            ]],
            ['type' => 'cta', 'props' => [
                'heading' => 'Cần báo giá cho bản vẽ của bạn?', 'text' => 'Gửi bản vẽ, nhận báo giá trong 24 giờ.',
                'button_label' => 'Liên hệ', 'button_url' => '/lien-he', 'variant' => 'rounded',
            ]],
            ['type' => 'contact', 'props' => [
                'variant' => 'cards', 'address_label' => 'Nhà máy', 'address' => 'Khu công nghiệp mẫu',
                'phone' => '0900000000', 'email' => 'lienhe@example.test',
            ]],
        ];

        $route = SiteRoute::query()->where('locale', $locale)->where('path', self::PAGE_PATH)->first();
        $entry = $route?->entry_id !== null ? Entry::query()->find($route->entry_id) : null;
        if ($entry !== null) {
            $entry->translationFor($locale)?->update(['body' => ['blocks' => $blocks]]);

            return;
        }

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create([
            'locale' => $locale, 'title' => 'Trang đo tốc độ', 'slug' => trim(self::PAGE_PATH, '/'), 'status' => 'published',
            'seo_description' => 'Trang mẫu dùng để đo tốc độ tải và khả năng truy cập.', 'body' => ['blocks' => $blocks],
        ]);
        SiteRoute::query()->create(['locale' => $locale, 'path' => self::PAGE_PATH, 'entry_id' => $entry->id]);
    }

    /** A category found by its localized slug, else created with its path (and its route). */
    private function category(string $locale, string $name, string $slug, string $vocabulary, ?Taxonomy $parent = null, int $position = 0, ?string $listTemplate = null, bool $route = true): Taxonomy
    {
        $existing = TaxonomyTranslation::query()->where('locale', $locale)->where('slug', $slug)->value('taxonomy_id');
        $category = is_numeric($existing) ? Taxonomy::query()->find((int) $existing) : null;
        if ($category === null) {
            $category = Taxonomy::query()->create([
                'vocabulary' => $vocabulary, 'parent_id' => $parent?->id, 'position' => $position, 'list_template' => $listTemplate,
            ]);
            $this->paths->refreshBranch($category->id);
            $category->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => $slug]);
        }
        if ($route) {
            try {
                $this->routes->syncTaxonomy($category, $locale, $slug);
            } catch (RouteConflictException) {
                // A page already owns this path; leave it.
            }
        }

        return $category;
    }

    /** A published post under $category, created once (looked up by its localized slug). */
    private function post(string $locale, Taxonomy $category, string $title, string $slug, string $image, string $excerpt, CarbonImmutable $publishedAt): void
    {
        if (EntryTranslation::query()->where('locale', $locale)->where('slug', $slug)->exists()) {
            return;
        }

        $entry = Entry::query()->create([
            'type' => 'post', 'status' => 'published', 'published_at' => $publishedAt, 'meta' => ['image_url' => $image],
        ]);
        $entry->translations()->create([
            'locale' => $locale, 'title' => $title, 'slug' => $slug, 'status' => 'published', 'excerpt' => $excerpt,
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => [$excerpt]]]]],
        ]);
        $entry->assignCategories([$category->id]);
        try {
            $this->routes->sync($entry, $locale, $slug);
        } catch (RouteConflictException) {
            // A page already owns this path; leave it.
        }
    }
}
