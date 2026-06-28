<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;

/**
 * Bootstrap Cơ Khí Chính Xác An Vinh's content — kept OUT of the generic ProvisionSite so
 * other tenants stay clean. Seeds: san-pham (6 products, 3 sub-cats), du-an (6 projects),
 * dich-vu (4 services), tin-tuc (6 news posts under the existing ProvisionSite category),
 * updates the home and contact page bodies, creates /gioi-thieu and /doi-tac pages, updates
 * the main nav, and sets the brand/NAP on SiteProfile.
 *
 * Idempotent — anchored on localized slug (taxonomies/entries) or route path (pages).
 * Runs INSIDE the tenant context (caller enters it) so site_id is stamped and RLS-scoped.
 * card image_url = raw R2 key (EntryGridComponent resolves at render); page section
 * image_url = pre-resolved URL via MediaUrlResolver (blade renders src directly).
 */
final class SeedAnVinhContent
{
    // R2 storage keys (from key-map.json — verbatim paths, never hard-coded base URLs)
    private const KEY_ABOUT = 'an-vinh/content/about-doi-ngu-a7b78a0a6ff7.jpg';

    private const KEY_HERO = 'an-vinh/hero/hero-home-7ca90eafe212.jpg';

    private const KEY_LOGO = 'an-vinh/brand/logo-e76d7cb1dfe4.png';

    // Storefront collection taxonomy slugs
    private const PRODUCTS_SLUG = 'san-pham';

    private const PROJECTS_SLUG = 'du-an';

    private const SERVICES_SLUG = 'dich-vu';

    private const NEWS_SLUG = 'tin-tuc';  // created by ProvisionSite; we reuse it

    // Product sub-categories (vocabulary = 'product_cat', children of san-pham)
    /** @var array<string, string> slug => display name */
    private const PRODUCT_CATS = [
        'do-ga' => 'Đồ gá',
        'khuon-mau' => 'Khuôn mẫu',
        'chi-tiet-may' => 'Chi tiết máy',
    ];

    /** @var list<array{title: string, slug: string, excerpt: string, specs: list<string>, image: string, sub_cat: string}> */
    private const PRODUCTS = [
        [
            'title' => 'Jig kiểm cụm Taplo cho xe VF7',
            'slug' => 'jig-kiem-tap-lo-vf7',
            'excerpt' => 'Đồ gá kiểm tra và lắp ráp cụm taplo cho dòng xe VinFast VF7, đảm bảo độ chính xác và ổn định trong quá trình sản xuất hàng loạt.',
            'specs' => ['Vật liệu: Thép SKD11, Nhôm A6061', 'Độ chính xác: ±0.05mm', 'Ứng dụng: Kiểm tra, lắp ráp ô tô'],
            'image' => 'an-vinh/san-pham/sp-jig-kiem-taplo-vf7-b2897cdd401e.jpg',
            'sub_cat' => 'do-ga',
        ],
        [
            'title' => 'Máy tự động nắn sửa sản phẩm',
            'slug' => 'may-tu-dong-nan-sua-san-pham',
            'excerpt' => 'Hệ thống tự động sử dụng robot để nắn sửa các chi tiết kim loại sau quá trình dập, tăng năng suất và giảm thiểu sai sót do con người.',
            'specs' => ['Robot sử dụng: Fanuc / Yaskawa', 'Chu kỳ: 15 giây/sản phẩm', 'Nguồn điện: 380V / 3 pha'],
            'image' => 'an-vinh/san-pham/sp-may-tu-dong-nan-sua-d2e8cabcfba6.jpg',
            'sub_cat' => 'do-ga',
        ],
        [
            'title' => 'Jig hàn Frame Comp',
            'slug' => 'jig-han-frame-comp',
            'excerpt' => 'Đồ gá chuyên dụng cho việc hàn khung xe, định vị chính xác các chi tiết, đảm bảo mối hàn chắc chắn và đúng vị trí kỹ thuật.',
            'specs' => ['Loại kẹp: Kẹp khí nén', 'Vật liệu chính: Thép SS400', 'Số điểm định vị: 24'],
            'image' => 'an-vinh/san-pham/sp-jig-han-frame-comp-bcd2a076a0ed.jpg',
            'sub_cat' => 'do-ga',
        ],
        [
            'title' => 'Khuôn dập bình xăng',
            'slug' => 'khuon-dap-binh-xang',
            'excerpt' => 'Khuôn dập liên hoàn cho sản phẩm bình xăng xe máy, sử dụng vật liệu thép chất lượng cao cho tuổi thọ và độ bền vượt trội.',
            'specs' => ['Loại khuôn: Khuôn dập liên hoàn', 'Vật liệu: DC53, SKD11', 'Lực dập: 200 tấn'],
            'image' => 'an-vinh/san-pham/sp-khuon-dap-binh-xang-e992548adeed.jpg',
            'sub_cat' => 'khuon-mau',
        ],
        [
            'title' => 'Khuôn đúc áp lực cao',
            'slug' => 'khuon-duc-ap-luc-cao',
            'excerpt' => 'Khuôn đúc nhôm áp lực cao, thiết kế tối ưu dòng chảy kim loại, đảm bảo sản phẩm có độ đặc và bề mặt hoàn hảo.',
            'specs' => ['Vật liệu: SKD61, DAC', 'Số lần đúc: > 100,000 shots', 'Máy đúc: 500-800 tấn'],
            'image' => 'an-vinh/san-pham/sp-khuon-duc-ap-luc-cao-ed70e2dd620b.jpg',
            'sub_cat' => 'khuon-mau',
        ],
        [
            'title' => 'Trục máy CNC',
            'slug' => 'truc-may-cnc',
            'excerpt' => 'Gia công các loại trục, vít me cho máy CNC với độ chính xác cao, vật liệu được nhiệt luyện để tăng độ cứng và chống mài mòn.',
            'specs' => ['Vật liệu: Thép S45C, SCM440', 'Độ cứng: 58-62 HRC', 'Dung sai: ±0.005mm'],
            'image' => 'an-vinh/san-pham/sp-truc-may-cnc-3e982930d535.jpg',
            'sub_cat' => 'chi-tiet-may',
        ],
    ];

