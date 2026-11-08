<?php

/*
 * Admin preview renderer — a TOOL, not a test of behaviour.
 *
 * It renders a set of admin screens to storage/app/admin-preview/*.html as a freshly seeded site
 * owner, so a reviewer (or Claude, checking a theme/contrast/layout change) can screenshot the admin
 * WITHOUT logging into any real environment: no production, no 2FA, no bypass route, no real tenant
 * data. Authentication is Pest's actingAs() against an ephemeral test-database owner; every request
 * is a GET, so nothing is mutated.
 *
 * It lives in tests/Tools/, which is outside the four configured PHPUnit suites (Unit, Feature, Arch,
 * Concurrency in phpunit.xml), so `composer qa` and CI never run it. Run it explicitly — normally
 * through scripts/admin-preview/run.sh, or directly:
 *
 *   docker compose run --rm --no-deps app vendor/bin/pest tests/Tools/RenderAdminPreviewTest.php
 *
 * Output goes to storage/app/admin-preview/. Override the target with ADMIN_PREVIEW_DIR.
 *
 * Unlike the real suites, this file opts Vite back IN (TestCase disables it): the whole point is to
 * render with the real compiled CSS/JS, so build the front end first (pnpm run build).
 */

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Admin\Livewire\Platform\Audit\CleanupDialog;
use App\Modules\Admin\Livewire\SeoSettings;
use App\Modules\Admin\Livewire\Settings\Domains as DomainSettings;
use App\Modules\Admin\Livewire\WorkflowSettings;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Http\SafeHttp;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\AiCrawlerCheck;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

/** The admin screens worth a look after a chrome/theme change: dashboard, a list, a form, the kit. */
const ADMIN_PREVIEW_SCREENS = [
    'dashboard' => '/admin',
    'list' => '/admin/content',
    'form' => '/admin/settings/email',
    'gallery' => '/admin/_gallery',
    'menus' => '/admin/menus',
];

const ADMIN_PREVIEW_HOST = 'preview.platform.test';

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         platform_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();

    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Preview', siteName: 'Công ty Demo', hostname: ADMIN_PREVIEW_HOST,
        ownerEmail: 'owner@'.ADMIN_PREVIEW_HOST, ownerName: 'Nguyễn Văn Demo',
        ownerPassword: 'a-strong-password', locales: ['vi', 'en'], modules: ['cms', 'media'],
    ));
});

