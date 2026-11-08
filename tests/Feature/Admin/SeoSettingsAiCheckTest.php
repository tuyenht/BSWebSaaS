<?php

use App\Modules\Admin\Livewire\SeoSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Shared\Http\SafeHttp;
use App\Modules\Tenancy\Actions\SetPrimaryDomain;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Jobs\CheckAiCrawlers;
use App\Modules\Theme\Theming\AiCrawlerCheck;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The SEO settings side of the AI crawler check: opening the page starts a check when none is kept
 * for the site's address; "Kiểm tra lại" starts one at most once a minute per site; both need
 * settings.manage; the page reads the kept result in plain words for its status line.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    config(['tenancy.noindex_platform_subdomains' => false]);
    app()->instance(SafeHttp::class, new SafeHttp(static fn (string $host): array => ['93.184.216.34']));
    Http::preventStrayRequests();
    foreach (['seo-ai-check:1', 'seo-ai-check:2', CheckAiCrawlers::LIMITER] as $key) {
        RateLimiter::clear($key);
    }
});

afterEach(function (): void {
    Livewire::flushState();
});

function seoAiAdmin(int $siteId = 1): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

/** a.test answers every request; GPTBot is refused at the door. */
function seoAiFakeRefusal(): void
{
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/robots.txt')) {
            return Http::response("User-agent: *\nDisallow:", 200);
        }

        return Http::response('', str_contains($request->header('User-Agent')[0] ?? '', 'GPTBot') ? 403 : 200);
    });
}

it('starts a check when the page opens without a kept result, and not again while one is queued', function (): void {
    Queue::fake();
    $admin = seoAiAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)->assertOk();
        Livewire::actingAs($admin)->test(SeoSettings::class)->assertOk();
    });

    Queue::assertPushed(CheckAiCrawlers::class, 1);
    Queue::assertPushed(CheckAiCrawlers::class, fn (CheckAiCrawlers $job): bool => $job->siteId === 1 && $job->queue === null);
});

it('does not start a check when a fresh result is kept, and starts one once it is a day old', function (): void {
    seoAiFakeRefusal();
    app(AiCrawlerCheck::class)->run(1);
    Queue::fake();
    $admin = seoAiAdmin();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(SeoSettings::class)->assertOk());
    Queue::assertNothingPushed();

    $this->travel(AiCrawlerCheck::TTL + 1)->seconds();
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(SeoSettings::class)->assertOk());
    Queue::assertPushed(CheckAiCrawlers::class, 1);
});

it('reads the kept result for the status line in plain words, with the time and a way to fix it', function (): void {
    seoAiFakeRefusal();
    $admin = seoAiAdmin();

    // The check the page starts as it opens runs once that request is over (the sync queue here).
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(SeoSettings::class)->assertOk());

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $check = Livewire::actingAs($admin)->test(SeoSettings::class)->viewData('aiCheck');

        expect($check['running'])->toBeFalse()
            ->and($check['level'])->toBe('warning')
            ->and($check['label'])->toBe('Cần xem lại')
            ->and($check['host'])->toBe('a.test')
            ->and($check['checkedAt'])->toMatch('~^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$~')
            ->and($check['findings'])->toHaveCount(1)
            ->and($check['findings'][0]['level'])->toBe('warning')
            ->and($check['findings'][0]['message'])->toBe('Website từ chối bot AI trong khi trình duyệt vẫn mở được: GPTBot (mã 403).')
            ->and($check['findings'][0]['fix'])->toStartWith('Hãy hỏi nơi cho thuê máy chủ');
    });
});

it('says plainly when nothing is in the way, when a check is under way and when none has run', function (): void {
    Http::fake(fn () => Http::response("User-agent: *\nDisallow:", 200));
    $admin = seoAiAdmin();
    $other = seoAiAdmin(2);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(SeoSettings::class)->assertOk());
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $check = Livewire::actingAs($admin)->test(SeoSettings::class)->viewData('aiCheck');
        expect($check['level'])->toBe('ok')
            ->and($check['findings'])->toBe([['level' => 'ok', 'message' => 'Không thấy gì ngăn bot AI đọc website.', 'fix' => null]]);
    });

    Queue::fake();
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)->call('recheck')
            ->assertSet('toast', 'Đang kiểm tra lại. Kết quả hiện ở đây sau ít phút.');
        expect(app(AiCrawlerCheck::class)->isRunning(1))->toBeTrue();
    });

    app(AiCrawlerCheck::class)->markRunning(2);
    app(Tenancy::class)->enter(2, function () use ($other): void {   // the open itself starts nothing new
        $check = Livewire::actingAs($other)->test(SeoSettings::class)->viewData('aiCheck');
        expect($check['level'])->toBeNull()->and($check['running'])->toBeTrue()->and($check['label'])->toBe('Đang kiểm tra bot AI…');
    });
});

