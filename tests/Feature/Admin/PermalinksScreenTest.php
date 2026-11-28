<?php

use App\Modules\Admin\Livewire\Settings\Permalinks;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Jobs\ReflowPermalinks;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteModule;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * «Đường dẫn» (Cài đặt › Nội dung): one card per content section with its address formats, a preview
 * of the addresses that change and a confirmation before addresses visitors know move. Saving stages
 * the change and starts the background run; until it is done the screen says so and takes no other
 * change. settings.manage on the page and on every action; a plan that may not choose shows the
 * locked card.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

afterEach(function (): void {
    Livewire::flushState();
});

function plsIn(Closure $step, int $site = 1): mixed
{
    return app(Tenancy::class)->enter($site, $step);
}

function plsManager(): User
{
    return blockLibraryUser(1, 'admin', ['settings.manage']);
}

/** Published pages with their live routes (inside psIn). */
function plsPages(int $from, int $to): void
{
    for ($i = $from; $i <= $to; $i++) {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang '.$i, 'slug' => 'trang-so-'.$i, 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($page->fresh() ?? $page, 'vi', 'trang-so-'.$i);
    }
}

/** A published page under $parent with its live route (inside psIn). */
function plsPage(string $slug, ?Entry $parent = null): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'parent_id' => $parent?->id]);
    $page->translations()->create(['locale' => 'vi', 'title' => $slug, 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);
    app(PermalinkConfig::class)->flush();
    app(RouteSync::class)->sync($page->fresh() ?? $page, 'vi', $slug);

    return $page;
}

