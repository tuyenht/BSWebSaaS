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
use App\Modules\Theme\Theming\RobotsTxt;
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
            ->and($check['label'])->toBe('Cảnh báo')
            ->and($check['host'])->toBe('a.test')
            ->and($check['checkedAt'])->toMatch('~^\d{2}:\d{2}, \d{2}/\d{2}/\d{4}$~')
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
            ->and($check['findings'])->toBe([['level' => 'ok', 'code' => 'all_clear', 'message' => 'Các bot AI đọc được website của bạn.', 'fix' => null]]);
    });

    Queue::fake();
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)->call('recheck')
            ->assertSet('toast', '');   // the card itself turns to "Đang kiểm"
        expect(app(AiCrawlerCheck::class)->isRunning(1))->toBeTrue();
    });

    app(AiCrawlerCheck::class)->markRunning(2);
    app(Tenancy::class)->enter(2, function () use ($other): void {   // the open itself starts nothing new
        $check = Livewire::actingAs($other)->test(SeoSettings::class)->viewData('aiCheck');
        expect($check['level'])->toBeNull()->and($check['running'])->toBeTrue()->and($check['label'])->toBe('Đang kiểm');
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

    $check = app(AiCrawlerCheck::class);   // the queue is faked: each check is ended by hand

    app(Tenancy::class)->enter(1, function () use ($admin, $check): void {
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class);
        $check->clearRunning(1);
        $lw->call('recheck')->assertSet('toast', '');
        expect($check->isRunning(1))->toBeTrue();
        $check->clearRunning(1);
        $lw->call('recheck')->assertSet('toastTone', 'info')->assertSet('toast', fn (string $toast): bool => preg_match('~^Mỗi phút chỉ kiểm được 1 lần\. Bấm lại được từ \d{2}:\d{2}\.$~', $toast) === 1);
        expect($check->isRunning(1))->toBeFalse();
    });
    app(Tenancy::class)->enter(2, function () use ($other, $check): void {
        $lw = Livewire::actingAs($other)->test(SeoSettings::class);
        $check->clearRunning(2);
        $lw->call('recheck')->assertSet('toast', '');
        expect($check->isRunning(2))->toBeTrue();
    });

    $this->travel(SeoSettings::RECHECK_WINDOW + 1)->seconds();
    app(Tenancy::class)->enter(1, function () use ($admin, $check): void {
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class);
        $check->clearRunning(1);
        $lw->call('recheck')->assertSet('toast', '');
        expect($check->isRunning(1))->toBeTrue();
    });

    // Each open plus each allowed "Kiểm tra lại" queues one check for its own site.
    expect(Queue::pushed(CheckAiCrawlers::class)->filter(fn (CheckAiCrawlers $job): bool => $job->siteId === 2)->count())->toBe(2)
        ->and(Queue::pushed(CheckAiCrawlers::class)->filter(fn (CheckAiCrawlers $job): bool => $job->siteId === 1)->count())->toBe(4);
});

it('does nothing when "Kiểm tra lại" is pressed while a check is under way, and keeps the minute', function (): void {
    Queue::fake();
    $admin = seoAiAdmin();
    app(AiCrawlerCheck::class)->markRunning(1);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SeoSettings::class)->call('recheck')->assertSet('toast', '');
    });

    Queue::assertNothingPushed();
    expect(app(AiCrawlerCheck::class)->isRunning(1))->toBeTrue()
        ->and(RateLimiter::attempts('seo-ai-check:1'))->toBe(0);
});

