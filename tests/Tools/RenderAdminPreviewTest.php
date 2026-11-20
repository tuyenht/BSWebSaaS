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

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Admin\Livewire\LanguageSettings;
use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Admin\Livewire\Forms\FormList;
use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Admin\Livewire\NotificationBell;
use App\Modules\Admin\Livewire\Platform\Audit\CleanupDialog;
use App\Modules\Admin\Livewire\SeoSettings;
use App\Modules\Admin\Livewire\Settings\Domains as DomainSettings;
use App\Modules\Admin\Livewire\Users\Index as MembersIndex;
use App\Modules\Admin\Livewire\WorkflowSettings;
use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Admin\Support\Settings\SetBrandImages;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductIndexLink;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Http\SafeHttp;
use App\Modules\Storefront\Models\ContactClickDaily;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\AiCrawlerCheck;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
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
    'account' => '/admin/account',
    'account-security' => '/admin/account?tab=security',
    'account-sessions' => '/admin/account?tab=sessions',
    'account-linked' => '/admin/account?tab=linked',
    'account-prefs' => '/admin/account?tab=prefs',
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
        ownerPassword: 'a-strong-password', locales: ['vi', 'en'], modules: ['cms', 'media', 'contacts'],
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
        // Each screen is its own request, as Octane serves it: Livewire forgets the @assets it
        // already put in an earlier page's head (the account tabs each link the account styles).
        app('livewire')->flushState();
        $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.$path)->assertOk()->getContent();
        file_put_contents($dir.'/'.$name.'.html', $html);
    }

    // The Trang list as a page tree three levels deep (each row with its grip and the move items), and
    // the same list searched, where a drag and «Chuyển lên / xuống» are locked with their reason.
    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $under = function (?Entry $parent, string $title, string $slug, int $position) use ($owner): Entry {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $owner->id, 'parent_id' => $parent?->id, 'position' => $position]);
            $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

            return $entry;
        };
        $services = $under(null, 'Dịch vụ', 'dich-vu', 1);
        $cnc = $under($services, 'Gia công CNC', 'gia-cong-cnc', 0);
        $under($cnc, 'Phay CNC 5 trục', 'phay-cnc-5-truc', 0);
        $under($cnc, 'Tiện CNC', 'tien-cnc', 1);
        $under($services, 'Xử lý bề mặt', 'xu-ly-be-mat', 1);
        $under(Entry::query()->whereHas('translations', fn ($q) => $q->where('slug', 'gioi-thieu'))->first(), 'Đội ngũ', 'doi-ngu', 0);
    });
    foreach (['list-tree' => '/admin/content', 'list-tree-search' => '/admin/content?q=CNC'] as $name => $path) {
        app('livewire')->flushState();
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
        $form->submissions()->create(['data' => ['name' => 'Nhận vay nhanh', 'details' => 'Vay tiền trực tuyến duyệt trong 5 phút.'], 'name' => 'Nhận vay nhanh', 'email' => 'vaynhanh247@mail.ru', 'status' => 'spam', 'is_spam' => true]);

        return $form->submissions()->create(['status' => 'read', 'name' => 'Phạm Thị Lan', 'phone' => '0915 668 383', 'source_url' => 'https://'.ADMIN_PREVIEW_HOST.'/lien-he', 'data' => [
            'name' => 'Phạm Thị Lan', 'phone' => '0915 668 383', 'company' => 'Công ty TNHH Cơ khí Bình Minh', 'service' => 'cnc',
            'details' => "Trục inox 304, 40 cái.\nCần giao trong tháng 12.", 'consent' => 'Tôi đồng ý cho website lưu thông tin liên hệ.',
        ]]);
    });
    foreach (['leads' => '/admin/forms', 'lead-detail' => '/admin/forms/'.$lead->ulid, 'leads-spam' => '/admin/forms?status=spam'] as $name => $path) {
        $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.$path)->assertOk()->getContent();
        file_put_contents($dir.'/'.$name.'.html', $html);
    }

    // Biểu mẫu: the list (the quote form above, with its leads and one recipient), its editor and a
    // new form from the «Đăng ký tư vấn» starting point.
    $quote = app(Tenancy::class)->enter(1, function (): Form {
        $form = Form::query()->where('key', 'bao-gia')->firstOrFail();
        $form->update(['notify_emails' => ['kinhdoanh@anvinh.vn']]);

        return $form;
    });
    foreach (['form-builder' => '/admin/form-builder', 'form-builder-edit' => '/admin/form-builder/'.$quote->ulid.'/edit', 'form-builder-new' => '/admin/form-builder/create/consult'] as $name => $path) {
        $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.$path)->assertOk()->getContent();
        file_put_contents($dir.'/'.$name.'.html', $html);
    }
    // «Xóa» on the quote form: the question with its leads and «Tắt biểu mẫu» instead.
    $formsPage = (string) file_get_contents($dir.'/form-builder.html');
    $ask = app(Tenancy::class)->enter(1, fn (): string => Livewire::actingAs($owner)->test(FormList::class)
        ->call('askDelete', $quote->ulid)->assertSet('confirmOpen', true)->html());
    expect($ask)->toContain('role="alertdialog"', __('form_builder.del_off'));
    file_put_contents($dir.'/form-builder-delete.html', str_replace('</body>', $ask.'</body>', $formsPage));
    // The leads inbox while the website's contact form is switched off: the band with «Bật lại».
    $contact = app(Tenancy::class)->enter(1, fn (): Form => Form::query()->where('key', Form::SYSTEM_KEY)->firstOrFail());
    app(Tenancy::class)->enter(1, fn () => $contact->update(['status' => 'disabled']));
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/forms')->assertOk()->getContent();
    expect($html)->toContain('data-state="form-off"');
    file_put_contents($dir.'/leads-form-off.html', $html);
    app(Tenancy::class)->enter(1, fn () => $contact->update(['status' => 'active']));

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

    // "Gói & tính năng": three plans (one with a customer on it) as the platform super-admin, who
    // also sees the website types; the same list as a billing operator; and the plan editor.
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate tenant_history restart identity cascade');
    $admin->table('tenants')->update(['plan_id' => null]);
    $admin->statement('delete from plans');
    $billing = User::factory()->create(['name' => 'Phạm Minh Châu']);
    app(PlatformAccess::class)->onPlatform(fn () => $billing->assignRole('billing'));
    app(PlatformAccess::class)->forgetMemo();
    $plans = app(PlatformPlans::class);
    $planKeys = [];
    foreach ([
        ['Cơ bản', true, ['sites.max' => 1, 'locales.max' => 1, 'custom_domains.max' => 0]],
        ['Chuyên nghiệp', true, ['workflow.editorial' => true, 'sites.max' => 3, 'locales.max' => 3, 'custom_domains.max' => 1, 'rbac.custom_roles' => true]],
        ['Doanh nghiệp', false, ['workflow.editorial' => true, 'sites.max' => 10, 'rbac.custom_roles' => true, 'rbac.category_scopes' => true]],
    ] as [$planName, $sold, $grants]) {
        $plans->setFeatures($billing, $made = $plans->create($billing, $planName, $sold), $grants);
        $planKeys[] = $made->key;
    }
    $admin->table('tenants')->update(['plan_id' => Plan::query()->where('key', $planKeys[0])->value('id')]);
    $this->flushSession();
    foreach (['plans' => [$top, '/plans'], 'plans-edit' => [$top, '/plans/'.$planKeys[1]]] as $name => [$who, $path]) {
        app(PlatformAccess::class)->forgetMemo();
        $html = $this->actingAs($who)->get($platform.$path)->assertOk()->getContent();
        file_put_contents($dir.'/'.$name.'.html', str_replace($platform, 'http://'.ADMIN_PREVIEW_HOST, $html));
    }
    $this->flushSession();
    app(PlatformAccess::class)->forgetMemo();
    $html = $this->actingAs($billing)->get($platform.'/plans')->assertOk()->getContent();
    file_put_contents($dir.'/plans-billing.html', str_replace($platform, 'http://'.ADMIN_PREVIEW_HOST, $html));
    $admin->table('tenants')->update(['plan_id' => null]); // the screens below show the site's own limits, not the plan's

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

    // The top bar's bell with its list already read (design 01 "Thông báo"): the page's bell is swapped
    // for the component after load(), so a click on the bell opens the drawn panel with no server. The
    // plain pages keep the bell unread-but-not-loaded: a click there shows the "Thử lại" card.
    $swapBell = function (string $page, string $bell): string {
        $start = strrpos(substr($page, 0, (int) strpos($page, 'wire:name="admin.notification-bell"')), '<div');
        expect($start)->not->toBeFalse();
        $depth = 0;
        $at = (int) $start;
        do {
            preg_match('~<div[\s>]|</div>~', $page, $tag, PREG_OFFSET_CAPTURE, $at);
            $depth += str_starts_with($tag[0][0], '</') ? -1 : 1;
            $at = $tag[0][1] + strlen($tag[0][0]);
        } while ($depth > 0);

        return substr($page, 0, (int) $start).$bell.substr($page, $at);
    };
    DB::connection('pgsql_admin')->statement('truncate site_notifications restart identity cascade');
    $this->flushSession();
    $bellPage = fn (): string => $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin')->assertOk()->getContent();
    // A call's answer carries the component's state beside the HTML: put it back on the root, as the
    // first page does, so Livewire starts the part in the browser.
    $bellLoaded = function () use ($owner): string {
        $bell = app(Tenancy::class)->enter(1, fn (): Testable => Livewire::actingAs($owner)->test(NotificationBell::class)->call('load'));

        return (string) preg_replace('~^<div ~', '<div wire:snapshot="'.e((string) json_encode($bell->snapshot)).'" wire:effects="[]" ', trim($bell->html()), 1);
    };
    file_put_contents($dir.'/bell-empty.html', $swapBell($bellPage(), $bellLoaded()));
    $entryUlid = app(Tenancy::class)->enter(1, fn (): mixed => Entry::query()->value('ulid'));
    $notices = [
        ['lead.new', ['name' => 'Trần Văn Hùng (Công ty Cơ khí Hưng Phát)', 'submission' => (string) str()->ulid()], 12, false],
        ['workflow.pending_review', ['entry' => $entryUlid, 'title' => 'Gia công CNC cho linh kiện ô tô', 'actor' => 'Chị Lan', 'change' => true], 35, false],
        ['system.domain_problem', ['sentence' => 'failing', 'domains' => 'anvinh.vn và www.anvinh.vn', 'until' => '14/10/2026'], 70, false],
        ['workflow.assigned', ['entry' => $entryUlid, 'title' => 'An Vinh đầu tư máy phay 5 trục mới', 'actor' => 'Anh Nam'], 150, false],
        ['workflow.returned', ['entry' => $entryUlid, 'title' => 'Sai số gia công: hiểu đúng để đặt hàng đúng', 'actor' => 'Chị Hà', 'note' => 'Thêm bảng dung sai và một ảnh chi tiết sau gia công.'], 60 * 22, false],
        ['security.two_factor_disabled', ['at' => '08:12 12/11/2026'], 60 * 26, false],
        ['workflow.published', ['entry' => $entryUlid, 'title' => '5 lưu ý khi chọn xưởng gia công CNC', 'actor' => 'Chị Hà'], 60 * 30, true],
        ['security.support_sign_in', ['staff' => 'Lê Hoàng Phúc', 'platform' => 'Bảo Sơn', 'member' => 'Nguyễn Ngọc Anh', 'at' => '10:05 11/11/2026', 'reason' => 'Khách nhờ sửa trình đơn đầu trang'], 60 * 50, true],
    ];
    foreach (array_reverse($notices) as [$kind, $facts, $minutes, $read]) { // oldest first, as they arrive
        DB::connection('pgsql_admin')->table('site_notifications')->insert([
            'ulid' => (string) str()->ulid(), 'site_id' => 1, 'user_id' => $owner->id, 'kind' => $kind, 'data' => json_encode($facts),
            'read_at' => $read ? now() : null, 'created_at' => now()->subMinutes($minutes),
        ]);
    }
    $this->flushSession();
    file_put_contents($dir.'/bell.html', $swapBell($bellPage(), $bellLoaded()));

    // "Thông báo": the same notices on their page (design c-thong-bao), and the console's empty copy.
    $this->flushSession();
    file_put_contents($dir.'/notifications.html', $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/notifications')->assertOk()->getContent());
    $this->flushSession();
    $html = $this->actingAs($top)->get($platform.'/notifications')->assertOk()->getContent();
    file_put_contents($dir.'/notifications-platform.html', str_replace($platform, 'http://'.ADMIN_PREVIEW_HOST, $html));

    // The floating contact buttons: round buttons with a channel of the site's own, then the phone
    // bottom bar with the "Mua hàng" button (the site has two languages, so the language bar shows).
    // SMS is switched on but not filled in: the preview draws its place as an unfinished slot.
    $channels = [
        ['key' => 'phone', 'on' => true, 'value' => '0912345678', 'tips' => ['vi' => 'Gọi ngay']],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'messenger', 'on' => true, 'value' => 'congtydemo'],
        ['key' => 'email', 'on' => false, 'value' => ''],
        ['key' => 'sms', 'on' => true, 'value' => ''],
        ['key' => 'c1', 'on' => true, 'value' => '/lien-he', 'icon' => 'ri-chat-3-line', 'name' => 'Đặt lịch', 'tips' => []],
    ];
    // Then the combined layout (round buttons on a computer, the bar on a phone, "Mua hàng" on) with a few
    // taps counted, "Mua hàng" among them.
    $looks = [
        'floating-contact' => ['theme' => 'vertical', 'layout' => 'vertical'],
        'floating-contact-bar' => ['theme' => 'horizontal', 'layout' => 'horizontal', 'buy' => ['enabled' => true, 'color' => '#f7b84b']],
        'floating-contact-combo' => ['theme' => 'horizontal', 'layout' => 'combo', 'buy' => ['enabled' => true, 'color' => '#f7b84b']],
    ];
    foreach ($looks as $name => $look) {
        if ($name === 'floating-contact-combo') {
            app(Tenancy::class)->enter(1, function (): void {
                ContactClickDaily::query()->create(['day' => now('Asia/Ho_Chi_Minh')->toDateString(), 'channel' => 'zalo', 'placement' => 'bar', 'clicks' => 18]);
                ContactClickDaily::query()->create(['day' => now('Asia/Ho_Chi_Minh')->toDateString(), 'channel' => 'phone', 'placement' => 'floating', 'clicks' => 7]);
                ContactClickDaily::query()->create(['day' => now('Asia/Ho_Chi_Minh')->toDateString(), 'channel' => 'buy', 'placement' => 'bar', 'clicks' => 5]);
            });
        }
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
    expect($html)->toContain('Xác minh &amp; Công cụ', 'wire:model.live.blur="tokens.google"');
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

    // "Google & mạng xã hội" with long default texts and a custom robots.txt (the Google
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
    expect($page)->toContain('wire:model.live.blur="form.template"', 'data-template-insert', 'data-share-change', 'bsSeoFields(', 'data-ai-check', 'data-robots-note');
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

    // The share image slot holding a picture from the library, its long file name wrapped, never clipped.
    $shareHash = hash('sha256', random_bytes(16));
    $share = app(Tenancy::class)->enter(1, fn (): Media => Media::query()->create([
        'collection' => 'library', 'slug' => 'anh-chia-se', 'hash' => $shareHash,
        'original_filename' => 'anh-chia-se-facebook-zalo-nha-xuong-gia-cong-co-khi-chinh-xac-phu-nghia-2026.jpg',
        'storage_key' => 's/library/anh-chia-se-'.substr($shareHash, 0, 12).'.jpg',
        'mime' => 'image/jpeg', 'size' => 182_000, 'width' => 1200, 'height' => 630,
    ]));
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['og_image' => $share->storage_key]));
    $aiShot('seo-image', 'aria-label="Gỡ ảnh"');

    // "Giao diện website": a logo from the library with a long file name (wrapped, never clipped),
    // the dark logo and the favicon still empty, and the row saying who saved last.
    $logoHash = hash('sha256', random_bytes(16));
    $logo = app(Tenancy::class)->enter(1, fn (): Media => Media::query()->create([
        'collection' => 'library', 'slug' => 'logo-cong-ty', 'hash' => $logoHash,
        'original_filename' => 'logo-cong-ty-co-khi-chinh-xac-demo-viet-nam-ban-ngang-nen-trong-suot-2026.png',
        'storage_key' => 's/library/logo-cong-ty-'.substr($logoHash, 0, 12).'.png',
        'mime' => 'image/png', 'size' => 24_000, 'width' => 640, 'height' => 160,
    ]));
    $this->actingAs($owner);
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]));
    // A header setting, so the phone menu card is drawn too (tone dark, brand accent: the swatch shows).
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['drawer_tone' => 'dark', 'drawer_accent' => 'brand']]));
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/appearance')->assertOk()->getContent();
    expect($html)->toContain('data-look-slot="logo"', 'data-look-stamp', 'data-look-share', 'Cập nhật lần cuối', 'Màu nhấn của menu', 'data-look-search', 'Có tìm kiếm');
    file_put_contents($dir.'/appearance.html', $html);

    // "Ngôn ngữ website": Vietnamese the default, English public, Japanese written but hidden (its
    // sub-line names the /ja/ address), the rest off; then the question before changing the default.
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->updateOrCreate(['locale' => 'ja'], ['enabled' => true, 'is_public' => false, 'is_default' => false]));
    $this->flushSession();
    $page = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/languages')->assertOk()->getContent();
    expect($page)->toContain('data-lng-row="vi"', 'data-lng-row="ja"', 'role="switch"', 'Chỉ soạn nội bộ');
    file_put_contents($dir.'/langs.html', $page);
    $ask = app(Tenancy::class)->enter(1, fn (): string => Livewire::actingAs($owner)->test(LanguageSettings::class)
        ->call('chooseDefault', 'en')->assertSet('asking', true)->html());
    expect($ask)->toContain('role="alertdialog"', 'Đổi ngôn ngữ mặc định sang English?');
    file_put_contents($dir.'/langs-ask.html', str_replace('</body>', $ask.'</body>', $page));

    // The post editor with its short description and Google title and description filled, long
    // enough that the meters show their bands and the Google preview shows where it cuts the text; a
    // featured image with its «Mô tả ảnh» under it.
    $post = app(Tenancy::class)->enter(1, function () use ($owner, $logo): Entry {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id, 'meta' => ['image_url' => $logo->storage_key]]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Khánh thành nhà xưởng gia công cơ khí chính xác mới tại Khu công nghiệp Phú Nghĩa', 'slug' => 'khanh-thanh-nha-xuong', 'status' => 'draft',
            'excerpt' => 'Nhà xưởng mới rộng 5.000 m² với dây chuyền CNC 5 trục giúp rút ngắn thời gian giao hàng còn một nửa cho khách hàng trong nước và xuất khẩu.',
            'seo_title' => 'Khánh thành nhà xưởng cơ khí chính xác mới tại Phú Nghĩa, Hà Nội năm 2026',
            'seo_description' => 'Nhà xưởng mới rộng 5.000 m² với dây chuyền CNC 5 trục, rút ngắn thời gian giao hàng còn một nửa cho khách hàng trong nước và xuất khẩu sang Nhật Bản, Hàn Quốc.',
            'cover_alt' => 'Thợ đứng máy phay CNC 5 trục trong nhà xưởng mới',
            'body' => ['blocks' => []]]);

        return $entry;
    });
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content/'.$post->ulid.'/edit')->assertOk()->getContent();
    expect($html)->toContain('data-seo-fields', 'wire:model="excerpt"', 'id="entry-cover-alt"');
    file_put_contents($dir.'/post-editor.html', $html);
    // A new post: the frame's header says «Thêm mới», Xem trước waits for the first save, no Trash.
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content/create?section=posts')->assertOk()->getContent();
    expect($html)->toContain('data-child-screen', 'data-guide="preview"')->not->toContain('wire:click="trash"');
    file_put_contents($dir.'/post-new.html', $html);

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

    // The post editor's «Chuyên mục» card (board 07): a post filed under two categories of its section and
    // one of another (that section's name beside it), «Chuyên mục chính» under the list, «Thêm chuyên mục
    // mới» for the owner. The post and the category made for it are taken away again: the screens
    // below count their own.
    $tech = null;
    $filed = app(Tenancy::class)->enter(1, function () use ($owner, &$tech): Entry {
        $jobs = Taxonomy::query()->whereKey(ContentSection::query()->where('key', 'pv-jobs')->value('taxonomy_id'))->firstOrFail();
        $tech = app(CreateTaxonomy::class)->handle('post_cat', $jobs, new TaxonomyData(names: ['vi' => 'Kỹ thuật viên']));
        $id = fn (string $name): int => (int) Taxonomy::query()->where('vocabulary', 'post_cat')
            ->whereHas('translations', fn ($q) => $q->where('name', $name))->value('id');
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $owner->id]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Tuyển kỹ thuật viên vận hành máy CNC', 'slug' => 'tuyen-ky-thuat-vien-cnc',
            'status' => 'draft', 'body' => ['blocks' => []]]);
        $entry->assignCategories([$id('Tin công ty'), $id('Gia công CNC'), $tech->id]);

        return $entry;
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content/'.$filed->ulid.'/edit')->assertOk()->getContent();
    expect($html)->toContain('id="entry-cat-main"', 'Kỹ thuật viên', 'Tuyển dụng', __('entries.ed.cat_add'));
    file_put_contents($dir.'/post-editor-categories.html', $html);
    app(Tenancy::class)->enter(1, function () use ($filed, $tech): void {
        $filed->forceDelete();
        $tech?->translations()->delete();
        $tech?->delete();
    });

    // The news list (Tin tức): posts in the categories above, with cover pictures, several statuses
    // and two authors, as the posts variant of the list board shows them. A cover is the picture's
    // key in the post's meta; here it names an image the preview server has, and one post in three
    // has none (the placeholder).
    $mediaConfig = config('media');
    config(['media.driver' => 'cloudflare', 'media.cloudflare.base' => 'http://'.ADMIN_PREVIEW_HOST]);
    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $writer = User::query()->create(['name' => 'Trần Thị Lan', 'email' => 'lan@'.ADMIN_PREVIEW_HOST, 'password' => 'a-strong-password']);
        $cat = fn (string $name): int => (int) Taxonomy::query()->where('vocabulary', 'post_cat')
            ->whereHas('translations', fn ($q) => $q->where('name', $name))->value('id');
        $rows = [
            ['Khánh thành nhà xưởng gia công cơ khí chính xác mới tại Phú Nghĩa', 'khanh-thanh-nha-xuong-moi', 'published', 'Tin công ty', 0],
            ['Những điều nên biết trước khi đặt gia công CNC', 'dieu-nen-biet-truoc-khi-dat-gia-cong', 'published', 'Gia công CNC', 1],
            ['Thông báo lịch nghỉ Tết Nguyên đán 2027', 'lich-nghi-tet-2027', 'scheduled', 'Tin công ty', 0],
            ['Chọn vật liệu thép cho chi tiết chịu mài mòn', 'chon-vat-lieu-thep', 'draft', 'Kiến thức', 1],
            ['Ưu đãi 10% cho đơn gia công đầu tiên', 'uu-dai-don-dau-tien', 'draft', 'Khuyến mãi', 0],
            ['Tham gia Hội chợ Mechanical Expo 2026', 'hoi-cho-mechanical-expo-2026', 'archived', 'Hội chợ Mechanical Expo', 1],
        ];
        foreach ($rows as $i => [$title, $slug, $status, $category, $byWriter]) {
            $hash = hash('sha256', $slug);
            $media = Media::query()->create(['collection' => 'library', 'slug' => 'anh-'.$slug, 'hash' => $hash,
                'original_filename' => 'anh-'.$slug.'.jpg', 'storage_key' => 's/library/anh-'.$slug.'-'.substr($hash, 0, 12).'.jpg',
                'mime' => 'image/jpeg', 'size' => 120_000, 'width' => 1200, 'height' => 800]);
            $entry = Entry::query()->create(['type' => 'post', 'status' => $status, 'author_id' => $byWriter ? $writer->id : $owner->id,
                'cover_media_id' => $media->id, 'view_count' => [1240, 860, 0, 0, 35, 410][$i],
                'meta' => $i % 3 === 2 ? [] : ['image_url' => $i % 2 === 0 ? 'images/admin/logo-sm.png' : 'images/admin/logo-light.png'],
                'published_at' => $status === 'scheduled' ? now()->addDays(20) : ($status === 'draft' ? null : now()->subDays($i + 1))]);
            $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            if ($i === 0) {
                $entry->translations()->create(['locale' => 'en', 'title' => 'New precision machining workshop opens', 'slug' => 'new-workshop-opens', 'status' => 'draft', 'body' => ['blocks' => []]]);
            }
            if (($id = $cat($category)) > 0) {
                $entry->taxonomies()->attach($id, ['position' => 0, 'site_id' => 1]);
            }
        }
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content?section=posts')->assertOk()->getContent();
    expect($html)->toContain('Khánh thành nhà xưởng gia công', 'data-role-note', 'Kiến thức › Gia công CNC', 'loading="lazy"', 'data-img="0"');
    file_put_contents($dir.'/post-list.html', $html);

    // The same list's other states for axe: a search that matches nothing («Bỏ lọc»), and the
    // Trash with one post in it (each row tells the days it has left).
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content?section=posts&q=khong-co-bai-nao-khop')->assertOk()->getContent();
    file_put_contents($dir.'/post-list-nomatch.html', $html);
    app(Tenancy::class)->enter(1, fn () => Entry::query()->where('type', 'post')->where('status', 'archived')->firstOrFail()->delete());
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content?section=posts&tab=trash')->assertOk()->getContent();
    expect($html)->toContain('Tham gia Hội chợ Mechanical Expo 2026');
    file_put_contents($dir.'/post-list-trash.html', $html);
    config(['media' => $mediaConfig]);

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
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/catalog/create')->assertOk()->getContent();
    expect($html)->toContain('data-child-screen', 'ped-child-mi')->not->toContain('wire:click="trash"');
    file_put_contents($dir.'/product-new.html', $html);

    // Trình đơn on a selling site: a products section with one category and a header setting, so the
    // phone menu area shows its note about the products tab.
    app(Tenancy::class)->enter(1, function (): void {
        $root = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $root->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham-xem-truoc']);
        ContentSection::query()->updateOrCreate(['key' => 'products'], ['label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $root->id]);
        app(CreateTaxonomy::class)->handle('post_cat', $root->fresh() ?? $root, new TaxonomyData(names: ['vi' => 'Đồ gá']));
        SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['top_bar' => ['items' => []], 'text_size' => false, 'sticky' => true]]);
    });
    // What a request reads once (modules, products section) is read again by this new request.
    app()->forgetInstance(Entitlement::class);
    app()->forgetInstance(ProductIndexLink::class);
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/menus')->assertOk()->getContent();
    expect($html)->toContain('\u0022productsTab\u0022:{\u0022note\u0022:true');
    file_put_contents($dir.'/menus-shop.html', $html);

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
    $swap = function (string $page, string $component, string $name = 'admin\\.catalog\\.index'): string {
        // the list's own root: the div that carries its snapshot, up to the div that closes it
        expect(preg_match('/<div wire:snapshot="[^"]*'.$name.'/', $page, $m, PREG_OFFSET_CAPTURE))->toBe(1);
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

    // The product editor on a selling site: the price card open with a sale running and both labels, a
    // new product's empty card, and the card after a save its sale price stopped (the editor's root put in
    // place of the page's own, as above).
    $priced = app(Tenancy::class)->enter(1, function (): Product {
        $product = Product::query()->create(['status' => 'draft', 'sku' => 'DG-CNC-004', 'price' => 12500000, 'sale_price' => 9900000,
            'sale_starts_at' => '2026-11-01 01:00:00', 'stock' => 3, 'is_featured' => true, 'is_bestseller' => true]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá kẹp phôi CNC 4 trục', 'slug' => 'do-ga-kep-phoi-cnc-4-truc']);

        return $product;
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/catalog/'.$priced->ulid.'/edit')->assertOk()->getContent();
    expect($html)->toContain('Giá &amp; tồn kho', '9.900.000')->not->toContain('Mở khối giá');
    file_put_contents($dir.'/product-editor-price.html', $html);
    $failed = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot(Livewire::actingAs($owner)->test(Editor::class, ['ulid' => $priced->ulid])
        ->set('salePrice', '12.500.000')->call('save')));
    expect($failed)->toContain('Giá khuyến mãi cần thấp hơn giá bán');
    file_put_contents($dir.'/product-editor-price-error.html', $swap($html, $failed, 'admin\\.catalog\\.editor'));
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/catalog/create')->assertOk()->getContent();
    expect($html)->toContain('Giá &amp; tồn kho');
    file_put_contents($dir.'/product-new-price.html', $html);

    // The product editor with three categories (the first the main one) and a strip of three pictures, and a
    // product opened from the Trash (the same product read only is drawn at the end: the plan's member limit).
    [$filed, $binned] = app(Tenancy::class)->enter(1, function () use ($priced): array {
        $priced->assignCategories(array_map(intval(...), Taxonomy::query()->where('vocabulary', 'product_cat')->orderBy('id')->limit(3)->pluck('id')->all()));
        foreach (array_values(Media::query()->where('collection', 'library')->orderBy('id')->limit(3)->pluck('id')->all()) as $i => $id) {
            $priced->images()->create(['media_id' => (int) $id, 'position' => $i]);
        }
        $binned = Product::query()->create(['status' => 'published', 'published_at' => now(), 'sku' => 'DG-CNC-005']);
        $binned->translations()->create(['locale' => 'vi', 'name' => 'Bàn gá phay đa năng', 'slug' => 'ban-ga-phay-da-nang']);
        $binned->delete();

        return [$priced->ulid, $binned->ulid];
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/catalog/'.$filed.'/edit')->assertOk()->getContent();
    expect($html)->toContain('ped-strip', 'Mục chính', 'Đặt làm ảnh đại diện');
    file_put_contents($dir.'/product-editor-full.html', $html);
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/catalog/'.$binned.'/edit')->assertOk()->getContent();
    expect($html)->toContain('data-state="trash"', 'wire:click="restore"');
    file_put_contents($dir.'/product-editor-trash.html', $html);

    // The page editor's «Hẹn giờ đăng» on a scheduled page, then with a changed time that has passed (the
    // reason under the field); and the Home page, whose link is the website's root and locked.
    $planned = app(Tenancy::class)->enter(1, function () use ($owner): array {
        $at = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'author_id' => $owner->id, 'published_at' => now()->addDays(3)->setTime(2, 30)]);
        $at->translations()->create(['locale' => 'vi', 'title' => 'Tuyển dụng', 'slug' => 'tuyen-dung', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $home = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $owner->id]);
        $home->translations()->create(['locale' => 'vi', 'title' => 'Trang chủ', 'slug' => 'home', 'status' => 'draft', 'body' => ['blocks' => []]]);

        return [$at, $home];
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content/'.$planned[0]->ulid.'/edit')->assertOk()->getContent();
    expect($html)->toContain('id="entry-sched-at"', 'data-sched-band', 'data-status-btn');
    file_put_contents($dir.'/editor-scheduled.html', $html);
    $past = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot(Livewire::actingAs($owner)->test(App\Modules\Admin\Livewire\Entries\Editor::class, ['ulid' => $planned[0]->ulid])
        ->set('scheduleAt', now()->subDay()->format('Y-m-d\TH:i'))));
    expect($past)->toContain(e(__('entries.sched_err_past')));
    file_put_contents($dir.'/editor-scheduled-error.html', $swap($html, $past, 'admin\\.entries\\.editor'));
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content/'.$planned[1]->ulid.'/edit')->assertOk()->getContent();
    expect($html)->toContain(e(__('entries.ed.slug_home_hint')));
    file_put_contents($dir.'/editor-home.html', $html);

    // «Lịch sử phiên bản» on a live page with a few versions (published, edited, restored, one older than
    // history): the card's region, the full list, one version beside the form, the question before a
    // restore with unsaved changes, and the question when the restore is refused.
    [$livePage, $oldest] = app(Tenancy::class)->enter(1, function () use ($owner): array {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $owner->id]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu công ty', 'slug' => 'gioi-thieu-cong-ty', 'status' => 'draft', 'excerpt' => 'Về An Vinh',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Gia công cơ khí chính xác']]]]]);
        app(PublishEntry::class)->handle($entry->fresh(), 'vi', $owner);
        $oldest = EntryRevision::query()->where('entry_id', $entry->id)->sole();
        $oldest->forceFill(['created_at' => now()->subDays(6)])->save();
        EntryRevision::query()->create(['entry_id' => $entry->id, 'locale' => 'vi', 'kind' => RevisionKind::Baseline,
            'snapshot' => $oldest->snapshot, 'created_at' => now()->subDays(9)]);
        $translation = $entry->translationFor('vi');
        $translation->update(['title' => 'Về An Vinh', 'excerpt' => 'Hơn 20 năm gia công cơ khí',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Chào mừng đến An Vinh']], ['type' => 'richtext', 'props' => ['heading' => 'Năng lực']]]]]);
        app(RecordEntryRevision::class)->forLocale($entry->fresh(), $translation->fresh(), RevisionKind::Edit, $owner);

        return [$entry->fresh(), $oldest];
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/content/'.$livePage->ulid.'/edit')->assertOk()->getContent();
    $history = fn () => Livewire::actingAs($owner)->test(App\Modules\Admin\Livewire\Entries\Editor::class, ['ulid' => $livePage->ulid])->call('loadRevisions');
    foreach ([
        'editor-revisions' => fn () => $history(),
        'editor-revisions-all' => fn () => $history()->set('revAllOpen', true),
        'editor-revisions-view' => fn () => $history()->call('viewRevision', $oldest->ulid),
        'editor-revisions-ask' => fn () => $history()->set('excerpt', 'Đang gõ dở')->call('askRestore', $oldest->ulid),
    ] as $name => $state) {
        $part = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($state()));
        file_put_contents($dir.'/'.$name.'.html', $swap($html, $part, 'admin\\.entries\\.editor'));
    }
    expect(file_get_contents($dir.'/editor-revisions-ask.html'))->toContain(e(__('entries.rev.ask_dirty')));
    $refused = app(Tenancy::class)->enter(1, function () use ($history, $withSnapshot, $oldest, $livePage): string {
        $test = $history()->call('askRestore', $oldest->ulid);
        $livePage->delete();
        $part = $withSnapshot($test->call('restoreRevision'));
        $livePage->restore();

        return $part;
    });
    expect($refused)->toContain(e(__('entries.revision_err_entry_trashed')));
    file_put_contents($dir.'/editor-revisions-refused.html', $swap($html, $refused, 'admin\\.entries\\.editor'));

    // The members screen with a realistic team: several roles, one member in two of them, a very long name and
    // email, a member at the owner's level (no buttons, says why). Then the roles panel and the invite dialog open.
    $people = app(Tenancy::class)->enter(1, function (): array {
        $made = [];
        foreach ([
            ['Trần Thị Thu Hà', 'ha.tran@congtydemo.vn', ['editor', 'author']],
            ['Lê Văn Đức', 'duc.le@congtydemo.vn', ['admin']],
            ['Phạm Minh Tâm', 'tam.pham@congtydemo.vn', ['viewer']],
            ['Nguyễn Hoàng Bảo Ngọc Phương Thảo Trịnh Thị Minh Nguyệt', 'nguyet.trinh.thi.minh.phong.kinh.doanh@congtydemo.vn', ['contributor']],
            ['Võ Quốc Việt', 'viet.vo@congtydemo.vn', ['owner']],
        ] as [$name, $email, $roles]) {
            $user = User::factory()->create(['name' => $name, 'email' => $email]);
            foreach ($roles as $role) {
                $user->assignRole(Role::findByName($role, PermissionRegistry::GUARD));
            }
            $made[$name] = $user->ulid;
        }

        return $made;
    });
    $this->flushSession();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/users')->assertOk()->getContent();
    expect($html)->toContain('Trần Thị Thu Hà', __('admin.members.why_level'));
    file_put_contents($dir.'/members.html', $html);
    $members = fn () => Livewire::actingAs($owner)->test(MembersIndex::class);
    $panel = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($members()->call('openPanel', $people['Trần Thị Thu Hà'])->set('panelRoles', ['editor'])));
    expect($panel)->toContain(__('admin.members.panel_title', ['name' => 'Trần Thị Thu Hà']));
    file_put_contents($dir.'/members-panel.html', $swap($html, $panel, 'admin\\.users\\.index'));
    $invite = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($members()->call('openInvite')));
    file_put_contents($dir.'/members-invite.html', $swap($html, $invite, 'admin\\.users\\.index'));
    // The ticked members with the bulk bar, then the bulk roles panel for the ones the owner may change.
    $bulk = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($members()
        ->call('togglePick', [$people['Trần Thị Thu Hà'], $people['Phạm Minh Tâm'], $people['Võ Quốc Việt']])->call('openBulkPanel')->set('panelRoles', ['author'])));
    expect($bulk)->toContain(__('admin.members.panel_title_many', ['n' => 2]));
    file_put_contents($dir.'/members-bulk.html', $swap($html, $bulk, 'admin\\.users\\.index'));
    // "Phạm vi làm việc" on both axes: a member limited to a post and a product category, their panel
    // with the product tree open, and the invite dialog with an editor ticked and products on "only"
    // (the plan's feature switched on for the site from here on).
    [$scopePanel, $scopeInvite] = app(Tenancy::class)->enter(1, function () use ($members, $people, $withSnapshot): array {
        $member = (int) User::query()->where('ulid', $people['Trần Thị Thu Hà'])->value('id');
        $post = (int) Taxonomy::query()->where('vocabulary', 'post_cat')->orderBy('id')->value('id');
        $products = Taxonomy::query()->where('vocabulary', 'product_cat')->orderBy('id')->limit(2)->get(['id', 'ulid']);
        foreach ([$post, (int) $products[0]->id] as $taxonomyId) {
            DB::table('member_category_scopes')->insert(['site_id' => 1, 'user_id' => $member, 'taxonomy_id' => $taxonomyId]);
        }
        SiteFeature::query()->updateOrCreate(['feature_key' => 'rbac.category_scopes'], ['value' => true]);
        app(CategoryScopes::class)->forget();

        return [
            $withSnapshot($members()->call('openPanel', $people['Trần Thị Thu Hà'])->set('panelCategories.product_cat', [$products[0]->ulid, $products[1]->ulid])),
            $withSnapshot($members()->call('openInvite')->set('inviteRoles', ['editor'])->set('inviteScope.product_cat', 'only')),
        ];
    });
    file_put_contents($dir.'/members-panel-scope.html', $swap($html, $scopePanel, 'admin\\.users\\.index'));
    file_put_contents($dir.'/members-invite-scope.html', $swap($html, $scopeInvite, 'admin\\.users\\.index'));
    expect($scopePanel)->toContain(__('admin.members.sc_pr_only'))
        ->and($scopeInvite)->toContain(__('admin.members.sc_pr_tree'));

    // The invitations: one waiting with a message, one about to expire, one carrying a role at the
    // owner's level (says why it cannot be re-sent), one withdrawn.
    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $invite = app(InviteMember::class);
        $role = fn (string $name): Role => Role::findByName($name, PermissionRegistry::GUARD);
        $invite->handle($owner, 'tam.ngo@gmail.com', [$role('author')], 'Chị Tâm phụ trách mục Tin tức và Sự kiện giúp em nhé.');
        $invite->handle($owner, 'khanhlinh.tonnu.hoangphuongthao@gmail.com', [$role('viewer')]);
        $invite->handle($owner, 'minh.chau@anvinh.vn', [$role('editor'), $role('author')]);
        $invite->handle($owner, 'dat.vo@gmail.com', [$role('author')]);
        Invitation::query()->where('email', 'khanhlinh.tonnu.hoangphuongthao@gmail.com')->update(['expires_at' => now()->addHours(20), 'sent_at' => now()->subDays(6)]);
        Invitation::query()->where('email', 'dat.vo@gmail.com')->update(['revoked_at' => now()]);
    });
    $this->flushSession();
    $invitations = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/users?xem=loi-moi')->assertOk()->getContent();
    expect($invitations)->toContain('tam.ngo@', __('admin.members.inv_resend_note', ['days' => InviteMember::VALID_DAYS]));
    file_put_contents($dir.'/members-invitations.html', $invitations);

    // The admin's error pages (design board 25): in the frame for a member — no permission, not
    // found, a fault with its reference, too many requests — and on a page of their own: the
    // session that ended, a website that is not open. Faults come from preview-only routes.
    config(['app.debug' => false, 'support.phone' => '0900 000 111', 'support.zalo' => '0900000111']);
    Route::middleware(['web', SetCurrentSite::class, 'site.active', 'auth', 'auth.session', 'tenant.member'])
        ->prefix('admin/_preview-fault')->group(function (): void {
            Route::get('/boom', fn () => throw new RuntimeException('preview fault'));
            Route::get('/busy', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 30]));
        });
    $viewer = User::factory()->create(['name' => 'Lê Văn Xem']);
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'media-viewer', ['media.view']));
    $errors = [
        'error-noperm' => [$viewer, '/admin/content', 403],
        'error-notfound' => [$owner, '/admin/content/01JZZZZZZZZZZZZZZZZZZZZZZZ/edit', 404],
        'error-e500' => [$owner, '/admin/_preview-fault/boom', 500],
        'error-rate' => [$owner, '/admin/_preview-fault/busy', 429],
    ];
    foreach ($errors as $name => [$who, $path, $status]) {
        $this->flushSession(); // a new member each time: their own session, as in a browser
        $html = $this->actingAs($who)->get('http://'.ADMIN_PREVIEW_HOST.$path)->assertStatus($status)->getContent();
        expect($html)->toContain('data-te-page=');
        file_put_contents($dir.'/'.$name.'.html', $html);
    }
    // "Nhờ cấp quyền" pressed: the button reads "Đã gửi yêu cầu" and the green line says who was told
    $this->flushSession();
    $this->actingAs($viewer)->post('http://'.ADMIN_PREVIEW_HOST.'/admin/access-requests', ['screen' => 'admin.settings.general', 'back' => '/admin/settings/general'])->assertStatus(303);
    $html = $this->actingAs($viewer)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/settings/general')->assertForbidden()->getContent();
    expect($html)->toContain('class="te-ok"');
    file_put_contents($dir.'/error-noperm-sent.html', $html);
    // The product editor read only, for a member who may only see products (no new member: the plan's limit).
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'cat-viewer', ['catalog.view']));
    $this->flushSession();
    $html = $this->actingAs($viewer)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/catalog/'.$filed.'/edit')->assertOk()->getContent();
    expect($html)->toContain('data-state="readonly"', 'ped-strip')->not->toContain('wire:click="publish"');
    file_put_contents($dir.'/product-editor-readonly.html', $html);
    app('auth')->forgetGuards();
    $this->flushSession();
    $html = $this->get('http://'.ADMIN_PREVIEW_HOST.'/admin/session-expired')->assertOk()->getContent();
    file_put_contents($dir.'/error-expired.html', $html);
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['status' => 'suspended']);
    $html = $this->get('http://'.ADMIN_PREVIEW_HOST.'/admin/login')->assertForbidden()->getContent();
    expect($html)->toContain('data-te-page="suspended"');
    file_put_contents($dir.'/error-suspended.html', $html);

    // The media library's folder column (design board 12): three folders and a sub-folder, the open one
    // with its ⋮ menu, the same folder being renamed in place, the question asked before deleting a
    // folder that holds files and one that holds a sub-folder, «Thư mục con mới» and «Chuyển thư mục…». The component is put in place of the page's own root, as for the product list.
    $mediaConfig = config('media');
    config(['media.driver' => 'cloudflare', 'media.cloudflare.base' => 'http://'.ADMIN_PREVIEW_HOST]);
    app(Tenancy::class)->enter(1, function (): void {
        DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['status' => 'active']);
        foreach ([['Ảnh xưởng', 'anh-xuong', 4], ['Sản phẩm', 'san-pham', 2], ['Hồ sơ công ty', 'ho-so-cong-ty', 0]] as $i => [$name, $slug, $files]) {
            $folder = MediaFolder::query()->create(['name' => $name, 'slug' => $slug, 'position' => $i + 1]);
            for ($n = 1; $n <= $files; $n++) {
                $hash = hash('sha256', $slug.$n);
                Media::query()->create(['collection' => 'library', 'slug' => $slug.'-'.$n, 'hash' => $hash, 'folder_id' => $folder->id,
                    'original_filename' => $slug.'-'.$n.'.jpg', 'storage_key' => 's/library/'.$slug.'-'.$n.'-'.substr($hash, 0, 12).'.jpg',
                    'mime' => 'image/jpeg', 'size' => 98_000, 'width' => 1200, 'height' => 800]);
            }
        }
        $kid = MediaFolder::query()->create(['name' => 'Bao bì', 'slug' => 'bao-bi', 'position' => 1,
            'parent_id' => MediaFolder::query()->where('slug', 'san-pham')->value('id')]);
        $hash = hash('sha256', 'bao-bi1');
        Media::query()->create(['collection' => 'library', 'slug' => 'bao-bi-1', 'hash' => $hash, 'folder_id' => $kid->id,
            'original_filename' => 'bao-bi-1.jpg', 'storage_key' => 's/library/bao-bi-1-'.substr($hash, 0, 12).'.jpg',
            'mime' => 'image/jpeg', 'size' => 98_000, 'width' => 1200, 'height' => 800]);
    });
    $this->flushSession();
    app('livewire')->flushState();
    $html = $this->actingAs($owner)->get('http://'.ADMIN_PREVIEW_HOST.'/admin/media')->assertOk()->getContent();
    expect($html)->toContain('Ảnh xưởng', 'Hồ sơ công ty');
    file_put_contents($dir.'/media-folders.html', $html);
    $library = fn () => Livewire::actingAs($owner)->test(Library::class)->call('setFolder', 'anh-xuong');
    $media = 'app\\.modules\\.admin\\.livewire\\.media\\.library';
    $open = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($library()));
    expect($open)->toContain("openRename('anh-xuong')");
    file_put_contents($dir.'/media-folder-open.html', $swap($html, $open, $media));
    $rename = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($library()->call('openRename', 'anh-xuong')));
    expect($rename)->toContain('wire:model="renameName"');
    file_put_contents($dir.'/media-folder-rename.html', $swap($html, $rename, $media));
    $ask = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($library()->call('askDeleteFolder', 'anh-xuong')));
    expect($ask)->toContain('4 tệp');
    file_put_contents($dir.'/media-folder-delete.html', $swap($html, $ask, $media));
    $askKids = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($library()->call('askDeleteFolder', 'san-pham')));
    expect($askKids)->toContain('1 thư mục con');
    file_put_contents($dir.'/media-subfolder-delete.html', $swap($html, $askKids, $media));
    $newKid = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($library()->call('openNewFolder', 'anh-xuong')));
    expect($newKid)->toContain('wire:model="newFolderParent"');
    file_put_contents($dir.'/media-subfolder-new.html', $swap($html, $newKid, $media));
    $reparent = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($library()->call('openReparent', 'anh-xuong')));
    expect($reparent)->toContain('wire:model.live="reparentTo"');
    file_put_contents($dir.'/media-folder-reparent.html', $swap($html, $reparent, $media));
    // someone who may delete only the files they uploaded, with one of theirs and someone else's
    // chosen: the Trash button names theirs, a line says why the other stays, and the question
    $author = User::factory()->create(['name' => 'Ngọc Anh']);
    $authorRights = PermissionRegistry::tenantRoleTemplates('corporate')['author']['permissions'];
    [$mineUlid, $theirsUlid] = app(Tenancy::class)->enter(1, function () use ($author, $authorRights): array {
        grantRole($author, 'author', $authorRights === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $authorRights);
        Media::query()->where('slug', 'anh-xuong-1')->update(['created_by' => $author->id]);

        return [(string) Media::query()->where('slug', 'anh-xuong-1')->value('ulid'), (string) Media::query()->where('slug', 'anh-xuong-2')->value('ulid')];
    });
    $author = $author->fresh() ?? $author;
    $own = fn () => Livewire::actingAs($author)->test(Library::class)->call('setFolder', 'anh-xuong')->call('togglePick', $mineUlid)->call('togglePick', $theirsUlid);
    $ownBulk = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($own()));
    expect($ownBulk)->toContain('Chuyển 1 tệp của bạn vào Thùng rác');
    file_put_contents($dir.'/media-trash-own.html', $swap($html, $ownBulk, $media));
    $ownAsk = app(Tenancy::class)->enter(1, fn (): string => $withSnapshot($own()->call('trashPicked')));
    expect($ownAsk)->toContain('giữ nguyên');
    file_put_contents($dir.'/media-trash-own-ask.html', $swap($html, $ownAsk, $media));
    config(['media' => $mediaConfig]);

    expect(glob($dir.'/*.html'))->toHaveCount(count(ADMIN_PREVIEW_SCREENS) + 89);
});
