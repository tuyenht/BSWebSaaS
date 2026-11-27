<?php

use App\Modules\Admin\Livewire\Settings\Permalinks;
use App\Modules\Cms\Jobs\ReflowPermalinks;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
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

function psIn(Closure $step, int $site = 1): mixed
{
    return app(Tenancy::class)->enter($site, $step);
}

function psManager(): User
{
    return blockLibraryUser(1, 'admin', ['settings.manage']);
}

/** Published pages with their live routes (inside psIn). */
function psPages(int $from, int $to): void
{
    for ($i = $from; $i <= $to; $i++) {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang '.$i, 'slug' => 'trang-so-'.$i, 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($page->fresh() ?? $page, 'vi', 'trang-so-'.$i);
    }
}

it('shows a member without settings.manage the no-permission card and answers 403 to every action', function (): void {
    $editor = blockLibraryUser(1, 'editor', ['entries.view']);

    $this->actingAs($editor)->get('http://a.test/admin/settings/permalinks')->assertOk()
        ->assertSee('Bạn chưa có quyền sửa cài đặt')->assertDontSee('Khuyên dùng');

    foreach (['save', 'confirmSave', 'cancelConfirm', 'resetChanges', 'retry', 'dismissToast'] as $method) {
        psIn(fn () => Livewire::actingAs($editor)->test(Permalinks::class)->call($method)->assertForbidden());
    }
});

it('stages the drafted format after the confirmation and starts the run', function (): void {
    Queue::fake();
    $manager = psManager();
    psIn(fn () => psPages(1, 3));

    psIn(function () use ($manager): void {
        $screen = Livewire::actingAs($manager)->test(Permalinks::class)
            ->assertSee('Phẳng')->assertSee('Có tiền tố')
            ->set('draft.pages.pattern', 'base')->set('draft.pages.base', 'trang')
            ->assertSee('/trang/trang-so-1')
            ->call('save')->assertSet('confirming', true);
        Queue::assertNothingPushed();
        $screen->call('confirmSave')->assertSet('confirming', false)->assertSet('toastTone', 'success')
            ->assertSee('Đang đổi đường dẫn');
    });

    expect(psIn(fn (): array => app(PermalinkConfig::class)->pending()))->toBe(['pages' => ['pattern' => 'base', 'base' => 'trang']])
        ->and(psIn(fn (): string => app(PermalinkConfig::class)->pattern('pages', 'pages')))->toBe('flat');
    Queue::assertPushed(ReflowPermalinks::class, fn (ReflowPermalinks $job): bool => $job->siteId === 1);
});

it('shows a prefix error in plain words and saves nothing', function (): void {
    Queue::fake();
    $manager = psManager();

    psIn(fn () => Livewire::actingAs($manager)->test(Permalinks::class)
        ->set('draft.pages.pattern', 'base')->set('draft.pages.base', 'Trang Mới')
        ->call('save')->assertSet('confirming', false)
        ->assertSee('Chỉ dùng chữ thường không dấu, số và dấu gạch nối')->assertDontSee('Xem trước'));

    expect(psIn(fn (): array => app(PermalinkConfig::class)->pending()))->toBe([]);
    Queue::assertNothingPushed();
});

it('shows the locked card when the plan may not choose', function (): void {
    $manager = psManager();
    psIn(fn () => SiteFeature::query()->create(['feature_key' => 'permalinks.custom', 'value' => false]));

    psIn(fn () => Livewire::actingAs($manager)->test(Permalinks::class)
        ->assertSee('Tự chọn kiểu đường dẫn')->assertDontSee('Khuyên dùng'));
});

it('renders in a fixed number of queries however many pages the website has', function (): void {
    $manager = psManager();
    // one request of the screen with a drafted change: the cards, their samples and the preview
    $render = fn (): int => psIn(function () use ($manager): int {
        $screen = Livewire::actingAs($manager)->test(Permalinks::class)->set('draft.pages.pattern', 'base');

        return countQueries(fn () => $screen->set('draft.pages.base', 'trang')->assertSee('/trang/trang-so-1'));
    });

    psIn(fn () => psPages(1, 2));
    $render();
    $few = $render();
    psIn(fn () => psPages(3, 30));
    $many = $render();

    // 18 measured: the settings rows, the sections and their roots, the capped counts, one load of the
    // sample items with their routes and categories, the clash lookup, and the frame's menu
    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(18);
});