    /** @var list<array{title: string, slug: string, excerpt: string, image: string}> */
    private const PROJECTS = [
        [
            'title' => 'Dây chuyền lắp ráp tự động',
            'slug' => 'day-chuyen-lap-rap-tu-dong',
            'excerpt' => 'Thiết kế và thi công hệ thống dây chuyền lắp ráp linh kiện điện tử, tăng năng suất 200%.',
            'image' => 'an-vinh/du-an/da-day-chuyen-lap-rap-tu-dong-0bec2c6d066c.jpg',
        ],
        [
            'title' => 'Hệ thống Jig hàn robot',
            'slug' => 'he-thong-jig-han-robot',
            'excerpt' => 'Phát triển hệ thống đồ gá hàn cho robot, ứng dụng trong ngành công nghiệp ô tô.',
            'image' => 'an-vinh/du-an/da-he-thong-jig-han-robot-eedb8ed77afb.jpg',
        ],
        [
            'title' => 'Khuôn mẫu cho sản phẩm gia dụng',
            'slug' => 'khuon-mau-san-pham-gia-dung',
            'excerpt' => 'Chế tạo loạt khuôn ép nhựa cho các sản phẩm gia dụng phức tạp, đáp ứng tiêu chuẩn xuất khẩu.',
            'image' => 'an-vinh/du-an/da-khuon-mau-san-pham-gia-dung-c9da111cbfb7.jpg',
        ],
        [
            'title' => 'Đồ gá kiểm tra thân vỏ xe',
            'slug' => 'do-ga-kiem-tra-than-vo-xe',
            'excerpt' => 'Sản xuất đồ gá kiểm tra kích thước 3D cho thân vỏ ô tô, đảm bảo độ chính xác tuyệt đối.',
            'image' => 'an-vinh/du-an/da-do-ga-kiem-tra-than-vo-xe-420ed0bd4bb3.jpg',
        ],
        [
            'title' => 'Gia công chi tiết hàng không',
            'slug' => 'gia-cong-chi-tiet-hang-khong',
            'excerpt' => 'Tham gia dự án gia công các chi tiết cơ khí phức tạp cho ngành hàng không vũ trụ.',
            'image' => 'an-vinh/du-an/da-gia-cong-chi-tiet-hang-khong-ae396a44dff9.jpg',
        ],
        [
            'title' => 'Cải tiến dây chuyền sản xuất',
            'slug' => 'cai-tien-day-chuyen-san-xuat',
            'excerpt' => 'Tư vấn và thực hiện cải tiến, tối ưu hóa dây chuyền sản xuất cho các đối tác lớn trong và ngoài nước.',
            'image' => 'an-vinh/du-an/da-cai-tien-day-chuyen-san-xuat-0df18b440a22.jpg',
        ],
    ];

    /** @var list<array{title: string, slug: string, excerpt: string, image: string}> */
    private const SERVICES = [
        [
            'title' => 'Thiết kế Jig & Đồ gá',
            'slug' => 'thiet-ke-jig-do-ga',
            'excerpt' => 'Cung cấp các thiết kế đồ gá tối ưu, đảm bảo độ chính xác cao và công năng vượt trội cho dây chuyền.',
            'image' => 'cog',
        ],
        [
            'title' => 'Gia công Phay & Tiện CNC',
            'slug' => 'gia-cong-phay-tien-cnc',
            'excerpt' => 'Gia công phay, tiện CNC trên các vật liệu đa dạng, đáp ứng nhanh nhu cầu khách hàng, tăng năng suất sản xuất.',
            'image' => 'box',
        ],
        [
            'title' => 'Hàn & Lắp ráp cơ khí',
            'slug' => 'han-lap-rap-co-khi',
            'excerpt' => 'Thực hiện đa dạng các phương pháp hàn và lắp ráp cụm chi tiết với độ chính xác và thẩm mỹ cao.',
            'image' => 'users',
        ],
        [
            'title' => 'Sản xuất chi tiết máy & OEM',
            'slug' => 'san-xuat-chi-tiet-may-oem',
            'excerpt' => 'Sản xuất các chi tiết máy, linh kiện thay thế theo yêu cầu bản vẽ kỹ thuật, đảm bảo chất lượng và tiến độ.',
            'image' => 'shield',
        ],
    ];

