<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\EnsureMediaFromKey;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\LayoutSlots;
use Carbon\CarbonImmutable;

/**
 * Fill a provisioned measurement site with the storefront's richer looks, so the CI `cwv` job
 * measures them on real pages: theme tokens, header and footer settings, nine photo rows pointing
 * at the CI fixture files (`tests/Fixtures/cwv`, served by the static media driver), a `/do-kiem`
 * page (slider, service icon cards and product photo cards as card rails with arrows, which the
 * job's tap check needs on a phone, top-rule features, a rounded call to action, contact cards) and
 * `/ban-tin`, a news card grid with two child categories, and a "Dịch vụ" main-menu item with two
 * sub-items (so the job's phone-menu pass measures parent rows, their arrows and sub-links). The
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

    /** The first seeded post: its page carries the post-only blocks (a measured URL of its own). */
    public const string POST_PATH = '/khanh-thanh-xuong-moi';

    /** Three pages that together draw every block kind the registry knows (a test pins this). */
    public const string ABOUT_PATH = '/do-kiem-gioi-thieu';

    public const string CONTENT_PATH = '/do-kiem-noi-dung';

    public const string COMMERCE_PATH = '/do-kiem-thuong-mai';

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
        $services = $this->seedServices($locale);
        $this->seedProducts($locale, $photos);
        $this->seedNews($locale, $photos);
        $this->seedPage($locale, $photos);

        $ids = array_map(static fn (Media $media): int => (int) $media->id, $photos);
        foreach (self::extraPages($ids) as $path => [$title, $blocks]) {
            $this->seedBlockPage($locale, $path, $title, $blocks);
        }
        $this->seedNewsSlots($locale);
        $this->seedMenu($locale, $services);
    }

    /**
     * The blocks of the three extra pages, path => [title, blocks]. Every block kind of the registry
     * (the slot-only ones come from slotBlocks) appears on one of them with enough items to fill its
     * grid or rail, so the CI job measures each kind's real markup.
     *
     * @param  array<string, int>  $img  photo name => media id
     * @return array<string, array{0: string, 1: list<array<string, mixed>>}>
     */
    public static function extraPages(array $img): array
    {
        $photoNames = ['slide-1', 'slide-2', 'slide-3', 'product-1', 'product-2', 'product-3', 'news-1', 'news-2', 'news-3'];
        $pic = static fn (int $n): int => $img[$photoNames[$n % 9]];
        $names = ['Nguyễn Văn An', 'Trần Thị Bình', 'Lê Hoàng Cường', 'Phạm Minh Đức', 'Võ Thanh Hà', 'Đặng Quốc Khánh'];
        $roles = ['Giám đốc kỹ thuật', 'Trưởng phòng chất lượng', 'Kỹ sư thiết kế', 'Quản đốc xưởng', 'Kỹ sư CNC', 'Chuyên viên kinh doanh'];

        $about = [
            ['type' => 'hero', 'props' => [
                'heading' => 'Đối tác gia công cơ khí đáng tin cậy', 'subheading' => 'Hơn mười năm kinh nghiệm cùng đội ngũ kỹ sư tận tâm.',
                'image_url' => $img['slide-1'], 'cta_label' => 'Nhận báo giá', 'cta_url' => '/lien-he', 'cta2_label' => 'Xem tin tức', 'cta2_url' => self::NEWS_PATH,
            ]],
            ['type' => 'about', 'props' => [
                'heading' => 'Về chúng tôi', 'variant' => 'showcase', 'image_url' => $img['slide-2'],
                'paragraphs' => ['Chúng tôi chế tạo đồ gá, khuôn mẫu và chi tiết máy theo bản vẽ của khách hàng.', 'Mỗi lô hàng đều được đo kiểm trước khi giao, để bạn yên tâm đưa vào dây chuyền.'],
                'cta_label' => 'Liên hệ ngay', 'cta_url' => '/lien-he',
                'links' => [
                    ['icon' => 'phone', 'label' => 'Gọi tư vấn', 'url' => 'tel:0900000000'],
                    ['icon' => 'email', 'label' => 'Gửi email', 'url' => 'mailto:lienhe@example.test'],
                    ['icon' => 'map', 'label' => 'Xem đường đi', 'url' => '/lien-he'],
                ],
            ]],
            ['type' => 'stats', 'props' => [
                'heading' => 'Con số nổi bật', 'variant' => 'floating', 'items' => [
                    ['kind' => 'years', 'value' => 2012, 'suffix' => '', 'label' => 'Năm kinh nghiệm'],
                    ['kind' => 'number', 'value' => 120, 'suffix' => '+', 'label' => 'Khách hàng'],
                    ['kind' => 'number', 'value' => 35, 'suffix' => '', 'label' => 'Máy CNC'],
                    ['kind' => 'number', 'value' => 99, 'suffix' => '%', 'label' => 'Giao hàng đúng hẹn'],
                ],
            ]],
            ['type' => 'partners', 'props' => [
                'heading' => 'Đối tác tin cậy', 'description' => 'Những doanh nghiệp đã đồng hành cùng chúng tôi.',
                'logos' => array_map(static fn (int $n): array => ['image_url' => $pic($n), 'name' => 'Đối tác '.($n + 1), 'url' => ''], range(0, 7)),
            ]],
            ['type' => 'team', 'props' => [
                'heading' => 'Đội ngũ của chúng tôi', 'variant' => 'grid-3',
                'members' => array_map(static fn (int $n): array => ['name' => $names[$n], 'role' => $roles[$n], 'photo' => $pic($n)], range(0, 5)),
            ]],
            ['type' => 'testimonial', 'props' => [
                'heading' => 'Khách hàng nói gì', 'variant' => 'grid-3',
                'items' => array_map(static fn (int $n): array => [
                    'quote' => 'Sản phẩm đúng bản vẽ, giao hàng đúng hẹn và hỗ trợ kỹ thuật rất nhanh.',
                    'name' => $names[$n], 'role' => 'Khách hàng '.($n + 1), 'avatar' => $pic($n + 3),
                ], range(0, 3)),
            ]],
            ['type' => 'branches', 'props' => ['heading' => 'Nhà máy và chi nhánh', 'description' => 'Nơi bạn có thể đến thăm và trao đổi trực tiếp.']],
        ];

        $content = [
            ['type' => 'page_header', 'props' => ['title' => 'Tài liệu và hỏi đáp', 'subtitle' => 'Quy trình làm việc, câu hỏi thường gặp và tin mới.', 'image_url' => $img['news-1'], 'overlay' => 65]],
            ['type' => 'richtext', 'props' => ['heading' => 'Giới thiệu quy trình', 'paragraphs' => [
                'Mỗi đơn hàng bắt đầu từ bản vẽ kỹ thuật. Kỹ sư của chúng tôi kiểm tra dung sai, vật liệu và phương án gia công trước khi báo giá.',
                'Sau khi chốt phương án, xưởng lập kế hoạch sản xuất, đo kiểm từng công đoạn và bàn giao kèm biên bản kiểm tra.',
            ]]],
            ['type' => 'richtext_html', 'props' => ['html' => '<h2>Tiêu chuẩn áp dụng</h2><p>Chúng tôi làm việc theo <strong>dung sai ghi trên bản vẽ</strong> và kiểm tra bằng máy đo tọa độ.</p><ul><li>Kiểm tra vật liệu đầu vào</li><li>Đo kiểm trong quá trình gia công</li><li>Kiểm tra lần cuối trước khi giao</li></ul>']],
            ['type' => 'process', 'props' => ['heading' => 'Quy trình làm việc', 'description' => 'Bốn bước từ bản vẽ đến sản phẩm.', 'variant' => 'numeral', 'steps' => [
                ['title' => 'Tiếp nhận bản vẽ', 'text' => 'Nhận bản vẽ và yêu cầu kỹ thuật của bạn.'],
                ['title' => 'Báo giá', 'text' => 'Phản hồi báo giá và thời gian giao trong 24 giờ.'],
                ['title' => 'Gia công', 'text' => 'Sản xuất trên máy CNC, đo kiểm từng công đoạn.'],
                ['title' => 'Bàn giao', 'text' => 'Giao hàng kèm biên bản đo kiểm.'],
            ]]],
            ['type' => 'video', 'props' => ['heading' => 'Xưởng của chúng tôi', 'url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'poster' => $img['slide-3'], 'player_width' => 'narrow', 'caption' => 'Giới thiệu xưởng gia công.']],
            ['type' => 'gallery', 'props' => ['heading' => 'Hình ảnh xưởng', 'columns' => '3', 'caption_style' => 'below', 'images' => array_map(static fn (int $n): array => ['image' => $pic($n), 'caption' => 'Khu vực gia công '.($n + 1)], range(0, 8))]],
            ['type' => 'faq', 'props' => ['heading' => 'Câu hỏi thường gặp', 'columns' => '2', 'style' => 'accordion', 'items' => [
                ['q' => 'Thời gian báo giá là bao lâu?', 'a' => 'Thông thường trong vòng 24 giờ làm việc kể từ khi nhận đủ bản vẽ.'],
                ['q' => 'Dung sai nhỏ nhất bạn gia công được?', 'a' => 'Tùy vật liệu và hình dạng, chúng tôi đạt tới cấp dung sai ghi trên bản vẽ.'],
                ['q' => 'Có nhận đơn hàng nhỏ lẻ không?', 'a' => 'Có, chúng tôi nhận từ một chi tiết để làm mẫu.'],
                ['q' => 'Có hỗ trợ thiết kế đồ gá không?', 'a' => 'Có, đội thiết kế sẽ đề xuất phương án phù hợp dây chuyền của bạn.'],
            ]]],
            ['type' => 'quick_links', 'props' => ['heading' => 'Liên kết nhanh', 'variant' => 'grid-2x2', 'items' => [
                ['icon' => 'phone', 'label' => 'Gọi tư vấn', 'url' => 'tel:0900000000'],
                ['icon' => 'email', 'label' => 'Gửi email', 'url' => 'mailto:lienhe@example.test'],
                ['icon' => 'map', 'label' => 'Bản đồ', 'url' => '/lien-he'],
                ['icon' => 'document', 'label' => 'Tin tức', 'url' => self::NEWS_PATH],
            ]]],
            ['type' => 'latest_posts', 'props' => ['heading' => 'Bài viết mới', 'limit' => 3]],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Tin công ty', 'taxonomy_slug' => 'ban-tin', 'limit' => 6, 'variant' => 'news-cards', 'tabs' => 'auto', 'layout' => 'auto', 'arrows' => 'always']],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Bài viết xen kẽ', 'taxonomy_slug' => 'ban-tin', 'limit' => 4, 'variant' => 'zigzag']],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Dự án tiêu biểu', 'taxonomy_slug' => 'ban-tin', 'limit' => 4, 'variant' => 'project-cards']],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Cơ hội nghề nghiệp', 'taxonomy_slug' => 'ban-tin', 'limit' => 6, 'variant' => 'job']],
            ['type' => 'form', 'props' => ['form_key' => 'contact', 'heading' => 'Gửi yêu cầu', 'description' => 'Để lại thông tin, chúng tôi sẽ liên hệ lại sớm.']],
        ];

        $commerce = [
            ['type' => 'product_grid', 'props' => ['heading' => 'Sản phẩm', 'limit' => 6, 'variant' => 'grid', 'layout' => 'grid']],
            ['type' => 'features', 'props' => ['heading' => 'Lợi ích', 'variant' => 'icon-head', 'items' => [
                ['title' => 'Đúng dung sai', 'text' => 'Đo kiểm từng lô hàng.', 'icon' => 'eye'],
                ['title' => 'Giao đúng hẹn', 'text' => 'Kế hoạch sản xuất rõ ràng.', 'icon' => 'machine'],
                ['title' => 'Hỗ trợ kỹ thuật', 'text' => 'Kỹ sư trả lời trong ngày.', 'icon' => 'wrench'],
            ]]],
            ['type' => 'features', 'props' => ['heading' => 'Cam kết', 'variant' => 'default', 'items' => [
                ['title' => 'Bảo hành', 'text' => 'Bảo hành theo hợp đồng.'],
                ['title' => 'Bảo mật bản vẽ', 'text' => 'Ký thỏa thuận bảo mật.'],
                ['title' => 'Giá minh bạch', 'text' => 'Báo giá chi tiết từng hạng mục.'],
            ]]],
            ['type' => 'pricing', 'props' => ['heading' => 'Gói dịch vụ', 'plans' => [
                ['name' => 'Cơ bản', 'price' => 1500000, 'unit' => 'đ/chi tiết', 'features' => "Gia công theo bản vẽ\nĐo kiểm cơ bản", 'cta_label' => 'Chọn gói', 'cta_url' => '/lien-he'],
                ['name' => 'Tiêu chuẩn', 'price' => 4500000, 'unit' => 'đ/lô', 'features' => "Gia công nhiều chi tiết\nBiên bản đo kiểm\nHỗ trợ thiết kế", 'cta_label' => 'Chọn gói', 'cta_url' => '/lien-he'],
                ['name' => 'Dự án', 'price' => 0, 'unit' => '', 'features' => "Đồ gá và khuôn mẫu\nKỹ sư riêng", 'cta_label' => 'Liên hệ', 'cta_url' => '/lien-he'],
            ]]],
            ['type' => 'cta', 'props' => ['heading' => 'Cần tư vấn giải pháp?', 'text' => 'Chúng tôi phản hồi trong 24 giờ.', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he', 'variant' => 'default', 'side' => 'stats', 'side_items' => [
                ['kind' => 'number', 'value' => 120, 'suffix' => '+', 'label' => 'Khách hàng'],
                ['kind' => 'number', 'value' => 24, 'suffix' => 'h', 'label' => 'Báo giá'],
            ]]],
            ['type' => 'cta', 'props' => ['heading' => 'Gửi bản vẽ ngay hôm nay', 'text' => 'Nhận báo giá miễn phí.', 'button_label' => 'Gửi bản vẽ', 'button_url' => '/lien-he', 'variant' => 'split']],
            ['type' => 'cta', 'props' => ['heading' => 'Nói chuyện với kỹ sư', 'text' => 'Gọi hoặc nhắn Zalo để được hỗ trợ.', 'button_label' => 'Gọi ngay', 'button_url' => 'tel:0900000000', 'variant' => 'card', 'button2_label' => 'Nhắn Zalo', 'button2_url' => 'https://zalo.me/0900000000', 'button2_tone' => 'zalo']],
        ];

        return [
            self::ABOUT_PATH => ['Giới thiệu đo tốc độ', $about],
            self::CONTENT_PATH => ['Nội dung đo tốc độ', $content],
            self::COMMERCE_PATH => ['Thương mại đo tốc độ', $commerce],
        ];
    }

    /**
     * The blocks that only a slot may hold, put on the `/ban-tin` category (its list and its posts),
     * slot => blocks. Kept off the site default so the other measured pages stay as they are.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function slotBlocks(): array
    {
        return [
            'after_list' => [
                ['type' => 'category_list', 'props' => ['heading' => 'Chuyên mục', 'source' => 'categories', 'counts' => 'show']],
                ['type' => 'consult', 'props' => ['eyebrow' => 'Cần hỗ trợ?', 'heading' => 'Tư vấn miễn phí', 'text' => 'Gọi cho kỹ sư của chúng tôi.', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he']],
            ],
            'post_aside' => [
                ['type' => 'recent_posts', 'props' => ['heading' => 'Bài viết mới']],
                ['type' => 'file_card', 'props' => ['badge' => 'PDF', 'title' => 'Hồ sơ năng lực', 'text' => 'Tải về tài liệu giới thiệu.', 'url' => '/lien-he']],
            ],
        ];
    }

    private function seedNewsSlots(string $locale): void
    {
        $id = TaxonomyTranslation::query()->where('locale', $locale)->where('slug', ltrim(self::NEWS_PATH, '/'))->value('taxonomy_id');
        if (! is_numeric($id)) {
            return;
        }
        $layout = [];
        foreach (self::slotBlocks() as $slot => $blocks) {
            $layout[$slot] = [$locale => ['blocks' => $blocks]];
        }
        Taxonomy::query()->whereKey((int) $id)->update(['meta->'.LayoutSlots::META_KEY => $layout]);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function seedBlockPage(string $locale, string $path, string $title, array $blocks): void
    {
        $route = SiteRoute::query()->where('locale', $locale)->where('path', $path)->first();
        $entry = $route?->entry_id !== null ? Entry::query()->find($route->entry_id) : null;
        if ($entry !== null) {
            $entry->translationFor($locale)?->update(['body' => ['blocks' => $blocks]]);

            return;
        }

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create([
            'locale' => $locale, 'title' => $title, 'slug' => trim($path, '/'), 'status' => 'published',
            'seo_description' => 'Trang mẫu dùng để đo tốc độ tải và khả năng truy cập.', 'body' => ['blocks' => $blocks],
        ]);
        SiteRoute::query()->create(['locale' => $locale, 'path' => $path, 'entry_id' => $entry->id]);
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
    private function seedServices(string $locale): Taxonomy
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

        return $services;
    }

    /**
     * Add "Dịch vụ" with two service posts under it to the menu the main area shows (the provisioned
     * one; created and bound only when the area has none). Idempotent: each item is anchored on its
     * target, and an item staff moved to the trash stays there.
     */
    private function seedMenu(string $locale, Taxonomy $services): void
    {
        $menuId = MenuAssignment::query()->where('region_key', 'primary')->where('locale', $locale)->value('menu_id');
        $menu = is_numeric($menuId) ? Menu::query()->find((int) $menuId) : null;
        if ($menu === null) {
            // No menu of its own (none, or the area follows another one): bind main-<locale> to it.
            $menu = Menu::query()->firstOrCreate(['handle' => 'main-'.$locale], ['name' => 'Menu chính', 'locales' => null]);
            MenuAssignment::query()->updateOrCreate(['region_key' => 'primary', 'locale' => $locale], ['menu_id' => $menu->id, 'same_as_region' => null]);
        }

        $parent = MenuItem::withTrashed()->firstOrCreate(
            ['menu_id' => $menu->id, 'link_type' => 'taxonomy', 'routable_type' => 'taxonomy', 'routable_id' => $services->id],
            ['position' => 2],
        );
        $parent->translations()->firstOrCreate(['locale' => $locale], ['label' => 'Dịch vụ']);

        foreach (['thiet-ke-do-ga' => 'Thiết kế đồ gá', 'gia-cong-cnc' => 'Gia công CNC'] as $slug => $label) {
            $entryId = EntryTranslation::query()->where('locale', $locale)->where('slug', $slug)->value('entry_id');
            if (! is_numeric($entryId)) {
                continue;
            }
            $child = MenuItem::withTrashed()->firstOrCreate(
                ['menu_id' => $menu->id, 'link_type' => 'entry', 'routable_type' => 'entry', 'routable_id' => (int) $entryId],
                ['parent_id' => $parent->id, 'position' => $slug === 'gia-cong-cnc' ? 1 : 0],
            );
            $child->translations()->firstOrCreate(['locale' => $locale], ['label' => $label]);
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