/** A post section covering a fresh root category, with $posts published posts (inside psIn). */
function plsPostSection(string $key, int $posts): void
{
    $root = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $root->translations()->create(['locale' => 'vi', 'name' => $key, 'slug' => $key]);
    ContentSection::query()->create([ // its root category is filed under it (content_section_categories) as it is made
        'key' => $key, 'label' => ucfirst($key), 'entry_type' => 'post',
        'taxonomy_id' => $root->id, 'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    for ($i = 1; $i <= $posts; $i++) {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'published']);
        $post->translations()->create(['locale' => 'vi', 'title' => $key.' '.$i, 'slug' => $key.'-bai-'.$i, 'status' => 'published', 'body' => ['blocks' => []]]);
        $post->assignCategories([$root->id]);
        app(RouteSync::class)->sync($post->fresh() ?? $post, 'vi', $key.'-bai-'.$i);
    }
}

/** Run the website's permalink run to its end, as workers would. */
function plsRunAll(): void
{
    $guard = 0;
    do {
        (new UniqueLock(app(Cache::class)))->release(new ReflowPermalinks(1));
        $done = (bool) app()->call([new ReflowPermalinks(1), 'handle']);
    } while (! $done && $guard++ < 20);
}

/** @return array<string, int> the website's routes path => entry id, read past RLS */
function plsRoutes(): array
{
    return DB::connection('pgsql_admin')->table('routes')->where('site_id', 1)->whereNotNull('entry_id')->orderBy('path')
        ->pluck('entry_id', 'path')->map(fn (mixed $id): int => (int) $id)->all();
}

it('shows a member without settings.manage the no-permission card and answers 403 to every action', function (): void {
    $editor = blockLibraryUser(1, 'editor', ['entries.view']);

    $this->actingAs($editor)->get('http://a.test/admin/settings/permalinks')->assertOk()
        ->assertSee('Bạn chưa có quyền sửa cài đặt')->assertDontSee('Khuyên dùng');

    foreach (['save', 'confirmSave', 'cancelConfirm', 'resetChanges', 'retry', 'retryFailed', 'dismissFailed', 'dismissToast'] as $method) {
        plsIn(fn () => Livewire::actingAs($editor)->test(Permalinks::class)->call($method)->assertForbidden());
    }
});

it('stages the drafted format after the confirmation and starts the run', function (): void {
    Queue::fake();
    $manager = plsManager();
    plsIn(fn () => plsPages(1, 3));

    plsIn(function () use ($manager): void {
        $screen = Livewire::actingAs($manager)->test(Permalinks::class)
            ->assertSee('Phẳng')->assertSee('Có tiền tố')
            ->set('draft.pages.pattern', 'base')->set('draft.pages.base', 'trang')
            ->assertSee('/trang/trang-so-1')
            ->call('save')->assertSet('confirming', true);
        Queue::assertNothingPushed();
        $screen->call('confirmSave')->assertSet('confirming', false)->assertSet('toastTone', 'success')
            ->assertSee('Đang đổi đường dẫn');
    });

    expect(plsIn(fn (): array => app(PermalinkConfig::class)->pending()))->toBe(['pages' => ['pattern' => 'base', 'base' => 'trang']])
        ->and(plsIn(fn (): string => app(PermalinkConfig::class)->pattern('pages', 'pages')))->toBe('flat');
    Queue::assertPushed(ReflowPermalinks::class, fn (ReflowPermalinks $job): bool => $job->siteId === 1);
});

it('shows a prefix error in plain words and saves nothing', function (): void {
    Queue::fake();
    $manager = plsManager();

    plsIn(fn () => Livewire::actingAs($manager)->test(Permalinks::class)
        ->set('draft.pages.pattern', 'base')->set('draft.pages.base', 'Trang Mới')
        ->call('save')->assertSet('confirming', false)
        ->assertSee('Chỉ dùng chữ thường không dấu, số và dấu gạch nối')->assertDontSee('Xem trước'));

    expect(plsIn(fn (): array => app(PermalinkConfig::class)->pending()))->toBe([]);
    Queue::assertNothingPushed();
});

it('shows the locked card when the plan may not choose', function (): void {
    $manager = plsManager();
    plsIn(fn () => SiteFeature::query()->create(['feature_key' => 'permalinks.custom', 'value' => false]));

    plsIn(fn () => Livewire::actingAs($manager)->test(Permalinks::class)
        ->assertSee('Tự chọn kiểu đường dẫn')->assertDontSee('Khuyên dùng'));
});

it('renders in a fixed number of queries however many pages the website has', function (): void {
    $manager = plsManager();
    // one request of the screen with a drafted change: the cards, their samples and the preview
    $render = fn (): int => plsIn(function () use ($manager): int {
        $screen = Livewire::actingAs($manager)->test(Permalinks::class)->set('draft.pages.pattern', 'base');

        return countQueries(fn () => $screen->set('draft.pages.base', 'trang')->assertSee('/trang/trang-so-1'));
    });

    plsIn(fn () => plsPages(1, 2));
    $render();
    $few = $render();
    plsIn(fn () => plsPages(3, 30));
    $many = $render();

    // 18 measured: the settings rows, the sections and their roots, the capped counts, one load of the
    // sample items with their routes and categories, the clash lookup, and the frame's menu
    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(18);
});

it('asks before nesting pages even when the first pages of the preview stay where they are', function (): void {
    Queue::fake();
    $manager = plsManager();
    // the preview's sample is the oldest pages, all top-level: only the newer sub-pages move
    [$parent, $child, $grandchild] = plsIn(function (): array {
        plsPages(1, 24);
        $parent = Entry::query()->whereHas('translations', fn ($query) => $query->where('slug', 'trang-so-1'))->firstOrFail();
        $child = plsPage('doi-ngu', $parent);

        return [$parent, $child, plsPage('ky-thuat', $child)];
    });

    plsIn(fn () => Livewire::actingAs($manager)->test(Permalinks::class)
        ->set('draft.pages.pattern', 'nested')
        ->call('save')->assertSet('confirming', true)
        ->assertSee('Phần xem trước chỉ xét một số mục đầu tiên')
        ->call('confirmSave')->assertSet('confirming', false)->assertSee('Đang đổi đường dẫn'));
    plsRunAll();

    expect(plsRoutes())->toMatchArray([
        '/trang-so-1' => $parent->id,
        '/trang-so-1/doi-ngu' => $child->id,
        '/trang-so-1/doi-ngu/ky-thuat' => $grandchild->id,
    ])->and(plsRoutes())->toHaveKeys(['/trang-so-2', '/trang-so-24'])
        ->and(plsRoutes())->not->toHaveKeys(['/doi-ngu', '/ky-thuat']);
    $this->get('http://a.test/ky-thuat')->assertStatus(301)->assertRedirect('http://a.test/trang-so-1/doi-ngu/ky-thuat');
});

it('asks before nesting pages when one address of a large sample in three languages moves', function (): void {
    Queue::fake();
    $manager = plsManager();
    foreach (['en', 'ja'] as $locale) {
        DB::connection('pgsql_admin')->table('site_locales')->insert(
            ['site_id' => 1, 'locale' => $locale, 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()],
        );
    }
    app()->forgetInstance(SiteLocale::class);
    app()->forgetScopedInstances();
    // 25 pages, the oldest 20 in the preview: 19 top-level pages in three languages (57 addresses)
    // and one sub-page in Vietnamese only, the one address of the 58 that nesting moves
    plsIn(function (): void {
        $first = null;
        for ($i = 1; $i <= 25; $i++) {
            if ($i === 20) {
                plsPage('trang-con', $first);

                continue;
            }
            $page = plsPage('trang-so-'.$i);
            $first ??= $page;
            foreach (['en', 'ja'] as $locale) {
                $page->translations()->create(['locale' => $locale, 'title' => 'Page '.$i, 'slug' => $locale.'-page-'.$i, 'status' => 'published', 'body' => ['blocks' => []]]);
                app(RouteSync::class)->sync($page->fresh() ?? $page, $locale, $locale.'-page-'.$i);
            }
        }
    });

    plsIn(fn () => Livewire::actingAs($manager)->test(Permalinks::class)
        ->set('draft.pages.pattern', 'nested')
        ->call('save')->assertSet('confirming', true)
        ->call('confirmSave')->assertSet('confirming', false));
    plsRunAll();

    expect(plsRoutes())->toHaveKeys(['/trang-so-1/trang-con', '/trang-so-21', '/en-page-1']);
});

it('shows and moves the addresses of a second language under its prefix', function (): void {
    Queue::fake();
    $manager = plsManager();
    DB::connection('pgsql_admin')->table('site_locales')->insert(
        ['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()],
    );
    app()->forgetInstance(SiteLocale::class);
    app()->forgetScopedInstances();
    $page = plsIn(function (): Entry {
        $page = plsPage('gioi-thieu');
        $page->translations()->create(['locale' => 'en', 'title' => 'About us', 'slug' => 'about-us', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($page->fresh() ?? $page, 'en', 'about-us');

        return $page;
    });

    plsIn(fn () => Livewire::actingAs($manager)->test(Permalinks::class)
        ->set('draft.pages.pattern', 'base')->set('draft.pages.base', 'trang')
        ->assertSee('/trang/gioi-thieu')->assertSee('/en/about-us')->assertSee('/en/trang/about-us')
        ->call('save')->assertSet('confirming', true)->call('confirmSave'));
    plsRunAll();

    $en = DB::connection('pgsql_admin')->table('routes')->where('site_id', 1)->where('entry_id', $page->id)->pluck('path', 'locale')->all();
    expect($en)->toBe(['vi' => '/trang/gioi-thieu', 'en' => '/trang/about-us'])
        ->and(DB::connection('pgsql_admin')->table('redirects')->where('site_id', 1)->where('locale', 'en')->pluck('to_path', 'from_path')->all())
        ->toBe(['/about-us' => '/trang/about-us']);
});

it('lists the addresses a run could not move, says what to do, and retries or forgets them', function (): void {
    Queue::fake();
    $manager = plsManager();
    $long = str_repeat('b', 250);
    plsIn(function () use ($long): void {
        plsPage('gioi-thieu');
        plsPage($long);
        app(PermalinkConfig::class)->stage(['pages' => ['pattern' => 'base', 'base' => 'trang']], now()->utc()->toIso8601String());
    });
    plsRunAll();

    $screen = plsIn(fn () => Livewire::actingAs($manager)->test(Permalinks::class)
        ->assertSee('1 địa chỉ chưa đổi được')->assertSee('dài quá 255 ký tự')->assertSee('Vẫn ở')->assertSee('/'.$long)
        ->assertDontSee('Đang đổi đường dẫn'));

    // «Thử lại» walks the section again (still too long: listed again); «Đã hiểu» forgets the list
    plsIn(fn () => $screen->call('retryFailed')->assertSee('Đang đổi đường dẫn'));
    Queue::assertPushed(ReflowPermalinks::class);
    plsRunAll();
    plsIn(fn () => $screen->call('retry')->assertSee('1 địa chỉ chưa đổi được')
        ->call('dismissFailed')->assertDontSee('chưa đổi được'));
    expect(plsIn(fn (): ?array => app(PermalinkConfig::class)->run()))->toBeNull();
});

it('renders in the same number of queries however many post sections the website has', function (): void {
    $manager = plsManager();
    $render = fn (): int => plsIn(function () use ($manager): int {
        $screen = Livewire::actingAs($manager)->test(Permalinks::class)->set('draft.pages.pattern', 'base');

        return countQueries(fn () => $screen->set('draft.pages.base', 'trang')->assertSee('/trang/trang-so-1'));
    });
    plsIn(function (): void {
        SiteModule::query()->create(['module_key' => 'catalog', 'enabled' => true]);
        $machines = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $machines->translations()->create(['locale' => 'vi', 'name' => 'May', 'slug' => 'may']);
        $product = Product::query()->create(['status' => 'published', 'sku' => 'SKU-1', 'price' => 1000000, 'published_at' => now()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'May CNC', 'slug' => 'may-cnc']);
        $product->assignCategories([$machines->id]);
        app(RouteSync::class)->syncProduct($product->id, $machines, 'vi', 'may-cnc');
        plsPages(1, 2);
        plsPostSection('tin-tuc', 2);
    });
    app()->forgetScopedInstances();
    $render();
    $one = $render();
    plsIn(function (): void {
        plsPostSection('du-an', 3);
        plsPostSection('tuyen-dung', 2);
    });
    app()->forgetScopedInstances();
    $render();
    $three = $render();

    // 26 measured: the 18 of a pages-only website, plus the catalog's card (its entitlement, count,
    // sample and items) — one read covers the count and sample of every post section
    expect($three)->toBe($one)->and($three)->toBeLessThanOrEqual(26);
});