    /** @var list<array{title: string, slug: string, excerpt: string, image: string}> */
    private const NEWS = [
        [
            'title' => 'An Vinh tổ chức team building 2024',
            'slug' => 'an-vinh-to-chuc-team-building-2024',
            'excerpt' => 'Hoạt động team building thường niên nhằm gắn kết tinh thần đồng đội và tái tạo năng lượng cho toàn thể cán bộ nhân viên.',
            'image' => 'an-vinh/tin-tuc/tt-team-building-2024-d0f883162905.jpg',
        ],
        [
            'title' => 'Xu hướng tự động hóa trong ngành cơ khí chế tạo',
            'slug' => 'xu-huong-tu-dong-hoa-nganh-co-khi',
            'excerpt' => 'Tự động hóa và robot đang thay đổi bộ mặt của ngành cơ khí, mang lại hiệu suất và độ chính xác chưa từng có.',
            'image' => 'an-vinh/tin-tuc/tt-xu-huong-tu-dong-hoa-e435190fc7d2.jpg',
        ],
        [
            'title' => 'Khánh thành nhà máy số 3 tại Thạch Thất',
            'slug' => 'khanh-thanh-nha-may-so-3',
            'excerpt' => 'Việc đưa vào hoạt động nhà máy số 3 giúp An Vinh nâng cao năng lực sản xuất, đáp ứng các đơn hàng lớn.',
            'image' => 'an-vinh/tin-tuc/tt-khanh-thanh-nha-may-3-222228314bc5.jpg',
        ],
        [
            'title' => 'Ứng dụng vật liệu mới trong chế tạo khuôn mẫu',
            'slug' => 'ung-dung-vat-lieu-moi-khuon-mau',
            'excerpt' => 'Các loại thép công cụ thế hệ mới giúp tăng tuổi thọ khuôn và cải thiện chất lượng bề mặt sản phẩm.',
            'image' => 'an-vinh/tin-tuc/tt-vat-lieu-moi-khuon-mau-5bd3cd43bc3c.jpg',
        ],
        [
            'title' => 'Đào tạo an toàn lao động định kỳ',
            'slug' => 'dao-tao-an-toan-lao-dong',
            'excerpt' => 'Tổ chức khóa đào tạo về an toàn lao động và phòng chống cháy nổ cho toàn bộ nhân viên nhà máy.',
            'image' => 'an-vinh/tin-tuc/tt-dao-tao-an-toan-lao-dong-2932ad3f6de8.jpg',
        ],
        [
            'title' => 'Công nghệ in 3D kim loại và tương lai của ngành',
            'slug' => 'cong-nghe-in-3d-kim-loai',
            'excerpt' => 'In 3D kim loại mở ra những khả năng mới trong việc tạo mẫu nhanh và sản xuất các chi tiết phức tạp.',
            'image' => 'an-vinh/tin-tuc/tt-cong-nghe-in-3d-kim-loai-a707a16fb5fb.jpg',
        ],
    ];

    public function __construct(
        private readonly RouteSync $routes,
        private readonly MediaUrlResolver $media,
    ) {}

    public function handle(): void
    {
        $defaultLocale = SiteLocale::query()->where('is_default', true)->value('locale');
        $locale = is_string($defaultLocale) ? $defaultLocale : 'vi';

        $this->seedProducts($locale);
        $this->seedProjects($locale);
        $this->seedServices($locale);
        $this->seedNews($locale);
        $this->updateHomePage($locale);
        $this->updateContactPage($locale);
        $this->createGioiThieuPage($locale);
        $this->createDoiTacPage($locale);
        $this->updateBrandNAP($locale);
    }