it('says the check cannot run while the site has no working address', function (): void {
    Queue::fake();
    DB::connection('pgsql_admin')->table('domains')->where('site_id', 1)->update(['verified_at' => null]);
    $admin = seoAiAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class);
        expect($lw->viewData('aiCheck')['findings'][0]['message'])->toBe('Chưa kiểm được: website chưa có địa chỉ nào hoạt động.');
        $lw->call('recheck')->assertSet('toastTone', 'error');
    });

    Queue::assertNothingPushed();
});

it('lets "Kiểm tra lại" run once a minute per site, and another site is not held up', function (): void {
    Queue::fake();
    $admin = seoAiAdmin();
    $other = seoAiAdmin(2);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class);
        $lw->call('recheck')->assertSet('toastTone', 'success');
        $lw->call('recheck')->assertSet('toastTone', 'error')->assertSee('Bạn vừa kiểm tra. Hãy thử lại sau');
    });
    app(Tenancy::class)->enter(2, fn () => Livewire::actingAs($other)->test(SeoSettings::class)->call('recheck')->assertSet('toastTone', 'success'));

    $this->travel(SeoSettings::RECHECK_WINDOW + 1)->seconds();
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(SeoSettings::class)->call('recheck')->assertSet('toastTone', 'success'));

    // Each open plus each allowed "Kiểm tra lại" queues one check for its own site.
    Queue::assertPushed(CheckAiCrawlers::class, fn (CheckAiCrawlers $job): bool => $job->siteId === 2);
    expect(Queue::pushed(CheckAiCrawlers::class)->filter(fn (CheckAiCrawlers $job): bool => $job->siteId === 1)->count())->toBe(3);
});

it('needs settings.manage to see the result or check again', function (): void {
    Queue::fake();
    $admin = seoAiAdmin();
    $editor = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($editor, 'editor', ['entries.view', 'entries.edit.any']));

    app(Tenancy::class)->enter(1, function () use ($admin, $editor): void {
        Livewire::actingAs($editor->fresh())->test(SeoSettings::class)->assertForbidden();

        $lw = Livewire::actingAs($admin)->test(SeoSettings::class);
        $admin->roles()->firstOrFail()->revokePermissionTo('settings.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        auth()->setUser($admin->fresh());
        $lw->call('recheck')->assertForbidden();
    });

    // Only the allowed open queued a check; the refused calls queued nothing.
    Queue::assertPushed(CheckAiCrawlers::class, 1);
});

it('drops the kept result and checks again once robots.txt or a search switch is saved — not for a title', function (string $field, mixed $value): void {
    seoAiFakeRefusal();
    app(AiCrawlerCheck::class)->run(1);
    Queue::fake();
    $admin = seoAiAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)->set('i18n.vi.title', 'Cơ khí An Vinh — chính xác')->call('save')->assertHasNoErrors();
    });
    expect(app(AiCrawlerCheck::class)->result(1, 'a.test'))->not->toBeNull();
    Queue::assertNothingPushed();

    app(Tenancy::class)->enter(1, function () use ($admin, $field, $value): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)->set($field, $value)->call('save')->assertHasNoErrors();
    });
    expect(app(AiCrawlerCheck::class)->result(1, 'a.test'))->toBeNull();
    Queue::assertPushed(CheckAiCrawlers::class, 1);
})->with([
    'robots.txt' => ['form.robots', "User-agent: GPTBot\nDisallow: /riêng-tư-đ"],
    'Cho Google tìm thấy' => ['form.indexable', false],
    'sitemap' => ['form.sitemap', false],
]);

it('starts afresh when the primary address changes: the kept result is under the old host', function (): void {
    seoAiFakeRefusal();
    DB::connection('pgsql_admin')->table('domains')->insert(['site_id' => 1, 'hostname' => 'anvinh.test', 'kind' => 'custom', 'is_primary' => false, 'verified_at' => now(), 'ssl_status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    app(AiCrawlerCheck::class)->run(1);
    expect(app(AiCrawlerCheck::class)->result(1, 'a.test'))->not->toBeNull();

    app(Tenancy::class)->enter(1, fn () => app(SetPrimaryDomain::class)->handle('anvinh.test'));

    expect(app(SiteResolver::class)->primaryHostFor(1))->toBe('anvinh.test')
        ->and(app(AiCrawlerCheck::class)->result(1, 'anvinh.test'))->toBeNull();
});