it('drops the "checking" mark when the request that queued the check rolls back', function (): void {
    Queue::fake();
    $admin = seoAiAdmin();

    try {
        app(Tenancy::class)->enter(1, function () use ($admin): void {
            Livewire::actingAs($admin)->test(SeoSettings::class)->assertOk();
            expect(app(AiCrawlerCheck::class)->isRunning(1))->toBeTrue();

            throw new RuntimeException('a later step of the same request failed');
        });
    } catch (RuntimeException) {
    }

    expect(app(AiCrawlerCheck::class)->isRunning(1))->toBeFalse();
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

/*
 * The card "Bot AI (ChatGPT, Claude, Perplexity và các bot khác)" in "Cho Google tìm thấy", and the
 * note under the robots.txt box: what each state reads, the button while it waits, and the asking
 * again that runs only while a check does.
 */

/** a.test answers as given: robots.txt, the bots' answers, headers on every answer. */
function seoAiFakeSite(string $robots = "User-agent: *\nDisallow:\nSitemap: https://a.test/sitemap.xml", array $bots = [], array $all = []): void
{
    $names = array_flip((array) config('seo.ai_crawlers.probes'));
    Http::fake(function (Request $request) use ($robots, $bots, $all, $names) {
        if (str_ends_with($request->url(), '/robots.txt')) {
            return Http::response($robots, 200, $all);
        }
        $bot = $names[$request->header('User-Agent')[0] ?? ''] ?? null;

        return Http::response('<!doctype html><title>Trang chủ</title>', $bot !== null ? ($bots[$bot] ?? 200) : 200, $all);
    });
}

/** The page as the owner sees it after $steps (a function of the Livewire test), in site 1. */
function seoAiPage(User $admin, ?Closure $steps = null): string
{
    return app(Tenancy::class)->enter(1, function () use ($admin, $steps): string {
        $lw = Livewire::actingAs($admin)->test(SeoSettings::class);
        if ($steps !== null) {
            $steps($lw);
        }

        return $lw->html();
    });
}

/** Just the card: from its root to the robots.txt group. */
function seoAiCardHtml(string $page): string
{
    $start = strpos($page, 'data-ai-check');
    expect($start)->not->toBeFalse();

    return substr($page, (int) $start, (int) strpos($page, 'seo-g-advanced', (int) $start) - (int) $start);
}

it('says "Ổn" with the time it checked when nothing keeps AI bots out, and does not ask again', function (): void {
    seoAiFakeSite();
    app(AiCrawlerCheck::class)->run(1);
    Queue::fake();

    $card = seoAiCardHtml(seoAiPage(seoAiAdmin()));

    expect($card)->toContain('Bot AI (ChatGPT, Claude, Perplexity và các bot khác)', '>Ổn<', 'data-tone="done"', 'Các bot AI đọc được website của bạn.', 'Kiểm tra lại', 'aria-disabled="false"', 'role="status"', 'aria-live="polite"')
        ->and($card)->toMatch('~Kiểm lúc \d{2}:\d{2}, \d{2}/\d{2}/\d{4}~')
        ->not->toContain('wire:poll', 'Mỗi phút chỉ kiểm được', 'aria-describedby');
    Queue::assertNothingPushed();
});

it('says "Đang kiểm" while a check runs, with the last time, a busy button, and asks again every few seconds', function (): void {
    seoAiFakeSite();
    app(AiCrawlerCheck::class)->run(1);
    Queue::fake();
    app(AiCrawlerCheck::class)->markRunning(1);

    $card = seoAiCardHtml(seoAiPage(seoAiAdmin()));

    expect($card)->toContain('data-tone="wait"', 'animate-spin', 'Đang thử đọc website như ChatGPT, Claude và Perplexity. Bạn cứ làm việc khác, kết quả tự hiện ở đây sau ít giây.', 'aria-disabled="true"', 'wire:poll.3s')
        ->and(substr_count($card, '>Đang kiểm<'))->toBe(2)   // the status word and the button
        ->and($card)->toMatch('~Lần kiểm trước: \d{2}:\d{2}, \d{2}/\d{2}/\d{4}~')
        ->not->toContain('Kiểm tra lại<', 'Kiểm lúc', '…');
});

it('stops asking once the check is over and shows the result', function (): void {
    Queue::fake();
    $admin = seoAiAdmin();

    $during = seoAiCardHtml(seoAiPage($admin));   // the open queues a check
    seoAiFakeSite();
    app(AiCrawlerCheck::class)->run(1);            // the queue runs it, which ends the "running" mark
    $after = seoAiCardHtml(seoAiPage($admin));

    expect($during)->toContain('wire:poll.3s', 'data-tone="wait"')
        ->and($after)->toContain('data-tone="done"', '>Ổn<')->not->toContain('wire:poll');
});

it('says when "Kiểm tra lại" may be pressed again, linked to the button, and frees it once the minute is over', function (): void {
    Queue::fake();
    $admin = seoAiAdmin();

    $card = seoAiCardHtml(seoAiPage($admin, function ($lw): void {
        app(AiCrawlerCheck::class)->clearRunning(1);   // the open's check is over
        $lw->call('recheck');
        app(AiCrawlerCheck::class)->clearRunning(1);   // and so is the one just asked for
        $lw->call('$refresh');
    }));

    expect($card)->toMatch('~<p id="seo-ai_check-limit"[^>]*>Mỗi phút chỉ kiểm được 1 lần\. Bấm lại được từ \d{2}:\d{2}\.</p>~')
        ->and($card)->toContain('aria-disabled="true"', 'aria-describedby="seo-ai_check-limit"', 'Kiểm tra lại')
        ->and($card)->toMatch('~wire:poll\.(6[01]|[1-5]\d|\d)s~');
});

it('says the website is hidden while the switch is off, before it is saved', function (): void {
    seoAiFakeSite();
    app(AiCrawlerCheck::class)->run(1);
    Queue::fake();

    $card = seoAiCardHtml(seoAiPage(seoAiAdmin(), fn ($lw) => $lw->call('toggleIndexable')->call('hideFromGoogle')));

    expect($card)->toContain('>Thông tin<', 'data-tone="info"', 'Website đang ẩn với mọi công cụ tìm kiếm, kể cả các công cụ AI.', 'Khi website đã làm xong, bật “Cho Google tìm thấy website” ở trên.')
        ->not->toContain('Các bot AI đọc được website của bạn.');
});

it('names the bots the robots.txt text blocks, in the card and under the box, as it is typed', function (string $robots, string $words): void {
    seoAiFakeSite();
    app(AiCrawlerCheck::class)->run(1);
    Queue::fake();

    $page = seoAiPage(seoAiAdmin(), fn ($lw) => $lw->set('form.robots', $robots));
    $card = seoAiCardHtml($page);

    expect($card)->toContain('>Thông tin<', $words, 'Muốn các công cụ AI đọc được website, xóa các dòng chặn đó trong ô “robots.txt tùy chỉnh” ở nhóm Nâng cao.')
        ->and($page)->toContain('data-robots-note', $words.' Muốn cho đọc lại thì xóa các dòng chặn đó trong ô trên.');
})->with([
    'two bots shut out' => ["User-agent: GPTBot\nDisallow: /\n\nUser-agent: ClaudeBot\nDisallow: /", 'Bạn đang chặn: GPTBot, ClaudeBot. Các công cụ AI sẽ không đọc được website.'],
    'some pages' => ["User-agent: PerplexityBot\nDisallow: /bang-gia", 'Bạn đang chặn một số trang với: PerplexityBot.'],
    'every search engine' => ["User-agent: *\nDisallow: /", 'Chữ robots.txt này đang chặn mọi công cụ tìm kiếm, kể cả Google và các công cụ AI.'],
]);

it('says "every search engine" only when Google and every AI bot are shut out', function (string $robots): void {
    Queue::fake();

    $page = seoAiPage(seoAiAdmin(), fn ($lw) => $lw->set('form.robots', $robots));

    expect($page)->not->toContain('mọi công cụ tìm kiếm');
})->with([
    'Google only' => ["User-agent: Googlebot\nDisallow: /"],
    'all but GPTBot' => ["User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /"],
]);

it('says the robots.txt text cannot be judged, never "Ổn", when it is past the judging bounds', function (): void {
    seoAiFakeSite();
    app(AiCrawlerCheck::class)->run(1);
    Queue::fake();

    $page = seoAiPage(seoAiAdmin(), fn ($lw) => $lw->set('form.robots', "User-agent: *\nDisallow: /".str_repeat('a', RobotsTxt::MAX_PATH + 8)));
    $card = seoAiCardHtml($page);

    expect($card)->toContain('>Chưa kiểm được<', 'robots.txt có quá nhiều quy tắc, hoặc quy tắc quá dài')
        ->not->toContain('>Ổn<')
        ->and($page)->toContain('data-robots-note');
});

it('shows no note under the robots.txt box when the text blocks no bot', function (): void {
    Queue::fake();

    $page = seoAiPage(seoAiAdmin(), fn ($lw) => $lw->set('form.robots', "User-agent: Googlebot-Image\nDisallow: /"));

    expect($page)->not->toContain('data-robots-note', 'Bạn đang chặn');
});

it('reads each finding of the kept check in the card’s words, the worst first', function (Closure $site, string $tone, string $status, array $words): void {
    $site();
    app(AiCrawlerCheck::class)->run(1);
    Queue::fake();

    $card = seoAiCardHtml(seoAiPage(seoAiAdmin()));

    expect($card)->toContain('data-tone="'.$tone.'"', '>'.$status.'<', ...$words);
})->with([
    'a service in front adds blocking lines' => [fn () => seoAiFakeSite(robots: "User-agent: *\nDisallow:\n\nUser-agent: GPTBot\nDisallow: /"), 'warn', 'Cảnh báo', [
        'Một dịch vụ đứng trước website (thường là Cloudflare) đang thêm dòng chặn bot AI. ChatGPT, Claude và Perplexity sẽ không đọc được website.',
        'Trong tài khoản Cloudflare, mở AI Crawl Control và tắt phần tự chặn bot AI, rồi bấm Kiểm tra lại.',
    ]],
    'Cloudflare in front challenges bots' => [fn () => seoAiFakeSite(bots: ['GPTBot' => 403], all: ['Server' => 'cloudflare']), 'info', 'Thông tin', [
        'Có Cloudflare đứng trước website và nó đang chặn hoặc thử thách bot. Bot AI thật có thể vẫn được phép, nên kết quả này chưa chắc chắn.',
        'Hãy xem mục AI Crawl Control trong tài khoản Cloudflare của bạn.',
    ]],
    'the platform hides its temporary address' => [function (): void {
        config(['tenancy.noindex_platform_subdomains' => true]);
        seoAiFakeSite(all: ['X-Robots-Tag' => 'noindex']);
    }, 'info', 'Thông tin', ['Nền tảng đang ẩn địa chỉ tạm a.test với công cụ tìm kiếm và bot AI.', 'Hãy gắn tên miền riêng ở mục Tên miền.']],
    'the domain does not point here' => [fn () => app()->instance(SafeHttp::class, new SafeHttp(static fn (string $host): array => [])), 'nochk', 'Chưa kiểm được', [
        'Tên miền chính chưa trỏ về máy chủ, nên chưa kiểm được.',
        'Xem lại bảng trỏ ở mục Tên miền, rồi bấm Kiểm tra lại.',
    ]],
]);

it('puts a warning above information when the check found both', function (): void {
    // The check finds the Content-Signal line (information) before the refused bot (a warning).
    seoAiFakeSite(robots: "User-agent: *\nDisallow:\nContent-Signal: ai-train=no\nSitemap: https://a.test/sitemap.xml", bots: ['GPTBot' => 403]);
    app(AiCrawlerCheck::class)->run(1);
    Queue::fake();

    $card = seoAiCardHtml(seoAiPage(seoAiAdmin()));

    expect($card)->toContain('>Cảnh báo<')
        ->and(strpos($card, 'Website từ chối bot AI trong khi trình duyệt vẫn mở được: GPTBot (mã 403).'))->toBeLessThan((int) strpos($card, 'Content-Signal'));
});