    private function seedProducts(string $locale): void
    {
        $sanPham = $this->firstOrCreateTaxonomy($locale, self::PRODUCTS_SLUG, 'Sản phẩm', 'post_cat');

        ContentSection::query()->firstOrCreate(
            ['key' => 'products'],
            ['label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $sanPham->id, 'permission_prefix' => 'entries', 'sort_order' => 3],
        );

        $this->syncTaxonomyRoute($sanPham, $locale);

        $subCats = [];
        foreach (self::PRODUCT_CATS as $slug => $name) {
            $subCats[$slug] = $this->firstOrCreateTaxonomy($locale, $slug, $name, 'product_cat', $sanPham->id);
        }

        foreach (self::PRODUCTS as $product) {
            $blocks = [
                ['type' => 'richtext', 'props' => ['heading' => $product['title'], 'paragraphs' => [$product['excerpt']]]],
                ['type' => 'richtext', 'props' => ['heading' => 'Thông số kỹ thuật', 'paragraphs' => $product['specs']]],
            ];
            $this->firstOrCreatePost(
                $locale,
                $product['title'],
                $product['slug'],
                $product['excerpt'],
                $product['image'],
                [$sanPham->id, $subCats[$product['sub_cat']]->id],
                $blocks,
            );
        }

        $this->addMenuTaxonomyItem($locale, $sanPham, 'Sản phẩm', 4);
    }

    private function seedProjects(string $locale): void
    {
        $duAn = $this->firstOrCreateTaxonomy($locale, self::PROJECTS_SLUG, 'Dự án', 'post_cat');

        ContentSection::query()->firstOrCreate(
            ['key' => 'projects'],
            ['label' => 'Dự án', 'entry_type' => 'post', 'taxonomy_id' => $duAn->id, 'permission_prefix' => 'entries', 'sort_order' => 5],
        );

        $this->syncTaxonomyRoute($duAn, $locale);

        foreach (self::PROJECTS as $project) {
            $this->firstOrCreatePost(
                $locale,
                $project['title'],
                $project['slug'],
                $project['excerpt'],
                $project['image'],
                [$duAn->id],
            );
        }

        $this->addMenuTaxonomyItem($locale, $duAn, 'Dự án', 5);
    }

    private function seedServices(string $locale): void
    {
        $dichVu = $this->firstOrCreateTaxonomy($locale, self::SERVICES_SLUG, 'Dịch vụ', 'post_cat');

        ContentSection::query()->firstOrCreate(
            ['key' => 'services'],
            ['label' => 'Dịch vụ', 'entry_type' => 'post', 'taxonomy_id' => $dichVu->id, 'permission_prefix' => 'entries', 'sort_order' => 2],
        );

        $this->syncTaxonomyRoute($dichVu, $locale);

        foreach (self::SERVICES as $service) {
            $this->firstOrCreatePost(
                $locale,
                $service['title'],
                $service['slug'],
                $service['excerpt'],
                $service['image'],
                [$dichVu->id],
            );
        }

        $this->addMenuTaxonomyItem($locale, $dichVu, 'Dịch vụ', 3);
    }

    /**
     * Seeds 6 news posts under the EXISTING 'tin-tuc' taxonomy seeded by ProvisionSite.
     * Does NOT create a new taxonomy — ProvisionSite owns that (its ContentSection key='posts').
     */
    private function seedNews(string $locale): void
    {
        $existing = TaxonomyTranslation::query()->where('locale', $locale)->where('slug', self::NEWS_SLUG)->first();
        if ($existing === null) {
            return; // ProvisionSite must have run first; no-op is safer than creating a duplicate
        }

        /** @var Taxonomy $tinTuc */
        $tinTuc = Taxonomy::query()->findOrFail($existing->taxonomy_id);

        foreach (self::NEWS as $news) {
            $this->firstOrCreatePost(
                $locale,
                $news['title'],
                $news['slug'],
                $news['excerpt'],
                $news['image'],
                [$tinTuc->id],
            );
        }

        // Reposition the tin-tuc nav item to the end of the An Vinh menu (after the 6 An Vinh-specific
        // sections at positions 2–7). ProvisionSite seeds it at position 1 (right after Trang chủ),
        // but the desired order is: Trang chủ · Giới thiệu · Dịch vụ · Sản phẩm · Dự án · Đối tác ·
        // Liên hệ · Tin tức. Idempotent: a re-run sets position to 8 again.
        $menu = Menu::query()->where('handle', 'main-'.$locale)->first();
        if ($menu !== null) {
            MenuItem::query()
                ->where('menu_id', $menu->id)
                ->where('link_type', 'taxonomy')
                ->where('routable_type', 'taxonomy')
                ->where('routable_id', $tinTuc->id)
                ->update(['position' => 8]);
        }
    }

    /**
     * Replaces the generic ProvisionSite home body with the An Vinh assembly.
     * hero schema has no image_url prop → bg image is a gap (noted in caller report).
     * About and page section images are PRE-RESOLVED via MediaUrlResolver so the blade
     * can render src directly (no resolver at render time for page sections).
     */
    private function updateHomePage(string $locale): void
    {
        $aboutUrl = $this->media->url(self::KEY_ABOUT, 960);
        // Hero background: 1600px — snapped by MediaUrlResolver to the nearest
        // whitelisted width. Pre-resolved so the blade renders src directly (no
        // resolver at render-time for page-section props).
        $heroUrl = $this->media->url(self::KEY_HERO, 1600);

        $blocks = [
            ['type' => 'hero', 'props' => [
                'heading' => 'Cơ Khí Chính Xác An Vinh',
                'subheading' => 'Dẫn đầu về giải pháp cơ khí chính xác, chuyên thiết kế, gia công đồ gá, jig, và khuôn mẫu chất lượng cao tại Việt Nam.',
                'cta_label' => 'Liên hệ ngay',
                'cta_url' => '/lien-he',
                'image_url' => $heroUrl,
            ]],
            ['type' => 'about', 'props' => [
                'heading' => 'Chế Tác Tinh Hoa - Nâng Tầm Chính Xác',
                'paragraphs' => [
                    'Là đơn vị hàng đầu trong lĩnh vực cơ khí chính xác tại Việt Nam, An Vinh tự hào mang đến các giải pháp toàn diện về thiết kế và gia công đồ gá, jig, khuôn mẫu và các chi tiết máy công nghệ cao.',
                    'Với đội ngũ kỹ sư dày dạn kinh nghiệm, hệ thống máy móc CNC hiện đại nhập khẩu từ Nhật Bản và quy trình quản lý chất lượng nghiêm ngặt theo tiêu chuẩn ISO 9001:2015, chúng tôi cam kết mỗi sản phẩm xuất xưởng đều đạt độ chính xác tuyệt đối, đáp ứng những yêu cầu kỹ thuật khắt khe nhất của khách hàng trong và ngoài nước.',
                ],
                'image_url' => $aboutUrl,
                'cta_label' => 'Tìm hiểu thêm',
                'cta_url' => '/gioi-thieu',
            ]],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Dịch vụ của chúng tôi', 'taxonomy_slug' => 'dich-vu', 'limit' => 4]],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Sản phẩm tiêu biểu', 'taxonomy_slug' => 'san-pham', 'limit' => 6]],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Dự án nổi bật', 'taxonomy_slug' => 'du-an', 'limit' => 6]],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Tin tức mới nhất', 'taxonomy_slug' => 'tin-tuc', 'limit' => 3]],
            // Partner logos are placeholders in the demo, so a logo-grid would render empty (the
            // partners section skips logo-less items). Show the real names as a text strip instead.
            ['type' => 'richtext', 'props' => [
                'heading' => 'Đối tác tiêu biểu',
                'paragraphs' => ['VinFast · Festo · Hutchinson · Weldcom · SMC · DGP · Yamaguchi · Palfinger'],
            ]],
            ['type' => 'cta', 'props' => [
                'heading' => 'Liên hệ với chúng tôi ngay hôm nay',
                'button_label' => 'Liên hệ ngay',
                'button_url' => '/lien-he',
            ]],
            ['type' => 'contact', 'props' => [
                'heading' => 'Liên hệ Cơ Khí Chính Xác An Vinh',
                'address' => 'Thôn Xuân Sơn, Xã Trung Giã, TP Hà Nội',
                'phone' => '0915 66 83 83',
                'email' => 'quybv@ckanvinh.vn',
            ]],
        ];

        $this->upsertPage($locale, 'Cơ Khí Chính Xác An Vinh', 'home', '/', 'Cơ Khí Chính Xác An Vinh — thiết kế, gia công đồ gá, jig, khuôn mẫu và chi tiết máy CNC độ chính xác cao tại Việt Nam, đạt chuẩn ISO 9001:2015.', $blocks);
    }

    /**
     * Replaces the generic contact body with the real An Vinh NAP + factory list (§6).
     */
    private function updateContactPage(string $locale): void
    {
        $blocks = [
            ['type' => 'contact', 'props' => [
                'heading' => 'Liên hệ Cơ Khí Chính Xác An Vinh',
                'address' => 'Thôn Xuân Sơn, Xã Trung Giã, TP Hà Nội',
                'phone' => '0915 66 83 83',
                'email' => 'quybv@ckanvinh.vn',
                'map_embed' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d12503.468002335805!2d105.85833024594946!3d21.29902948043917!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x31351dc82e015dad%3A0xc3a6d44da6b27bb9!2zQ8O0bmcgdHkgY8ahIGtow60gQW4gVmluaA!5e0!3m2!1svi!2s!4v1762317872785!5m2!1svi!2s',
            ]],
            ['type' => 'richtext', 'props' => [
                'heading' => 'Hệ Thống Nhà Máy',
                'paragraphs' => [
                    'Nhà máy 1 — Lắp ráp & sản xuất hàn, dập — 2.000m² — Đường 418, thôn Xuân Sơn, xã Trung Giã, TP Hà Nội',
                    'Nhà máy 2 — Lắp ráp — 500m² — Đường QL3, thôn Hương Ninh, xã Trung Giã, TP Hà Nội',
                    'Nhà máy 3 — Gia công — 1.800m² — Đội 6, thôn Ngũ Sơn, xã Thạch Thất, TP Hà Nội',
                ],
            ]],
        ];

        $entryId = $this->upsertPage($locale, 'Liên hệ', 'lien-he', '/lien-he', 'Liên hệ Cơ Khí Chính Xác An Vinh — hotline 0915 66 83 83, email quybv@ckanvinh.vn, hệ thống 3 nhà máy tại Hà Nội.', $blocks);
        $this->addMenuEntryItem($locale, $entryId, 'Liên hệ', 7);
    }

    /**
     * Creates /gioi-thieu with the full company intro (§5.2), vision/mission (§5.4),
     * 6 core values (§5.5), and ISO 9001:2015 certification (§5.6). Adds nav item.
     */
    private function createGioiThieuPage(string $locale): void
    {
        $aboutUrl = $this->media->url(self::KEY_ABOUT, 960);

        $blocks = [
            ['type' => 'about', 'props' => [
                'heading' => 'Về An Vinh - Chế Tác Tinh Hoa, Kiến Tạo Tương Lai',
                'paragraphs' => [
                    'Là đơn vị hàng đầu trong lĩnh vực cơ khí chính xác tại Việt Nam, An Vinh tự hào mang đến các giải pháp toàn diện về thiết kế và gia công đồ gá, jig, khuôn mẫu và các chi tiết máy công nghệ cao.',
                    'Với đội ngũ kỹ sư dày dạn kinh nghiệm, hệ thống máy móc CNC hiện đại nhập khẩu từ Nhật Bản và quy trình quản lý chất lượng nghiêm ngặt theo tiêu chuẩn ISO 9001:2015, chúng tôi cam kết mỗi sản phẩm xuất xưởng đều đạt độ chính xác tuyệt đối, đáp ứng những yêu cầu kỹ thuật khắt khe nhất của khách hàng trong và ngoài nước.',
                ],
                'image_url' => $aboutUrl,
                'cta_label' => 'Liên hệ hợp tác',
                'cta_url' => '/lien-he',
            ]],
            ['type' => 'richtext', 'props' => [
                'heading' => 'Định Hướng Phát Triển',
                'paragraphs' => ['An Vinh không ngừng vươn tới những tiêu chuẩn cao hơn, kiến tạo giá trị bền vững cho khách hàng và xã hội.'],
            ]],
            ['type' => 'richtext', 'props' => [
                'heading' => 'Tầm Nhìn',
                'paragraphs' => ['Trở thành công ty gia công cơ khí chính xác, thiết kế đồ gá, khuôn mẫu hàng đầu Việt Nam với trang thiết bị hiện đại, tiên tiến. Cung cấp sản phẩm, dịch vụ chất lượng tốt nhất, giá cả cạnh tranh, thoả mãn mọi nhu cầu của khách hàng.'],
            ]],
            ['type' => 'richtext', 'props' => [
                'heading' => 'Sứ Mệnh',
                'paragraphs' => ['Thiết kế, thi công và sản xuất những sản phẩm có chất lượng tốt, an toàn, đảm bảo tiến độ và hiệu quả kinh tế. Phát huy cao nhất năng lực đội ngũ và nâng cao chất lượng đời sống vật chất, tinh thần cho toàn thể cán bộ nhân viên.'],
            ]],
            ['type' => 'richtext', 'props' => [
                'heading' => 'Giá Trị Cốt Lõi',
                'paragraphs' => [
                    'Uy Tín — Luôn nỗ lực hết mình để đảm bảo chất lượng hoàn hảo và cam kết đúng tiến độ.',
                    'Sáng Tạo — Không ngừng sáng tạo nhằm tạo sự khác biệt và mang đậm bản sắc riêng.',
                    'Trách Nhiệm — Luôn làm việc tận tụy và có trách nhiệm với những gì đã cam kết.',
                    'Hiệu Quả — Cải tiến liên tục kỹ năng để phục vụ khách hàng với hiệu suất cao nhất.',
                    'Khách Hàng Là Trọng Tâm — Lấy sự hài lòng của khách hàng làm thước đo cho thành công.',
                    'Chia Sẻ Thành Công — Đồng hành cùng sự phát triển của khách hàng và cộng đồng.',
                ],
            ]],
            ['type' => 'richtext', 'props' => [
                'heading' => 'Cam Kết Chất Lượng Theo Tiêu Chuẩn Quốc Tế',
                'paragraphs' => ['An Vinh tự hào được đánh giá và chứng nhận phù hợp với các yêu cầu của tiêu chuẩn quốc tế ISO 9001:2015 cho Hệ thống Quản lý Chất lượng. Đây là minh chứng cho cam kết không ngừng của chúng tôi trong việc cung cấp những sản phẩm và dịch vụ đạt chuẩn mực cao nhất, từ khâu thiết kế, sản xuất đến kiểm định, mang lại sự an tâm tuyệt đối cho khách hàng.'],
            ]],
        ];

        $entryId = $this->upsertPage($locale, 'Giới thiệu', 'gioi-thieu', '/gioi-thieu', 'Giới thiệu Cơ Khí Chính Xác An Vinh — đơn vị hàng đầu thiết kế, gia công cơ khí chính xác, đồ gá, khuôn mẫu, đạt chuẩn ISO 9001:2015.', $blocks);
        $this->addMenuEntryItem($locale, $entryId, 'Giới thiệu', 2);
    }

    /**
     * Creates /doi-tac listing the 10 real partner names (§7) and 2 testimonials (§8.B).
     * No placeholder logo URLs — text only (logos not available in demo).
     */
    private function createDoiTacPage(string $locale): void
    {
        $blocks = [
            ['type' => 'richtext', 'props' => [
                'heading' => 'Đối Tác Của Chúng Tôi',
                'paragraphs' => [
                    'VinFast',
                    'Festo',
                    'Hutchinson',
                    'Weldcom',
                    'SMC',
                    'DGP',
                    'Yamaguchi',
                    'Mitsuboshi',
                    'Palfinger',
                    'Vinmanutech',
                ],
            ]],
            ['type' => 'richtext', 'props' => [
                'heading' => 'Khách Hàng Nói Về Chúng Tôi',
                'paragraphs' => [
                    '"An Vinh đã chứng tỏ là một đối tác đáng tin cậy. Các sản phẩm đồ gá của họ có độ chính xác cao, giúp dây chuyền sản xuất của chúng tôi hoạt động ổn định và hiệu quả hơn." — Trưởng phòng Kỹ thuật, VinFast',
                    '"Chúng tôi đánh giá cao sự chuyên nghiệp và khả năng đáp ứng nhanh chóng các yêu cầu kỹ thuật phức tạp từ An Vinh. Chất lượng khuôn mẫu vượt ngoài mong đợi." — Giám đốc Sản xuất, Hutchinson',
                ],
            ]],
        ];

        $entryId = $this->upsertPage($locale, 'Đối tác', 'doi-tac', '/doi-tac', 'Đối tác của Cơ Khí Chính Xác An Vinh — VinFast, Festo, Hutchinson, SMC và nhiều thương hiệu công nghiệp hàng đầu.', $blocks);
        $this->addMenuEntryItem($locale, $entryId, 'Đối tác', 6);
    }

    /**
     * Stamps the real An Vinh brand name, legal name, and NAP on the existing SiteProfile
     * (created blank by ProvisionSite). Also upserts the localized brand_name translation.
     */
    private function updateBrandNAP(string $locale): void
    {
        $profile = SiteProfile::query()->firstOrCreate([], []);

        $profile->fill([
            'brand_name' => 'Cơ Khí Chính Xác An Vinh',
            'legal_name' => 'CÔNG TY TNHH THƯƠNG MẠI VÀ DỊCH VỤ THIẾT KẾ GIA CÔNG CƠ KHÍ AN VINH',
            'tax_code' => '0108649706',
            'business_license_no' => '0108649706',
            'phone' => '0915 66 83 83',
            'hotline' => '0915 66 83 83',
            'email' => 'quybv@ckanvinh.vn',
            'address_line' => 'Thôn Xuân Sơn',
            'ward' => 'Xã Trung Giã',
            'province' => 'TP Hà Nội',
        ])->save();

        $profile->translations()->updateOrCreate(
            ['locale' => $locale],
            [
                'brand_name' => 'Cơ Khí Chính Xác An Vinh',
                'short_description' => 'Dẫn đầu về giải pháp cơ khí chính xác, chuyên thiết kế, gia công đồ gá, jig, và khuôn mẫu chất lượng cao tại Việt Nam.',
            ],
        );

        // Storefront brand setting: name, logo, SEO defaults, and NAP.
        // - name: displayed in the header logo aria-label and footer copyright
        // - legal_name: full legal name shown in the footer company block
        // - description: meta description fallback for pages without a per-page description
        // - og_image: fallback og:image; per-entry images override this in StorefrontController
        // - address/phone/email/license: NAP for the footer; conditional — tenants without
        //   these keys get a minimal footer so no An Vinh content leaks to other tenants
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'general', 'key' => 'brand'],
            ['value' => [
                'name' => 'Cơ Khí Chính Xác An Vinh',
                'legal_name' => 'Công ty TNHH TM & DV Thiết Kế Gia Công Cơ Khí An Vinh',
                'logo' => $this->media->url(self::KEY_LOGO),
                'description' => 'Dẫn đầu về giải pháp cơ khí chính xác, chuyên thiết kế, gia công đồ gá, jig, và khuôn mẫu chất lượng cao tại Việt Nam.',
                'og_image' => $this->media->url(self::KEY_ABOUT, 1280),
                // Combined address string — used by the footer display and as fallback streetAddress
                // for tenants that haven't been re-seeded with the split NAP fields below.
                'address' => 'Thôn Xuân Sơn, Xã Trung Giã, TP Hà Nội',
                // Split NAP fields — used by the Organization JSON-LD PostalAddress in head.blade.php.
                'address_line' => 'Thôn Xuân Sơn',
                'ward' => 'Xã Trung Giã',
                'province' => 'TP Hà Nội',
                'phone' => '0915 66 83 83',
                'email' => 'quybv@ckanvinh.vn',
                'license' => '0108649706',
                'tax_id' => '0108649706',
            ]],
        );
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
     * Create a published post (product / project / service / news) under the given categories.
     * The first category ID is the primary one (drives routing). If $blocks is empty, falls back
     * to a single richtext block from $excerpt. Card image: raw R2 key stored in meta['image_url']
     * (EntryGridComponent resolves it at render time via MediaUrlResolver).
     *
     * @param  list<int>  $categoryIds  first = primary category (prefix for routing)
     * @param  list<array<string, mixed>>  $blocks  body blocks; falls back to a richtext from $excerpt
     */
    private function firstOrCreatePost(
        string $locale,
        string $title,
        string $slug,
        string $excerpt,
        string $image,
        array $categoryIds,
        array $blocks = [],
    ): void {
        $existing = EntryTranslation::query()->where('locale', $locale)->where('slug', $slug)->first();
        if ($existing !== null) {
            // Re-seed: the post's text is admin-owned, but the featured image is canonical here — so
            // refresh just it, so a re-curated photo (new content-hash key) reaches existing posts.
            $entry = Entry::query()->find($existing->entry_id);
            if ($entry !== null) {
                $meta = $entry->meta;
                if (data_get($meta, 'image_url') !== $image) {
                    $meta['image_url'] = $image;
                    $entry->update(['meta' => $meta]);
                }
            }

            return;
        }

        $entry = Entry::query()->create([
            'type' => 'post',
            'status' => 'published',
            'published_at' => now(),
            'meta' => ['image_url' => $image],
        ]);

        $body = $blocks !== []
            ? ['blocks' => $blocks]
            : ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => [$excerpt]]]]];

        $entry->translations()->create([
            'locale' => $locale,
            'title' => $title,
            'slug' => $slug,
            'status' => 'published',
            'excerpt' => $excerpt,
            'seo_description' => $excerpt,
            'body' => $body,
        ]);

        $entry->assignCategories($categoryIds);

        try {
            $this->routes->sync($entry, $locale, $slug);
        } catch (RouteConflictException) {
            // A page already owns this path; leave it.
        }
    }

    /**
     * Create the page + route at $path if missing, else refresh its body (title preserved on
     * update). Correct on a fresh site (dev — ProvisionSite already made /lien-he) AND on a site
     * provisioned before the contact page existed (prod av1 had only / + /tin-tuc, so a plain
     * "update" would have silently no-op'd and left the CTA dangling). Returns the entry ID so the
     * caller can wire a menu item idempotently.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function upsertPage(string $locale, string $title, string $slug, string $path, string $description, array $blocks): int
    {
        $route = SiteRoute::query()->where('path', $path)->first();

        if ($route !== null && $route->entry_id !== null) {
            $entry = Entry::query()->find($route->entry_id);
            $entry?->translationFor($locale)?->update(['body' => ['blocks' => $blocks], 'seo_description' => $description]);

            return $route->entry_id;
        }

        $entry = Entry::query()->create([
            'type' => 'page',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $entry->translations()->create([
            'locale' => $locale,
            'title' => $title,
            'slug' => $slug,
            'status' => 'published',
            'seo_description' => $description,
            'body' => ['blocks' => $blocks],
        ]);
        SiteRoute::query()->create([
            'locale' => $locale,
            'path' => $path,
            'entry_id' => $entry->id,
        ]);

        return $entry->id;
    }

    /** Sync a taxonomy's storefront list route (/{slug}). Best-effort. */
    private function syncTaxonomyRoute(Taxonomy $category, string $locale): void
    {
        $translation = $category->translationFor($locale);
        if ($translation !== null) {
            try {
                $this->routes->syncTaxonomy($category, $locale, $translation->slug);
            } catch (RouteConflictException) {
                // A page already owns this path; leave it.
            }
        }
    }

    /** Add a taxonomy-link menu item (idempotent; anchored on routable_id). */
    private function addMenuTaxonomyItem(string $locale, Taxonomy $category, string $label, int $position): void
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

    /** Add an entry-link menu item (idempotent; anchored on routable_id). */
    private function addMenuEntryItem(string $locale, int $entryId, string $label, int $position): void
    {
        $menu = Menu::query()->where('handle', 'main-'.$locale)->first();
        if ($menu === null) {
            return;
        }

        $item = MenuItem::query()->firstOrCreate(
            ['menu_id' => $menu->id, 'link_type' => 'entry', 'routable_type' => 'entry', 'routable_id' => $entryId],
            ['position' => $position],
        );
        $item->translations()->firstOrCreate(['locale' => $locale], ['label' => $label]);
    }
}