it('renders the admin screens to HTML for screenshotting', function (): void {
    // Belt for the "someone ran the whole suite" case: this tool needs the built front end, and the
    // CI/`composer qa` pest job never builds it. tests/Tools sits outside the configured suites so it
    // is not collected there anyway — but if it ever were, skip instead of failing on a missing Vite
    // manifest.
    if (! is_file(public_path('build/manifest.json'))) {
        $this->markTestSkipped('admin preview tool: run `pnpm run build` first (needs the compiled assets).');
    }

    $this->withVite(); // render with the real built assets, not the test stub

    $owner = User::query()->where('email', 'owner@'.ADMIN_PREVIEW_HOST)->firstOrFail();

    // A few entries in mixed states so the list screen shows real rows, not an empty state; the site
    // has two languages and one page is translated, so the rows show both language-chip states.
    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $pages = [];
        foreach ([['Trang chủ', 'trang-chu', 'published'], ['Giới thiệu', 'gioi-thieu', 'published'], ['Liên hệ', 'lien-he', 'draft'], ['Tuyển dụng', 'tuyen-dung', 'scheduled']] as [$title, $slug, $status]) {
            $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'author_id' => $owner->id]);
            $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            $pages[$slug] = $entry;
            if ($slug === 'gioi-thieu') {
                $entry->translations()->create(['locale' => 'en', 'title' => 'About us', 'slug' => 'about-us', 'status' => 'draft', 'body' => ['blocks' => []]]);
            }
        }

        // The Trình đơn screen: a main menu in the header (nested, a link with a badge, a page not
        // published yet) and a footer menu, so the tree, the areas and the preview show real rows.
        $menu = app(SaveMenu::class)->handle(null, 'Menu chính');
        $footer = app(SaveMenu::class)->handle(null, 'Menu chân trang');
        MenuAssignment::query()->updateOrCreate(['region_key' => 'primary', 'locale' => 'vi'], ['menu_id' => $menu->id, 'same_as_region' => null]);
        MenuAssignment::query()->updateOrCreate(['region_key' => 'footer', 'locale' => 'vi'], ['menu_id' => $footer->id, 'same_as_region' => null]);
        $add = function (Menu $in, ?MenuItem $parent, array $link, string $label = '', array $meta = []) use (&$position): MenuItem {
            $item = MenuItem::query()->create(['menu_id' => $in->id, 'parent_id' => $parent?->id, 'position' => $position++, 'meta' => $meta, ...$link]);
            if ($label !== '') {
                $item->translations()->create(['locale' => 'vi', 'label' => $label]);
            }

            return $item;
        };
        $position = 0;
        $entry = fn (string $slug): array => ['link_type' => 'entry', 'routable_type' => 'entry', 'routable_id' => $pages[$slug]->id];
        $add($menu, null, ['link_type' => 'dynamic', 'dynamic_key' => 'home']);
        $about = $add($menu, null, $entry('gioi-thieu'), 'Về chúng tôi');
        $add($menu, $about, $entry('tuyen-dung'));
        $add($menu, $about, $entry('lien-he'), 'Liên hệ');
        $add($menu, null, ['link_type' => 'url', 'url' => '/bang-gia'], 'Bảng giá', ['badge' => ['kind' => 'new']]);
        $add($footer, null, $entry('lien-he'), 'Liên hệ');
        // A page that moved after it was linked ("Đã tự sửa đường dẫn"), a page with its own short
        // text ("Lấy từ mô tả trang") and two links whose pages are gone ("Gỡ 2 mục không còn").
        Redirect::query()->create(['locale' => 'vi', 'from_path' => '/ve-chung-toi', 'to_path' => '/gioi-thieu', 'entry_id' => $pages['gioi-thieu']->id,
            'created_at' => now()->addMinute(), 'updated_at' => now()->addMinute()]);
        $pages['tuyen-dung']->translations()->where('locale', 'vi')->update(['excerpt' => 'Việc làm kỹ thuật cơ khí chính xác, đào tạo nghề tại xưởng']);
        $add($menu, null, ['link_type' => 'entry', 'routable_type' => 'entry', 'routable_id' => 999998], 'Khuyến mãi cũ');
        $add($menu, null, ['link_type' => 'entry', 'routable_type' => 'entry', 'routable_id' => 999999], 'Tin cũ');
    });

    $dir = env('ADMIN_PREVIEW_DIR', storage_path('app/admin-preview'));
    @mkdir($dir, 0777, true);
    array_map('unlink', glob($dir.'/*.html') ?: []);

    foreach (ADMIN_PREVIEW_SCREENS as $name => $path) {
        $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.$path)->assertOk()->getContent();
        file_put_contents($dir.'/'.$name.'.html', $html);
    }

    // The page editor with blocks carrying looks, so the "Trình bày" panel under each block shows its
    // chosen, joined and split states; its buttons show "Khi bấm nút" with an older address sorted
    // into a kind, a new button searching for its page, an unsafe link's problem and a call.
    $page = app(Tenancy::class)->enter(1, function () use ($owner): Entry {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $owner->id]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Dịch vụ', 'slug' => 'dich-vu', 'status' => 'draft', 'body' => ['blocks' => [
            ['type' => 'hero', 'props' => ['heading' => 'Gia công cơ khí chính xác', 'motion' => 'fade', 'cta_label' => 'Nhận báo giá', 'cta_url' => '#nang-luc', 'cta2_label' => 'Xem dịch vụ']],
            ['type' => 'cta', 'props' => ['heading' => 'Liên hệ', 'button_label' => 'Gửi yêu cầu', 'button_url_do' => ['k' => 'url', 'url' => 'javascript:alert(1)', 'blank' => true]]],
            ['type' => 'quick_links', 'props' => ['items' => [['icon' => 'phone', 'label' => 'Gọi ngay', 'url_do' => ['k' => 'tel', 'phone' => '0915 668 383']]]]],
            ['type' => 'richtext', 'props' => ['heading' => 'Năng lực', 'scheme' => 'brand', 'bg' => 'scheme', 'spacing_top' => 'l', 'spacing_bottom' => 'l', 'corner' => 'soft', 'anchor_id' => 'nang-luc']],
            ['type' => 'richtext', 'props' => ['heading' => 'Quy trình', 'scheme' => 'brand', 'bg' => 'scheme', 'spacing_top' => 's', 'spacing_bottom' => 'xl', 'motion' => 'rise']],
        ]]]);

        return $entry;
    });
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content/'.$page->ulid.'/edit')->assertOk()->getContent();
    file_put_contents($dir.'/editor.html', $html);

    // Khách liên hệ: the inbox and one quote lead, whose answers sit under the form's labels and
    // whose ticked consent box shows the sentence stored with the lead.
    $lead = app(Tenancy::class)->enter(1, function (): FormSubmission {
        $form = Form::query()->create(['key' => 'bao-gia', 'name' => ['vi' => 'Báo giá'], 'status' => 'active']);
        $form->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'phone', 'type' => 'tel', 'label' => ['vi' => 'Số điện thoại'], 'sort_order' => 2],
            ['key' => 'company', 'type' => 'text', 'label' => ['vi' => 'Công ty'], 'sort_order' => 3],
            ['key' => 'service', 'type' => 'select', 'label' => ['vi' => 'Nhu cầu'], 'sort_order' => 4,
                'options' => ['choices' => [['value' => 'cnc', 'label' => ['vi' => 'Gia công CNC']]]]],
            ['key' => 'details', 'type' => 'textarea', 'label' => ['vi' => 'Nội dung cần báo giá'], 'sort_order' => 5],
            ['key' => 'consent', 'type' => 'checkbox', 'label' => ['vi' => 'Tôi đồng ý cho website lưu thông tin liên hệ.'], 'is_required' => true, 'sort_order' => 6],
        ]);
        $form->submissions()->create(['data' => ['name' => 'Trần Minh Khoa', 'phone' => '0903 112 233'], 'name' => 'Trần Minh Khoa', 'phone' => '0903 112 233', 'status' => 'new']);

        return $form->submissions()->create(['status' => 'read', 'name' => 'Phạm Thị Lan', 'phone' => '0915 668 383', 'source_url' => 'https://'.ADMIN_PREVIEW_HOST.'/lien-he', 'data' => [
            'name' => 'Phạm Thị Lan', 'phone' => '0915 668 383', 'company' => 'Công ty TNHH Cơ khí Bình Minh', 'service' => 'cnc',
            'details' => "Trục inox 304, 40 cái.\nCần giao trong tháng 12.", 'consent' => 'Tôi đồng ý cho website lưu thông tin liên hệ.',
        ]]);
    });
    foreach (['leads' => '/admin/forms', 'lead-detail' => '/admin/forms/'.$lead->ulid] as $name => $path) {
        $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.$path)->assertOk()->getContent();
        file_put_contents($dir.'/'.$name.'.html', $html);
    }

    // The platform Audit log, as a support operator, with a member's row and a support sign-in row.
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful(); // the platform roles
    $operator = User::factory()->create(['name' => 'Lê Hoàng Phúc']);
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';
    AuditLog::query()->create(['action' => 'rbac.role.assigned', 'actor_id' => $owner->id, 'actor_label' => $owner->email, 'site_id' => 1,
        'ip_address' => '113.161.72.18', 'user_agent' => $agent, 'meta' => ['role' => 'Biên tập viên']]);
    $support = AuditLog::query()->create(['action' => 'cms.menu.saved', 'actor_id' => $owner->id, 'actor_label' => $owner->email, 'site_id' => 1,
        'impersonator_id' => $operator->id, 'impersonator_label' => $operator->email, 'ip_address' => '14.232.10.5', 'user_agent' => $agent,
        'meta' => ['name' => 'Trình đơn chính']]);
    $this->flushSession(); // the owner's session would sign the operator out (session guard)
    $platform = 'http://'.config('tenancy.platform_host');
    foreach (['audit' => '/audit', 'audit-detail' => '/audit?open='.$support->ulid] as $name => $path) {
        $html = $this->actingAs($operator)->get($platform.$path)->assertOk()->getContent();
        // served by serve.mjs like the other screens, which rewrites only the preview host
        file_put_contents($dir.'/'.$name.'.html', str_replace($platform, 'http://'.ADMIN_PREVIEW_HOST, $html));
    }

    // The same log as the platform super-admin, who also gets "Dọn nhật ký" in the tool row.
    $top = User::factory()->create(['name' => 'Trần Thu Hà']);
    app(PlatformAccess::class)->onPlatform(fn () => $top->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();
    $this->flushSession();
    $html = $this->actingAs($top)->get($platform.'/audit')->assertOk()->getContent();
    file_put_contents($dir.'/audit-cleanup.html', str_replace($platform, 'http://'.ADMIN_PREVIEW_HOST, $html));

    // Its dialog open on each step, put into that page (it opens from the browser, never from a URL).
    $old = now()->utc()->startOfMonth()->subMonths(13)->format('Y-m');
    DB::connection('pgsql_admin')->statement('select public.audit_log_month_create(?::date)', [$old.'-01']);
    $dialog = Livewire::actingAs($top)->test(CleanupDialog::class)->call('openDialog')->call('loadMonths');
    $pick = $dialog->html();
    $dialog->set('month', $old)->call('next')->call('countRows')->assertSet('step', 'confirm');
    expect($dialog->get('exact'))->not->toBeNull();
    $steps = ['audit-cleanup-pick' => $pick, 'audit-cleanup-confirm' => $dialog->html()];
    foreach ($steps as $name => $open) {
        file_put_contents($dir.'/'.$name.'.html', str_replace([$platform, '</body>'], ['http://'.ADMIN_PREVIEW_HOST, $open.'</body>'], $html));
    }

    // The approval workflow settings, one collection on and one off, as the platform super-admin.
    app(Tenancy::class)->enter(1, function () use ($top): void {
        foreach ([['pv-news', 'Dự án', 'du-an-pv', true], ['pv-jobs', 'Tuyển dụng', 'tuyen-dung-pv', false]] as [$key, $label, $slug, $on]) {
            $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
            $category->translations()->create(['locale' => 'vi', 'name' => $label, 'slug' => $slug]);
            ContentSection::query()->create(['key' => $key, 'label' => $label, 'entry_type' => 'post', 'taxonomy_id' => $category->id,
                'permission_prefix' => 'entries', 'sort_order' => 1]);
            Livewire::actingAs($top)->test(WorkflowSettings::class)
                ->call('save', [['key' => $key, 'template' => 'admin_approval', 'active' => $on, 'from' => ['template' => null, 'active' => false]]])->assertReturned(true);
        }
    });
    $this->flushSession();
    $html = $this->actingAs($top)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/workflows')->assertOk()->getContent();
    // every collection's row carries its switch (shown once it has a flow); only "Dự án" is on
    expect(substr_count($html, 'role="switch"'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($html, 'aria-checked="true"'))->toBe(1)
        ->and($html)->toContain('Dự án', 'Tuyển dụng');
    file_put_contents($dir.'/workflows.html', $html);

    // The domain screen with its "add a name" dialog open on the first step (the www switch).
    $this->flushSession();
    $page = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/domains')->assertOk()->getContent();
    $open = app(Tenancy::class)->enter(1, fn (): string => Livewire::actingAs($owner)->test(DomainSettings::class)
        ->call('openAdd')->set('addName', 'congtydemo.vn')->assertSet('addOpen', true)->html());
    expect($open)->toContain('role="switch"');
    file_put_contents($dir.'/domains-add.html', str_replace('</body>', $open.'</body>', $page));

    // The floating contact buttons: round buttons with a channel of the site's own, then the phone
    // bottom bar with the "Mua hàng" button (the site has two languages, so the language bar shows).
    $channels = [
        ['key' => 'phone', 'on' => true, 'value' => '0912345678', 'tips' => ['vi' => 'Gọi ngay']],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'messenger', 'on' => true, 'value' => 'congtydemo'],
        ['key' => 'email', 'on' => false, 'value' => ''],
        ['key' => 'sms', 'on' => false, 'value' => ''],
        ['key' => 'c1', 'on' => true, 'value' => '/lien-he', 'icon' => 'ri-chat-3-line', 'name' => 'Đặt lịch', 'tips' => []],
    ];
    foreach (['floating-contact' => ['theme' => 'vertical'], 'floating-contact-bar' => ['theme' => 'horizontal', 'buy' => ['enabled' => true, 'color' => '#f7b84b']]] as $name => $look) {
        app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'floating_contact'],
            ['value' => $look + ['enabled' => true, 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24, 'channels' => $channels]]));
        $this->flushSession();
        $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/floating-contact')->assertOk()->getContent();
        file_put_contents($dir.'/'.$name.'.html', $html);
    }

    // "Tích hợp & Theo dõi": Google verified, Bing switched on but empty, Facebook off.
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'verification'], ['value' => [
        'google' => ['on' => true, 'token' => 'abcDEF1234567890abcdEF-_xyz'],
        'bing' => ['on' => true, 'token' => ''],
        'facebook' => ['on' => false, 'token' => ''],
    ]]));
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/integrations')->assertOk()->getContent();
    expect($html)->toContain('Xác minh &amp; Công cụ', 'wire:model.blur="tokens.google"');
    file_put_contents($dir.'/integrations.html', $html);

    // "Thông tin chung" filled from the older brand setting, as a site has it before its first save
    // (long names, so a clipped label or a sideways scroll shows up).
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => [
        'name' => 'Công ty Demo', 'legal_name' => 'Công ty Trách nhiệm Hữu hạn Cơ khí Chính xác Demo Việt Nam',
        'address' => 'Lô CN-07, Khu công nghiệp Phú Nghĩa, Chương Mỹ, Hà Nội', 'phone' => '0912 345 678',
        'email' => 'lienhe@congtydemo.vn', 'tax_id' => '0109876543', 'license' => '0109876543',
    ]]));
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/general')->assertOk()->getContent();
    expect($html)->toContain('Tên pháp lý đầy đủ (theo giấy phép)', 'wire:model.live.blur="form.legalName"');   // the values ride in the Livewire snapshot
    file_put_contents($dir.'/general.html', $html);

    // "Hiện trên Google & mạng xã hội" with long default texts and a custom robots.txt (the Google
    // preview shows where it cuts), and again with the "hide from Google" question open.
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'seo', 'key' => 'config'], ['value' => [
        'titles' => ['vi' => 'Công ty Demo — gia công cơ khí chính xác CNC 5 trục cho ngành ô tô, điện tử và khuôn mẫu'],
        'descriptions' => ['vi' => 'Gia công CNC 5 trục, tiện, phay, mài chính xác đến 0,005 mm cho khách hàng trong nước và xuất khẩu sang Nhật Bản, Hàn Quốc, châu Âu. Giao hàng đúng hẹn, báo giá trong 24 giờ.'],
        'template' => '%tieu-de% | %ten-website%', 'robots' => "User-agent: *\nDisallow: /gio-hang\nDisallow: /tim-kiem",
    ]]));
    // Its AI bot card reads a kept check: no request leaves the machine (the site answers through
    // Http::fake, the check is queued nowhere). This text blocks some pages for every bot, so the
    // card says so and the note under robots.txt names them.
    Queue::fake();
    app()->instance(SafeHttp::class, new SafeHttp(static fn (string $host): array => ['93.184.216.34']));
    $served = "User-agent: *\nDisallow:";
    Http::fake(static function (Request $request) use (&$served) {
        return str_ends_with($request->url(), '/robots.txt') ? Http::response($served, 200) : Http::response('<!doctype html><title>Trang chủ</title>', 200);
    });
    app(AiCrawlerCheck::class)->run(1);
    $this->flushSession();
    $page = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/seo')->assertOk()->getContent();
    expect($page)->toContain('wire:model.live.blur="form.template"', 'bsSeoFields(', 'data-ai-check', 'data-robots-note');
    file_put_contents($dir.'/seo.html', $page);
    $open = app(Tenancy::class)->enter(1, fn (): string => Livewire::actingAs($owner)->test(SeoSettings::class)
        ->call('toggleIndexable')->assertSet('confirmHide', true)->html());
    expect($open)->toContain('role="alertdialog"', 'Google sẽ gỡ website');
    file_put_contents($dir.'/seo-hide.html', str_replace('</body>', $open.'</body>', $page));

    // The card in its other states, the robots.txt text cleared: all clear; a check under way; and
    // a service in front adding a block, just checked (the button waits for the minute).
    app(Tenancy::class)->enter(1, function (): void {
        $seo = SiteSetting::query()->where('group_name', 'seo')->where('key', 'config')->firstOrFail();
        $seo->update(['value' => ['robots' => ''] + (array) $seo->value]);
    });
    $aiShot = function (string $name, string $expect) use ($owner, $dir): void {
        $this->flushSession();
        $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/seo')->assertOk()->getContent();
        expect($html)->toContain($expect)->not->toContain('data-robots-note');
        file_put_contents($dir.'/'.$name.'.html', $html);
    };
    $aiShot('seo-ai-ok', 'data-tone="done"');
    app(AiCrawlerCheck::class)->markRunning(1);
    $aiShot('seo-ai-busy', 'data-tone="wait"');
    app(AiCrawlerCheck::class)->clearRunning(1);
    $served = "User-agent: *\nDisallow:\n\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: ClaudeBot\nDisallow: /";
    app(AiCrawlerCheck::class)->run(1);
    RateLimiter::hit('seo-ai-check:1', 60);
    $aiShot('seo-ai-warn', 'Mỗi phút chỉ kiểm được 1 lần.');

    // The post editor with its short description and Google title and description filled, long
    // enough that the meters show their bands and the Google preview shows where it cuts the text.
    $post = app(Tenancy::class)->enter(1, function () use ($owner): Entry {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Khánh thành nhà xưởng gia công cơ khí chính xác mới tại Khu công nghiệp Phú Nghĩa', 'slug' => 'khanh-thanh-nha-xuong', 'status' => 'draft',
            'excerpt' => 'Nhà xưởng mới rộng 5.000 m² với dây chuyền CNC 5 trục giúp rút ngắn thời gian giao hàng còn một nửa cho khách hàng trong nước và xuất khẩu.',
            'seo_title' => 'Khánh thành nhà xưởng cơ khí chính xác mới tại Phú Nghĩa, Hà Nội năm 2026',
            'seo_description' => 'Nhà xưởng mới rộng 5.000 m² với dây chuyền CNC 5 trục, rút ngắn thời gian giao hàng còn một nửa cho khách hàng trong nước và xuất khẩu sang Nhật Bản, Hàn Quốc.',
            'body' => ['blocks' => []]]);

        return $entry;
    });
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content/'.$post->ulid.'/edit')->assertOk()->getContent();
    expect($html)->toContain('data-seo-fields', 'wire:model="excerpt"');
    file_put_contents($dir.'/post-editor.html', $html);

    // "Chuyên mục" of the news section: a small tree as deep as it may go (three levels), one category translated,
    // long enough names that a clipped name or a sideways scroll shows up.
    app(Tenancy::class)->enter(1, function (): void {
        $root = Taxonomy::query()->whereKey(ContentSection::query()->where('key', 'posts')->value('taxonomy_id'))->firstOrFail();
        $make = fn (string $name, ?Taxonomy $parent, array $names = []): Taxonomy => app(CreateTaxonomy::class)
            ->handle('post_cat', $parent, new TaxonomyData(names: ['vi' => $name] + $names));
        $company = $make('Tin công ty', $root, ['en' => 'Company news']);
        $make('Sự kiện và hội chợ triển lãm ngành cơ khí chính xác', $company);
        $make('Hội chợ Mechanical Expo', $company);
        $know = $make('Kiến thức', $root);
        $make('Gia công CNC', $know);
        $make('Khuyến mãi', $root);
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/taxonomies?section=posts')->assertOk()->getContent();
    expect($html)->toContain('data-bs-lazy="bsCategories"', 'Gia công CNC');
    file_put_contents($dir.'/categories.html', $html);

    // The product editor with the same shared Google part (no short description: a product has
    // none). The catalog is switched on only now, so the screens above keep their menus.
    $product = app(Tenancy::class)->enter(1, function (): Product {
        SiteModule::query()->updateOrCreate(['module_key' => 'catalog'], ['enabled' => true]);
        $product = Product::query()->create(['status' => 'draft', 'sku' => 'DG-CNC-001', 'price' => 12500000]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá kẹp phôi CNC 5 trục', 'slug' => 'do-ga-kep-phoi-cnc-5-truc',
            'seo_title' => 'Đồ gá kẹp phôi CNC 5 trục', 'seo_description' => '']);

        return $product;
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/catalog/'.$product->ulid.'/edit')->assertOk()->getContent();
    expect($html)->toContain('data-seo-fields')->not->toContain('wire:model="excerpt"');
    file_put_contents($dir.'/product-editor.html', $html);

    // The products list on a selling site, with a realistic set: two languages, short and very long names, every
    // price shape (none, plain, one thousand million, on sale with an end date), every stock kind, labels, a
    // three-level category and some products without the English translation. GET-only, like the other screens.
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'catalog', 'key' => 'mode'], ['value' => ['value' => 'commerce']]);
        $cat = fn (string $name, ?Taxonomy $parent, string $en): Taxonomy => app(CreateTaxonomy::class)
            ->handle('product_cat', $parent, new TaxonomyData(names: ['vi' => $name, 'en' => $en]));
        $machines = $cat('Máy gia công', null, 'Machines');
        $milling = $cat('Máy phay', $machines, 'Milling');
        $fiveAxis = $cat('Máy phay CNC năm trục liên động', $milling, 'Five-axis CNC milling');
        $tools = $cat('Dụng cụ cắt', null, 'Cutting tools');

        $long = 'Mũi khoan thép gió dài HSS đường kính 10 mm, chuôi trụ, mài chính xác, phủ titan nitrua cho gia công cơ khí chính xác';
        $rows = [
            ['Mũi khoan HSS 10', 'mui-khoan-hss-10', 'MK-HSS-10', 480000, null, null, 6, [], $tools, true],
            [$long, 'mui-khoan-hss-dai', 'MK-HSS-DAI', 1090000000, null, null, 1234, ['featured'], $fiveAxis, true],
            ['Bộ dao phay ngón', 'bo-dao-phay-ngon', null, null, null, null, null, [], null, true],
            ['Đồ gá kẹp phôi CNC 5 trục', 'do-ga-kep-phoi', 'DG-CNC-002', 12500000, 9900000, '2026-12-31 16:59:00', 3, ['best'], $milling, true],
            ['Dao tiện ngoài', 'dao-tien-ngoai', 'DT-01', 250000, null, null, 0, ['featured', 'best'], $tools, false],
            ['Ê tô máy phay', 'e-to-may-phay', 'ET-02', 3200000, 2800000, null, 6, [], $machines, false],
            ['Mũi taro M8', 'mui-taro-m8', 'MT-08', 95000, null, null, 1234, [], $tools, true],
            ['Máy phay CNC năm trục liên động độ chính xác cao', 'may-phay-5-truc', 'MP-5T', 850000000, null, null, 3, ['featured'], $fiveAxis, true],
            ['Đầu kẹp dao ER32', 'dau-kep-er32', 'ER-32', 180000, null, null, null, [], $tools, false],
            ['Dầu cắt gọt tổng hợp 20 lít', 'dau-cat-got', 'DC-20', 1450000, null, null, 0, [], null, true],
            ['Thước cặp điện tử 150 mm', 'thuoc-cap', 'TC-150', 790000, 690000, '2026-11-30 16:59:00', 6, ['best'], $tools, false],
            ['Phôi thép S45C', 'phoi-thep', 'S45C', 42000, null, null, 1234, [], null, true],
        ];
        foreach ($rows as [$name, $slug, $sku, $price, $sale, $saleEnd, $stock, $tags, $category, $hasEn]) {
            $product = Product::query()->create(['status' => $stock === 0 ? 'archived' : 'published', 'sku' => $sku, 'price' => $price, 'stock' => $stock,
                'sale_price' => $sale, 'sale_ends_at' => $saleEnd, 'is_featured' => in_array('featured', $tags, true), 'is_bestseller' => in_array('best', $tags, true)]);
            $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);
            if ($hasEn) {
                $product->translations()->create(['locale' => 'en', 'name' => 'Product '.$slug, 'slug' => 'en-'.$slug]);
            }
            if ($category !== null) {
                $product->assignCategories([$category->id]);
            }
        }
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/catalog')->assertOk()->getContent();
    expect($html)->toContain('Mũi khoan thép gió dài', 'Bộ dao phay ngón', 'list-langs2');
    file_put_contents($dir.'/product-list.html', $html);

    // The same list with 3 rows ticked (the sticky bar and its one skip sentence), and with the "Đổi chuyên mục chính"
    // dialog open for 2 products over about 15 categories (one of them three levels deep). The component is rendered
    // by the Livewire test harness and put in place of the list's own root, so the page keeps its real shell.
    $ulids = app(Tenancy::class)->enter(1, function (): array {
        $cat = fn (string $name, ?Taxonomy $parent): Taxonomy => app(CreateTaxonomy::class)
            ->handle('product_cat', $parent, new TaxonomyData(names: ['vi' => $name, 'en' => $name]));
        $machines = Taxonomy::query()->where('vocabulary', 'product_cat')->whereHas('translations', fn ($q) => $q->where('name', 'Máy gia công'))->firstOrFail();
        $names = ['Phụ kiện máy', 'Đồ gá và kẹp', 'Dụng cụ đo kiểm', 'Vật tư tiêu hao', 'Dầu và chất làm mát', 'Thiết bị an toàn lao động',
            'Máy tiện CNC', 'Máy mài', 'Máy cắt dây', 'Máy khoan đứng'];
        foreach ($names as $name) {
            $cat($name, str_starts_with($name, 'Máy') ? $machines : null);
        }

        return Product::query()->whereIn('sku', ['MK-HSS-10', 'DT-01', 'DG-CNC-002'])->orderBy('sku')->pluck('ulid')->all();
    });
    expect($ulids)->toHaveCount(3);
    $mount = fn () => Livewire::actingAs($owner)->test(Index::class);
    // the harness leaves the snapshot off the root once an action ran; put it back so the browser can boot the component
    $withSnapshot = function ($test): string {
        $snapshot = (fn () => $this->lastState->getSnapshot())->call($test);

        return (string) preg_replace('/^<div wire:id="([^"]+)"/', '<div wire:snapshot="'.e(is_string($snapshot) ? $snapshot : json_encode($snapshot)).'" wire:effects="[]" wire:id="$1"', $test->html(), 1);
    };
    $swap = function (string $page, string $component): string {
        // the list's own root: the div that carries its snapshot, up to the div that closes it
        expect(preg_match('/<div wire:snapshot="[^"]*admin\\.catalog\\.index/', $page, $m, PREG_OFFSET_CAPTURE))->toBe(1);
        $from = $m[0][1];
        preg_match_all('#<div\b|</div>#', $page, $tags, PREG_OFFSET_CAPTURE, $from);
        $depth = 0;
        $to = null;
        foreach ($tags[0] as [$tag, $at]) {
            $depth += $tag === '</div>' ? -1 : 1;
            if ($depth === 0) {
                $to = $at + 6;
                break;
            }
        }
        expect($to)->not->toBeNull();

        return substr($page, 0, $from).$component.substr($page, $to);
    };
    $ticked = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($mount()->call('toggleSelect', $ulids)));
    expect($ticked)->toContain('role="region"', 'Bỏ chọn');
    file_put_contents($dir.'/product-list-selected.html', $swap($html, $ticked));
    $dialog = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($mount()->call('toggleSelect', [$ulids[0], $ulids[1]])->call('openCategory')));
    file_put_contents($dir.'/product-list-category.html', $swap($html, $dialog));

    expect(glob($dir.'/*.html'))->toHaveCount(count(ADMIN_PREVIEW_SCREENS) + 25);
});
