<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Support\Facades\View;
use PragmaRX\Google2FA\Google2FA;

/*
 * Query Budget guardrail (CLAUDE.md §6): assert a hard ceiling of DB queries per
 * hot route so N+1 regressions fail CI. Tighten as the render path matures (cache,
 * eager-load); for now it bounds the resolve + render of one page.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'An Vinh');
});

it('renders the storefront home within its query budget', function (): void {
    $budget = 11; // + nav: one assignment lookup (miss → empty menu) on top of the page resolve

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk());

    expect($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with the site’s search settings within the same budget', function (): void {
    $budget = 11; // the search settings ride the one site-settings query the page already makes
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'seo', 'key' => 'config', 'value' => [
        'titles' => ['vi' => 'Mặc định'], 'descriptions' => ['vi' => 'Mô tả'], 'template' => '%tieu-de% | %ten-website%', 'indexable' => false,
    ]]));

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk()->assertSee('noindex, nofollow', false));

    expect($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with its brand images set within the budget plus one', function (): void {
    // The four brand images resolve in ONE media query (Storefront BrandImages); with none set the
    // home stays at its own budget (the first test), so this is the home budget + 1.
    $budget = 12;
    $keys = app(Tenancy::class)->enter(1, function (): array {
        $keys = [];
        foreach (['logo', 'logo-dark', 'favicon', 'share'] as $slug) {
            $hash = hash('sha256', $slug);
            $keys[] = Media::query()->create([
                'collection' => 'brand', 'slug' => $slug, 'hash' => $hash, 'storage_key' => "s/brand/{$slug}-".substr($hash, 0, 12).'.png',
                'mime' => 'image/png', 'size' => 1_000, 'width' => 320, 'height' => 80,
            ])->id;
        }
        SiteProfile::query()->create(['brand_name' => 'An Vinh', 'logo_media_id' => $keys[0], 'logo_dark_media_id' => $keys[1], 'favicon_media_id' => $keys[2], 'og_image_media_id' => $keys[3]]);

        return $keys;
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('s/brand/favicon-')->toContain('s/brand/share-')
        ->and($keys)->toHaveCount(4)
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with only the older brand URLs within the home budget', function (): void {
    $budget = 11; // no media id set: the brand images cost no query
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => [
        'name' => 'An Vinh', 'logo' => 'https://files.baoson.net/s/brand/logo.png', 'favicon' => 'https://files.baoson.net/s/brand/logo.png?w=64',
    ]]));

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk()->assertSee('s/brand/logo.png?w=64', false));

    expect($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with the phone bar and its “Mua hàng” button within its query budget', function (): void {
    // The home budget plus finding the product list once for the page: the site's modules (2), the
    // products section, a published product and the list's address. The bar's own setting shares
    // the one settings read the home already makes.
    $budget = 16;

    app(Tenancy::class)->enter(1, function (): void {
        SiteModule::query()->create(['module_key' => 'catalog', 'enabled' => true]);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham']);
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'san-pham');
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm 1', 'slug' => 'sp-1']);
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
            'enabled' => true, 'theme' => 'horizontal', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24,
            'buy' => ['enabled' => true, 'color' => '#0ab39c'],
            'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678'], ['key' => 'zalo', 'on' => true, 'value' => '0912345678']],
        ]]);
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('tel:0912345678')->toContain('href="/san-pham"')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with contact buttons drawn by their own pictures within the budget plus one', function (): void {
    // Every channel's own icon picture resolves in ONE media query (Storefront ContactIconImages);
    // with none set the floating contact adds no query, so this is the home budget + 1.
    $budget = 12;
    $keys = app(Tenancy::class)->enter(1, function (): array {
        $pictures = [];
        foreach (['goi', 'zalo'] as $slug) {
            $hash = hash('sha256', 'icon-'.$slug);
            $pictures[$slug] = Media::query()->create([
                'collection' => 'library', 'slug' => $slug, 'hash' => $hash, 'storage_key' => "s/library/{$slug}-".substr($hash, 0, 12).'.png',
                'mime' => 'image/png', 'size' => 2_000, 'width' => 64, 'height' => 64,
            ]);
        }
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
            'enabled' => true, 'theme' => 'horizontal', 'position' => 'right',
            'channels' => [
                ['key' => 'phone', 'on' => true, 'value' => '0912345678', 'img' => $pictures['goi']->ulid],
                ['key' => 'zalo', 'on' => true, 'value' => '0912345678', 'img' => $pictures['zalo']->ulid],
            ],
        ]]);

        return array_map(static fn (Media $m): string => e(app(MediaUrlResolver::class)->url($m->storage_key)), $pictures);
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('sf-fc__img')->toContain($keys['goi'])->toContain($keys['zalo'])
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with the full chrome within its query budget', function (): void {
    // The home budget plus the three reads any menu costs (items, their labels, link targets).
    // Tokens, header and footer settings share the one settings read the home already makes,
    // and the footer menu rides in the same resolve as the primary one — no query of its own.
    $budget = 14;

    app(Tenancy::class)->enter(1, function (): void {
        foreach ([
            ['theme', 'tokens', ['primary' => '#f59e0b', 'ink' => '#0f172a', 'font' => 'Inter, sans-serif']],
            ['storefront', 'header', ['top_bar' => ['items' => [['kind' => 'phone', 'text' => '0901 234 567', 'value' => '0901234567']]], 'text_size' => true]],
            ['storefront', 'footer', ['about' => 'Giới thiệu.', 'factories' => [['title' => 'Nhà máy 1', 'map_url' => 'https://maps.google.com/?q=1']]]],
        ] as [$group, $key, $value]) {
            SiteSetting::query()->create(['group_name' => $group, 'key' => $key, 'value' => $value]);
        }

        $home = Entry::query()->firstOrFail();
        foreach (['primary' => 'Chính', 'footer' => 'Chân trang'] as $region => $name) {
            $menu = Menu::query()->create(['name' => $name, 'handle' => $region]);
            $parent = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '#', 'position' => 0]);
            $parent->translations()->create(['locale' => 'vi', 'label' => 'Nhóm']);
            foreach (range(1, 3) as $position) {
                $item = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $parent->id, 'link_type' => 'entry',
                    'routable_type' => 'entry', 'routable_id' => $home->id, 'position' => $position]);
                $item->translations()->create(['locale' => 'vi', 'label' => "Mục {$position}"]);
            }
            MenuAssignment::query()->create(['region_key' => $region, 'locale' => 'vi', 'menu_id' => $menu->id]);
        }
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('sf-header')->toContain('sf-footer')->toContain('Mục 3')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the site’s 404 page with the full chrome within its query budget', function (): void {
    // The page resolve misses (the route, then the redirect check), then the same chrome as the
    // home: one settings read, the brand, the menus' three reads. The contact page costs nothing
    // while the site names none, and the suggested links come from the menu already loaded.
    $budget = 13;

    app(Tenancy::class)->enter(1, function (): void {
        foreach ([
            ['storefront', 'header', ['top_bar' => ['items' => [['kind' => 'phone', 'text' => '0901 234 567', 'value' => '0901234567']]], 'text_size' => true]],
            ['storefront', 'footer', ['about' => 'Giới thiệu.']],
        ] as [$group, $key, $value]) {
            SiteSetting::query()->create(['group_name' => $group, 'key' => $key, 'value' => $value]);
        }

        $home = Entry::query()->firstOrFail();
        foreach (['primary' => 'Chính', 'footer' => 'Chân trang'] as $region => $name) {
            $menu = Menu::query()->create(['name' => $name, 'handle' => $region]);
            foreach (range(1, 3) as $position) {
                $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'entry',
                    'routable_type' => 'entry', 'routable_id' => $home->id, 'position' => $position]);
                $item->translations()->create(['locale' => 'vi', 'label' => "Mục {$position}"]);
            }
            MenuAssignment::query()->create(['region_key' => $region, 'locale' => 'vi', 'menu_id' => $menu->id]);
        }
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();
    });

    expect($html)->toContain('sf-404')->toContain('sf-header')->toContain('sf-footer')->toContain('Mục 3')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the site’s 404 page within the same query budget when the site names a contact page', function (): void {
    // The contact page is one more read of the site's settings plus its route lookup.
    $budget = 15;

    app(Tenancy::class)->enter(1, function (): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he', 'entry_id' => $page->id]);
        SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'contact_page', 'value' => ['entry' => $page->ulid]]);
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();
    });

    expect($html)->toContain('sf-404__btn--contact')->toContain('href="/lien-he"')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders a stats block of plain numbers with no query of its own, and a years item with the one settings read', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $numbers = fn () => View::make('theme::sections.stats', ['props' => ['items' => [['value' => 3, 'label' => 'Nhà máy'], ['value' => 4300, 'label' => 'Nhà xưởng']]]])->render();
        $years = fn () => View::make('theme::sections.stats', ['props' => ['items' => [['kind' => 'years', 'value' => 2014, 'label' => 'Năm'], ['kind' => 'year', 'value' => 2019, 'label' => 'Thành lập']]]])->render();
        $side = fn () => View::make('theme::sections.cta', ['props' => ['side' => 'stats', 'side_items' => [['value' => 3, 'label' => 'Nhà máy']]]])->render();

        expect(countQueries($numbers))->toBe(0)
            ->and(countQueries($side))->toBe(0)
            ->and(countQueries($years))->toBe(1)
            ->and(countQueries($years))->toBe(0);
    });
});

it('renders the admin "Tổng quan" with every card on a flat query budget', function (): void {
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    // A writer who also reviews and runs the website: every card of the screen is on.
    $member = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        grantRole($member, 'everything', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit', 'entries.review', 'entries.approve',
            'settings.manage', 'forms.view', 'domains.manage', 'media.upload', 'menus.manage']);
        SiteModule::query()->create(['module_key' => 'contacts', 'enabled' => true]);

        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        // Enough rows of every kind that a query per row would blow the ceiling.
        $other = User::factory()->create();
        grantRole($other, 'reviewer', ['entries.view', 'entries.edit.own', 'entries.submit', 'entries.review', 'entries.approve']);
        foreach (range(1, 12) as $i) {
            $author = $i % 2 ? $member : $other;
            $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
            $post->translations()->create(['locale' => 'vi', 'title' => "Bài {$i}", 'slug' => "bai-{$i}", 'status' => 'draft', 'body' => ['blocks' => []]]);
            $post->assignCategories([$category->id]);
            if ($i % 3 !== 0) {
                app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $author->fresh(), 'submit');
            }
            if ($i % 4 === 1 && $i % 3 !== 0) {
                app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $other->fresh(), 'reject', "Lý do {$i}");
            }
        }
        $form = Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'status' => 'active']);
        foreach (range(1, 12) as $i) {
            FormSubmission::query()->create(['form_id' => $form->id, 'data' => ['message' => 'x'], 'name' => "Khách {$i}", 'status' => 'new']);
        }
    });

    $this->actingAs($member)->get('http://a.test/admin')->assertOk(); // the permission and settings caches fill on the first request
    $html = '';
    $count = countQueries(function () use ($member, &$html): void {
        $html = $this->actingAs($member)->get('http://a.test/admin')->assertOk()->getContent();
    });

    expect($html)->toContain(__('dash.rev_title'))->toContain(__('dash.wr_title'))->toContain(__('dash.wr_returned_title'))
        ->toContain(__('dash.start_title'))->toContain(__('dash.todo_leads', ['n' => 12]))
        // The shell (tenancy, nav, entitlement) and one bounded read per card: the newest unread
        // leads, the domains, the first steps, the review queue (with its rows' relations), the
        // member's own posts counted up to 99+ in one statement, and five recent pages and posts.
        // Every list is capped, so the count does not grow with the website's size.
        ->and($count)->toBeLessThanOrEqual(41);
});

it('keeps the admin "Tổng quan" flat however many sub-categories its rows are filed under', function (): void {
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $member = User::factory()->create();
    $author = User::factory()->create();

    // Posts filed under sub-categories of the section's category: each row's section is found
    // by going up the category tree, which must not cost a query per category on the page.
    $fileUnder = function (int $count, int $from) use ($author): void {
        app(Tenancy::class)->enter(1, function () use ($count, $from, $author): void {
            $root = Taxonomy::query()->where('vocabulary', 'post_cat')->whereNull('parent_id')->firstOrFail();
            foreach (range($from, $from + $count - 1) as $i) {
                $sub = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $root->id, 'position' => $i]);
                $sub->translations()->create(['locale' => 'vi', 'name' => "Mục {$i}", 'slug' => "muc-{$i}"]);
                app(TaxonomyPaths::class)->refreshBranch($sub->id);
                $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
                $post->translations()->create(['locale' => 'vi', 'title' => "Bài mục {$i}", 'slug' => "bai-muc-{$i}", 'status' => 'draft', 'body' => ['blocks' => []]]);
                $post->assignCategories([$sub->id]);
                app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $author->fresh(), 'submit');
            }
        });
    };

    app(Tenancy::class)->enter(1, function () use ($member, $author): void {
        grantRole($member, 'reviewer', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.review', 'entries.approve']);
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        app(TaxonomyPaths::class)->refreshBranch($category->id);
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
    });

    $measure = function () use ($member): int {
        $this->actingAs($member)->get('http://a.test/admin')->assertOk(); // caches fill on the first request
        app()->forgetScopedInstances(); // a new request: the per-request memos (sections among them) start empty

        return countQueries(fn () => $this->actingAs($member)->get('http://a.test/admin')->assertOk()->assertSee(__('dash.rev_title')));
    };

    $fileUnder(1, 1);
    $one = $measure();
    $fileUnder(29, 2);
    $thirty = $measure();

    expect($thirty)->toBe($one);
});

it('renders the member\'s own account page within its query budget', function (): void {
    // Measured at 17 with two-step sign-in on, one linked account and every notification group
    // shown (16 + the top bar bell's unread count, one read on every admin page): the linked
    // accounts are one read and the notification rows add none per kind.
    $budget = 17;
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view', 'entries.review', 'forms.view', 'domains.manage']));
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));
    SocialIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'sub' => 'g-'.$user->ulid, 'email' => $user->email]);
    $user = $user->fresh();

    $this->actingAs($user)->get('http://a.test/admin/account')->assertOk(); // warm the caches a signed-in member has
    $count = countQueries(fn () => $this->actingAs($user)->get('http://a.test/admin/account?tab=prefs')->assertOk());

    expect($count)->toBeLessThanOrEqual($budget);
});
