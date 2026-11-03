<?php

use App\Modules\Admin\Livewire\Settings\Domains;
use App\Modules\Admin\Notifications\DomainHelpRequestNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\OwnershipProof;
use App\Modules\Tenancy\Domains\PointingMethod;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\FakeDns;

/*
 * A website's Domains screen (design.md §28): its owner adds their own name, points it, proves it
 * is theirs when another site holds it, turns HTTPS on, picks the main name and the spelling that
 * runs, asks support for help — and sees what went wrong. `domains` has no row-level security, so
 * the screen only ever finds a name inside the site it is about; a name of another site is "not
 * found". Reading needs domains.view (support staff may look); every change needs domains.manage
 * and is never made on a member's behalf.
 */

beforeEach(function (): void {
    app()->setLocale('vi');
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate users, audit_logs, site_notifications restart identity cascade');
    // Both sites may use their own domains (entitlement comes before any permission).
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'domains', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'domains', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Cache::flush();
    foreach (['domain-help:1', 'domain-help:2', 'domain-https:tencongty.vn', 'domain-https:www.tencongty.vn'] as $key) {
        RateLimiter::clear($key);
    }
    config([
        'tenancy.custom_domains.target' => 'target.platform.test', 'tenancy.base_domains' => ['platform.test'],
        'tenancy.custom_domains.ip' => FakeDns::PLATFORM_IP,
    ]);
    $this->dns = FakeDns::install();
    Queue::fake();
});

/** A fresh member of $site holding the corporate role template $name. */
function dsMember(int $site, string $name): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];
    $user = User::factory()->create(['name' => 'Member '.$name]);

    app(Tenancy::class)->enter($site, function () use ($name, $tpl, $perms, $user): void {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);
        $user->assignRole($role);
    });
    app(TeamContext::class)->forget();

    return $user;
}

function dsStaff(string $role): User
{
    $user = User::factory()->create(['name' => 'Staff '.$role]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user;
}

/** A pair of spellings on $site: $runs runs and gets $columns; its twin redirects. */
function dsPair(int $site, string $runs, array $columns = [], array $twin = []): void
{
    $base = ['site_id' => $site, 'kind' => 'custom', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()];
    $table = DB::connection('pgsql_admin')->table('domains');
    $table->insert([...$base, 'hostname' => $runs, 'is_redirect' => false, ...$columns]);
    $table->insert([...$base, 'hostname' => str_starts_with($runs, 'www.') ? substr($runs, 4) : 'www.'.$runs, 'is_redirect' => true, ...$twin]);
}

function dsLive(int $site, string $runs, bool $primary = false): void
{
    if ($primary) {
        DB::connection('pgsql_admin')->table('domains')->where('site_id', $site)->update(['is_primary' => false]);
    }
    dsPair($site, $runs, ['verified_at' => now(), 'ssl_status' => 'active', 'is_primary' => $primary], ['verified_at' => now(), 'ssl_status' => 'active']);
}

/** The screen as $user sees it on the admin of $site. */
function dsScreen(User $user, int $site = 1, bool $support = false): Testable
{
    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($user);
    if ($support) {
        app(SupportSession::class)->start(dsStaff('support'), $user, $site, 'Kiểm tra', now()->addMinutes(30));
        // Livewire's test requests skip the middleware, so nothing starts the session on them;
        // hand each one this session, as StartSession does on a real request.
        app()->rebinding('request', function ($app, Request $request) use ($session): void {
            if (! $request->hasSession()) {
                $request->setLaravelSession($session);
            }
        });
    }

    return app(Tenancy::class)->enter($site, fn () => Livewire::actingAs($user)->test(Domains::class));
}

/** Run $then inside $site, where the screen's later calls happen. */
function dsIn(int $site, Closure $then): mixed
{
    return app(Tenancy::class)->enter($site, $then);
}

function dsHosts(int $site): array
{
    return DB::connection('pgsql_admin')->table('domains')->where('site_id', $site)->orderBy('hostname')->pluck('hostname')->all();
}

function dsRow(string $host, int $site = 1): ?object
{
    return DB::connection('pgsql_admin')->table('domains')->where('site_id', $site)->where('hostname', $host)->first();
}

function dsTrail(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'like', 'tenancy.domain.%')->orderBy('id')->pluck('action')->all();
}

// ── Who sees what ────────────────────────────────────────────────────────────

it('shows the site\'s own names to whoever may view them, and none of another site\'s', function (): void {
    dsLive(1, 'tencongty.vn', primary: true);
    dsPair(2, 'cua-site-b.vn');

    dsScreen(dsMember(1, 'owner'))
        ->assertOk()
        ->assertSee('Tên miền của website')
        ->assertSee('tencongty.vn')
        ->assertSee('a.test')
        ->assertDontSee('cua-site-b.vn')
        ->assertDontSee('b.test');
});

it('lets an editor look but not change: read-only, no add, no next step', function (): void {
    dsPair(1, 'tencongty.vn');

    dsScreen(dsMember(1, 'editor'))
        ->assertOk()
        ->assertSee('Bạn chỉ xem được tên miền. Thêm, kiểm tra, gỡ cần quyền quản lý tên miền.')
        ->assertDontSeeHtml('wire:click="openAdd"')
        ->assertSee('Trỏ tên miền về hệ thống');
});

it('tells a member without the view permission so, naming their role', function (): void {
    dsScreen(dsMember(1, 'author'))
        ->assertOk()
        ->assertSee('Bạn chưa có quyền quản lý tên miền')
        ->assertSee('Nhóm quyền “'.__('rbac.role.author').'” không có quyền quản lý tên miền.')
        ->assertDontSee('a.test');
});

it('refuses every action to a member without the view permission', function (string $method, array $args): void {
    dsPair(1, 'tencongty.vn');

    dsScreen(dsMember(1, 'author'))->call($method, ...$args)->assertForbidden();

    expect(dsHosts(1))->toBe(['a.test', 'tencongty.vn', 'www.tencongty.vn']);
})->with([
    ['openAdd', []], ['submitAdd', []], ['check', ['tencongty.vn']], ['enableHttps', ['tencongty.vn']],
    ['askPrimary', ['tencongty.vn']], ['askRemove', ['tencongty.vn']], ['switchSpelling', ['tencongty.vn']],
    ['confirmOk', []], ['requestHelp', ['tencongty.vn']], ['togglePanel', ['tencongty.vn']],
    ['pickApex', ['tencongty.vn', 'ip']], ['copied', ['target', 'tencongty.vn']], ['acknowledgeTaken', ['X']],
]);

it('refuses a change to a member who may only look', function (): void {
    dsPair(1, 'tencongty.vn');

    dsScreen(dsMember(1, 'editor'))->call('askRemove', 'tencongty.vn')->assertForbidden();

    expect(dsHosts(1))->toContain('tencongty.vn');
});

it('lets support staff signed in as a member look, and never change a name', function (): void {
    Notification::fake();
    dsPair(1, 'tencongty.vn');

    $screen = dsScreen(dsMember(1, 'owner'), support: true)
        ->assertOk()
        ->assertSee('Không làm được việc này khi nhân viên hỗ trợ đang đăng nhập thay thành viên. Bạn vẫn xem được tên miền và bảng trỏ.');

    foreach ([['openAdd'], ['check', 'tencongty.vn'], ['askRemove', 'tencongty.vn'], ['requestHelp', 'tencongty.vn']] as $call) {
        dsIn(1, fn () => $screen->call(...$call))
            ->assertSet('toast', 'Không làm được việc này khi nhân viên hỗ trợ đang đăng nhập thay thành viên.')
            ->assertSet('toastTone', 'error');
    }

    expect(dsHosts(1))->toContain('tencongty.vn')->and(dsTrail())->toBe([]);
    Notification::assertNothingSent();
});

// ── Tenancy: another site's name is never found ─────────────────────────────

it('treats a name of another site as not found, whatever the browser sends', function (string $method): void {
    dsLive(2, 'cua-site-b.vn', primary: true);
    $before = DB::connection('pgsql_admin')->table('domains')->where('site_id', 2)->orderBy('id')->get()->toJson();

    $screen = dsScreen(dsMember(1, 'owner'));
    dsIn(1, fn () => $screen->call($method, 'cua-site-b.vn'))
        ->assertSet('toast', 'Không tìm thấy tên miền này trong website.')
        ->assertSet('confirm', null);

    expect(DB::connection('pgsql_admin')->table('domains')->where('site_id', 2)->orderBy('id')->get()->toJson())->toBe($before)
        ->and(dsTrail())->toBe([]);
})->with(['check', 'enableHttps', 'askPrimary', 'askRemove', 'switchSpelling', 'requestHelp', 'togglePanel']);

it('never confirms a change for a name the dialog was not opened on', function (): void {
    dsLive(1, 'tencongty.vn');
    dsLive(2, 'cua-site-b.vn');

    $screen = dsScreen(dsMember(1, 'owner'));

    expect(fn () => $screen->set('confirmName', 'cua-site-b.vn'))->toThrow(Exception::class);
});

// ── Adding a name ────────────────────────────────────────────────────────────

it('adds a pasted address as its name, turning www on when it was typed', function (): void {
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('openAdd')->set('addName', 'https://WWW.TenCongTy.vn/abc'))
        ->assertSet('addWww', true)
        ->assertSee('Website sẽ chạy ở https://www.tencongty.vn — tencongty.vn tự chuyển về đây. Bạn nhập có www nên công tắc “Dùng www” đã bật.');

    dsIn(1, fn () => $screen->call('submitAdd'))
        ->assertHasNoErrors()
        ->assertSet('addStep', 2)
        ->assertSee('Đã thêm tencongty.vn vào danh sách. Việc tiếp theo: trỏ tên miền về hệ thống.');

    expect(dsRow('www.tencongty.vn')?->is_redirect)->toBeFalse()
        ->and(dsRow('tencongty.vn')?->is_redirect)->toBeTrue()
        ->and(dsTrail())->toBe(['tenancy.domain.attached']);
});

it('keeps the www choice the owner made by hand', function (): void {
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('openAdd')->call('toggleWww')->call('toggleWww')->set('addName', 'www.tencongty.vn'))
        ->assertSet('addWww', false);
});

it('accepts a name with diacritics', function (): void {
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('openAdd')->set('addName', 'bảo-sơn.vn')->call('submitAdd'))
        ->assertHasNoErrors()
        ->assertSee('Đã thêm bảo-sơn.vn vào danh sách.');

    expect(dsHosts(1))->toContain('xn--bo-sn-xob2903c.vn');
});

it('says in plain words why a name cannot be added, and keeps what was typed', function (string $typed, string $message): void {
    dsPair(1, 'da-co.vn');
    $this->artisan('site:feature', ['host' => 'a.test', 'feature' => 'custom_domains.max', 'value' => '3'])->assertSuccessful();

    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('openAdd')->set('addName', $typed)->call('submitAdd'))
        ->assertHasErrors('addName')
        ->assertSee($message)
        ->assertSet('addName', $typed)
        ->assertSet('addStep', 1);
})->with([
    'empty' => ['', 'Nhập tên miền của bạn, ví dụ tencongty.vn.'],
    'not a name' => ['ten mien sai', 'Tên miền chưa đúng dạng — chỉ gồm chữ, số, dấu gạch nối và dấu chấm, ví dụ tencongty.vn.'],
    'platform name' => ['shop.platform.test', 'Tên miền dạng …platform.test do nền tảng cấp. Website đã có sẵn a.test.'],
    'platform itself' => ['platform.test', 'Đây là tên miền của nền tảng, không gắn được. Hãy dùng tên miền riêng của bạn.'],
    'already there' => ['www.da-co.vn', 'Tên miền này đã có trong danh sách của website.'],
]);

it('counts a stopped name against the plan, and says how to free a place', function (): void {
    dsPair(1, 'da-ngung.vn', ['failing_since' => now()->subDays(40), 'failure_reason' => 'not_pointing']);

    $screen = dsScreen(dsMember(1, 'owner'))
        ->assertSee('Đã dùng 1 / 1 tên miền riêng')
        ->assertSee('Khóa theo gói')
        ->assertSee('Gói hiện tại cho 1 tên miền riêng (có và không www tính là 1) — đã dùng 1. Gỡ bớt một tên miền để thêm tên khác. Gỡ tên miền đã ngừng để thêm tên mới. Cần thêm? Liên hệ hỗ trợ.');

    dsIn(1, fn () => $screen->call('openAdd'))->assertSet('addOpen', false)->assertSet('toastTone', 'error');
    dsIn(1, fn () => $screen->set('addName', 'moi.vn')->call('submitAdd'))->assertHasErrors('addName');

    expect(dsHosts(1))->not->toContain('moi.vn');
});

it('shows the ownership proof right away for a name another site serves', function (): void {
    dsLive(2, 'tranh-chap.vn', primary: true);
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('openAdd')->set('addName', 'tranh-chap.vn')->call('submitAdd'))
        ->assertSee('Đã thêm tranh-chap.vn — cần chứng minh bạn là chủ tên miền.')
        ->assertSee('Tên miền này đang gắn ở website khác')
        ->assertSee(app(OwnershipProof::class)->recordValue(1, 'tranh-chap.vn'))
        ->assertDontSee('cua-site-b')
        ->assertDontSee('SITEB');
});

// ── Checking, HTTPS, primary, spelling, removal ─────────────────────────────

it('checks a waiting name and says it is not pointed yet, then holds a second check for a minute', function (): void {
    dsPair(1, 'tencongty.vn');
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('check', 'tencongty.vn'))
        ->assertSet('toast', 'Chưa thấy tencongty.vn trỏ về hệ thống. Nơi quản lý tên miền có thể cần thêm thời gian — hệ thống tự kiểm lại mỗi 15 phút.')
        ->assertSet('toastTone', 'error');

    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');

    dsIn(1, fn () => $screen->call('check', 'tencongty.vn'))
        ->assertSet('toast', 'Vừa kiểm tra — thử lại sau ít phút. Hệ thống vẫn tự kiểm lại mỗi 15 phút.');

    expect(dsRow('tencongty.vn')?->verified_at)->toBeNull();
});

it('verifies a pointed name and offers HTTPS next', function (): void {
    dsPair(1, 'tencongty.vn');
    $this->dns->pointHere('tencongty.vn', 'www.tencongty.vn');

    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('check', 'tencongty.vn'))
        ->assertSet('toast', 'tencongty.vn đã trỏ đúng. Việc tiếp theo: bật kết nối bảo mật.')
        ->assertSee('Bật kết nối bảo mật');

    expect(dsRow('tencongty.vn')?->verified_at)->not->toBeNull();
});

it('takes a contested name over once its owner published the proof', function (): void {
    dsLive(2, 'tranh-chap.vn', primary: true);
    dsPair(1, 'tranh-chap.vn', ['failure_reason' => 'needs_proof']);
    $this->dns->pointHere('tranh-chap.vn', 'www.tranh-chap.vn');
    // Each spelling is proved by its own TXT record.
    foreach (['tranh-chap.vn', 'www.tranh-chap.vn'] as $host) {
        $this->dns->txt['_site-verification.'.$host] = [app(OwnershipProof::class)->recordValue(1, $host)];
    }

    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('check', 'tranh-chap.vn'))
        ->assertSet('toast', 'Đã xác minh bạn là chủ tranh-chap.vn. Website kia đã nhận thông báo. Việc tiếp theo: bật kết nối bảo mật.');

    expect(dsHosts(2))->toBe(['b.test']);
});

it('turns HTTPS on, looks again while the certificate is on its way, and says when it is on', function (): void {
    dsPair(1, 'tencongty.vn', ['verified_at' => now()], ['verified_at' => now()]);
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('enableHttps', 'tencongty.vn'))
        ->assertSee('Đang bật kết nối bảo mật')
        ->assertSeeHtml('wire:poll.5s');

    DB::connection('pgsql_admin')->table('domains')->where('site_id', 1)->where('kind', 'custom')->update(['ssl_status' => 'active']);

    dsIn(1, fn () => $screen->call('$refresh'))
        ->assertSet('toast', 'Đã bật kết nối bảo mật cho tencongty.vn — địa chỉ bắt đầu bằng https:// và có ổ khóa.')
        ->assertDontSeeHtml('wire:poll.5s');
});

it('asks the certificate step to wait five minutes after a failed try, also after a reload', function (): void {
    dsPair(1, 'tencongty.vn', ['verified_at' => now(), 'ssl_status' => 'failed', 'failure_reason' => 'points_elsewhere']);

    dsScreen(dsMember(1, 'owner'))
        ->assertSee('Tên miền đang trỏ tới nơi khác. Xóa dòng trỏ cũ ở nơi quản lý tên miền rồi bấm Thử lại.')
        ->assertDontSee('Thử lại sau 5 phút');

    RateLimiter::hit('domain-https:tencongty.vn', 300);
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('enableHttps', 'tencongty.vn'))
        ->assertSee('Tên miền đang trỏ tới nơi khác. Xóa dòng trỏ cũ ở nơi quản lý tên miền rồi bấm Thử lại.')
        ->assertSee('Thử lại sau 5 phút')
        ->assertSee('Vừa thử nhiều lần — hãy thử lại sau 5 phút.');

    // Read from the limiter itself: a fresh page says the same.
    dsScreen(dsMember(1, 'owner'))->assertSee('Thử lại sau 5 phút')->assertSee('Vừa thử nhiều lần — hãy thử lại sau 5 phút.');
});

it('makes a live name the main one after a confirmation', function (): void {
    dsLive(1, 'tencongty.vn');
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('askPrimary', 'tencongty.vn'))
        ->assertSet('confirm', 'primary')
        ->assertSee('Đặt tencongty.vn làm tên miền chính?')
        ->assertSee('Địa chỉ công khai của website đổi từ a.test sang tencongty.vn.');

    dsIn(1, fn () => $screen->call('confirmOk'))
        ->assertSet('confirm', null)
        ->assertSet('toast', 'Đã đặt tencongty.vn làm tên miền chính. Các tên miền khác tự chuyển về đây.');

    expect(dsRow('tencongty.vn')?->is_primary)->toBeTrue();
});

it('will not make a name main before HTTPS is on', function (): void {
    dsPair(1, 'tencongty.vn', ['verified_at' => now()], ['verified_at' => now()]);
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('askPrimary', 'tencongty.vn'))
        ->assertSet('confirm', null)
        ->assertSet('toast', 'Bật kết nối bảo mật trước, rồi mới đặt làm tên miền chính.');

    expect(dsRow('tencongty.vn')?->is_primary)->toBeFalse();
});

it('switches the spelling of a non-main name at once, with an undo', function (): void {
    dsLive(1, 'tencongty.vn');
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('switchSpelling', 'tencongty.vn'))
        ->assertSet('toast', 'Đã đổi: website chạy ở www.tencongty.vn.')
        ->assertSet('toastUndo', true);
    expect(dsRow('www.tencongty.vn')?->is_redirect)->toBeFalse();

    dsIn(1, fn () => $screen->call('undo'))->assertSet('toastUndo', false);
    expect(dsRow('tencongty.vn')?->is_redirect)->toBeFalse()
        ->and(dsTrail())->toBe(['tenancy.domain.spelling_changed', 'tenancy.domain.spelling_changed']);
});

it('asks before switching the spelling of the main name', function (): void {
    dsLive(1, 'tencongty.vn', primary: true);
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('switchSpelling', 'tencongty.vn'))
        ->assertSet('confirm', 'spelling')
        ->assertSee('Đổi cách viết của tên miền chính?');

    dsIn(1, fn () => $screen->call('confirmOk'))
        ->assertSet('toast', 'Website giờ chạy ở www.tencongty.vn. Cách viết kia tự chuyển về đây.');

    expect(dsRow('www.tencongty.vn')?->is_primary)->toBeTrue();
});

it('removes the main name after a confirmation that states the consequence, and the platform name takes over', function (): void {
    dsLive(1, 'tencongty.vn', primary: true);
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('askRemove', 'tencongty.vn'))
        ->assertSee('Gỡ tencongty.vn khỏi website?')
        ->assertSee('a.test sẽ thành tên miền chính. Khách mở tencongty.vn sẽ không vào được website.')
        ->assertSee('Nhớ xóa 2 dòng đã trỏ ở nơi quản lý tên miền: “@” và “www”.');

    dsIn(1, fn () => $screen->call('confirmOk'))
        ->assertSet('toast', 'Đã gỡ tencongty.vn. Nhớ xóa 2 dòng đã trỏ ở nơi quản lý tên miền. Tên miền chính giờ là a.test.');

    expect(dsHosts(1))->toBe(['a.test'])->and(dsRow('a.test')?->is_primary)->toBeTrue();
});

// ── Pointing panel, help, notices ───────────────────────────────────────────

it('shows the pointing rows, and the server address once the owner picks it for “@”', function (): void {
    dsPair(1, 'tencongty.vn');
    $screen = dsScreen(dsMember(1, 'owner'))
        ->assertSee('target.platform.test')
        ->assertSee('Để chuyển hướng về tencongty.vn');

    dsIn(1, fn () => $screen->call('pickApex', 'tencongty.vn', 'ip'))
        ->assertSee(FakeDns::PLATFORM_IP)
        ->assertSee('Đang dùng tạm dãy số máy chủ cho dòng “@”.');

    // Stored on both spellings (who to warn before the server moves), so a reload keeps it.
    expect(dsRow('tencongty.vn')?->pointing_method)->toBe(PointingMethod::ARecord->value)
        ->and(dsRow('www.tencongty.vn')?->pointing_method)->toBe(PointingMethod::ARecord->value)
        ->and(dsTrail())->toBe(['tenancy.domain.pointing_changed']);
    dsScreen(dsMember(1, 'owner'))->assertSee('Đang dùng tạm dãy số máy chủ cho dòng “@”.');

    dsIn(1, fn () => $screen->call('pickApex', 'tencongty.vn', 'move'))->assertDontSee('Đang dùng tạm dãy số máy chủ cho dòng “@”.');
    expect(dsRow('tencongty.vn')?->pointing_method)->toBe(PointingMethod::Alias->value);

    // The "move" answer is kept as well: a reload still shows it chosen.
    dsScreen(dsMember(1, 'owner'))->assertSet('apex', ['tencongty.vn' => 'move']);
});

it('adds a name with no answer for “@” yet, so nothing shows as chosen', function (): void {
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('openAdd')->set('addName', 'moi.vn')->call('submitAdd'));

    expect(dsRow('moi.vn')?->pointing_method)->toBeNull();
    dsScreen(dsMember(1, 'owner'))->assertSet('apex', []);
});

it('shows the server address to a member who may only look, without storing their choice', function (): void {
    dsPair(1, 'tencongty.vn');
    $screen = dsScreen(dsMember(1, 'editor'));

    dsIn(1, fn () => $screen->call('pickApex', 'tencongty.vn', 'ip'))->assertSee(FakeDns::PLATFORM_IP);

    expect(dsRow('tencongty.vn')?->pointing_method)->toBeNull()->and(dsTrail())->toBe([]);
});

it('offers no server address when the platform has none configured, and never asks DNS for it', function (): void {
    config(['tenancy.custom_domains.ip' => '']);
    dsPair(1, 'tencongty.vn');
    $screen = dsScreen(dsMember(1, 'owner'));
    $this->dns->lookups = 0;

    dsIn(1, fn () => $screen->call('pickApex', 'tencongty.vn', 'ip'))
        ->assertSet('toast', 'Chưa có dãy số máy chủ để dùng cho dòng “@”. Hãy nhờ hỗ trợ trỏ giúp.')
        ->assertDontSee('Đang dùng tạm dãy số máy chủ cho dòng “@”.');

    expect($this->dns->lookups)->toBe(0)->and(dsRow('tencongty.vn')?->pointing_method)->toBeNull();
});

it('starts on the server address when the name was attached to point by number', function (): void {
    dsPair(1, 'tencongty.vn', ['pointing_method' => PointingMethod::ARecord->value]);

    dsScreen(dsMember(1, 'owner'))->assertSee('Đang dùng tạm dãy số máy chủ cho dòng “@”.');
});

it('sends a help request to the support mailbox, notes it in the trail, and holds back a flood', function (): void {
    Notification::fake();
    dsPair(1, 'tencongty.vn');
    $screen = dsScreen(dsMember(1, 'owner'));

    foreach (range(1, 3) as $ignored) {
        dsIn(1, fn () => $screen->call('requestHelp', 'tencongty.vn'))
            ->assertSet('toast', 'Đã gửi yêu cầu. Nhân viên hỗ trợ sẽ trả lời qua email để trỏ giúp tencongty.vn.');
    }
    dsIn(1, fn () => $screen->call('requestHelp', 'tencongty.vn'))->assertSet('toastTone', 'error');

    Notification::assertSentOnDemandTimes(DomainHelpRequestNotification::class, 3);
    Notification::assertSentTo(new AnonymousNotifiable, DomainHelpRequestNotification::class,
        fn (DomainHelpRequestNotification $n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'support@platform.test'
            && $n->domain === 'tencongty.vn' && $n->site === 'A' && $n->tenantSiteId === null);
    expect(dsTrail())->toBe(['tenancy.domain.help_requested', 'tenancy.domain.help_requested', 'tenancy.domain.help_requested']);
});

it('tells the site a name was taken until the member says they understood', function (): void {
    $owner = dsMember(1, 'owner');
    $notice = dsIn(1, fn () => SiteNotification::query()->create([
        'user_id' => $owner->id, 'kind' => 'security.domain_taken_over',
        'data' => ['domains' => 'mat-roi.vn, www.mat-roi.vn', 'until' => '', 'target' => 'target.platform.test'],
    ]));

    $screen = dsScreen($owner)->assertSee('Tên miền mat-roi.vn đã được chủ tên miền gắn sang website khác.');

    dsIn(1, fn () => $screen->call('acknowledgeTaken', $notice->ulid))
        ->assertDontSee('Tên miền mat-roi.vn đã được chủ tên miền gắn sang website khác.');

    expect(dsIn(1, fn () => $notice->fresh()?->read_at))->not->toBeNull();
});

it('marks a name that works again', function (): void {
    $owner = dsMember(1, 'owner');
    dsLive(1, 'tencongty.vn');
    dsIn(1, fn () => SiteNotification::query()->create([
        'user_id' => $owner->id, 'kind' => 'system.domain_problem',
        'data' => ['sentence' => 'recovered', 'domains' => 'tencongty.vn, www.tencongty.vn', 'until' => '', 'target' => 'target.platform.test'],
    ]));

    dsScreen($owner)->assertSee('Hoạt động trở lại');
});

// ── Cost and the platform console ───────────────────────────────────────────

it('reads the screen on a flat query budget and never asks DNS while drawing it', function (): void {
    $this->artisan('site:feature', ['host' => 'a.test', 'feature' => 'custom_domains.max', 'value' => '5'])->assertSuccessful();
    $owner = dsMember(1, 'owner');
    dsPair(1, 'mot.vn');
    // A name pointed by number draws the server's address too — from config, never from DNS.
    dsPair(1, 'so.vn', ['pointing_method' => PointingMethod::ARecord->value]);
    $this->actingAs($owner)->get('http://a.test/admin/settings/domains')->assertOk(); // warm the per-process caches
    $this->dns->lookups = 0;

    $one = countQueries(fn () => $this->actingAs($owner)->get('http://a.test/admin/settings/domains')->assertOk()->assertSee('mot.vn')->assertSee(FakeDns::PLATFORM_IP));

    foreach (['hai.vn', 'ba.vn', 'bon.vn'] as $name) {
        dsPair(1, $name);
    }
    $four = countQueries(fn () => $this->actingAs($owner)->get('http://a.test/admin/settings/domains')->assertOk()->assertSee('bon.vn'));

    // One read of the names, one yes/no on names served elsewhere, the notices, the site's
    // entitlement (2, remembered for the request: the page and the admin nav share it) — never
    // one per name.
    expect($one)->toBeLessThanOrEqual(12)
        ->and($four)->toBe($one)
        ->and($this->dns->lookups)->toBe(0);
});

it('serves the platform console\'s view of one site behind the platform permissions', function (): void {
    dsLive(1, 'tencongty.vn');
    $viewer = dsStaff('support');

    $this->actingAs($viewer);
    Livewire::actingAs($viewer)->test(Domains::class, ['ulid' => 'SITEA000000000000000000001'])
        ->assertOk()
        ->assertSee('Đang xem tên miền của website A từ trang nền tảng. Mọi thao tác được ghi vào nhật ký của website.')
        ->assertSee('Nhóm quyền nền tảng của bạn chỉ xem được tên miền. Thêm, kiểm tra, gỡ cần quyền quản lý website.')
        ->assertSee('tencongty.vn')
        ->call('askRemove', 'tencongty.vn')
        ->assertForbidden();

    expect(dsHosts(1))->toContain('tencongty.vn');
});

it('lets platform staff who manage sites change a site\'s names, logged on that site', function (): void {
    dsLive(1, 'tencongty.vn');
    $ops = dsStaff('super_admin');

    $this->actingAs($ops);
    Livewire::actingAs($ops)->test(Domains::class, ['ulid' => 'SITEA000000000000000000001'])
        ->call('askRemove', 'tencongty.vn')
        ->call('confirmOk')
        ->assertSet('toast', 'Đã gỡ tencongty.vn. Nhớ xóa 2 dòng đã trỏ ở nơi quản lý tên miền.');

    expect(dsHosts(1))->toBe(['a.test'])
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'tenancy.domain.removed')->value('site_id'))->toBe(1);
});

it('keeps the console page off a website\'s own host', function (): void {
    $this->actingAs(dsMember(1, 'owner'))->get('http://a.test/sites/SITEA000000000000000000001/domains')->assertNotFound();
});

// ── Both spellings held by another site: one proof per spelling ─────────────

it('shows a second TXT pair for the other spelling when another site holds both, in the list and the add dialog', function (): void {
    dsLive(2, 'tranh-chap.vn', primary: true);
    $proof = app(OwnershipProof::class);
    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('openAdd')->set('addName', 'tranh-chap.vn')->call('submitAdd'))
        ->assertSee($proof->recordName('tranh-chap.vn'))
        ->assertSee($proof->recordValue(1, 'tranh-chap.vn'))
        ->assertSee($proof->recordName('www.tranh-chap.vn'))
        ->assertSee($proof->recordValue(1, 'www.tranh-chap.vn'))
        ->assertSee('www.tranh-chap.vn cũng đang gắn ở website kia, nên cần thêm dòng thứ hai này cho cách viết đó. Thêm đủ cả hai dòng rồi mới bấm “Kiểm tra”.');

    dsIn(1, fn () => $screen->call('closeAdd'))
        ->assertSee($proof->recordValue(1, 'www.tranh-chap.vn'))
        ->assertSee('Thêm đủ cả hai dòng rồi mới bấm “Kiểm tra”.');

    dsIn(1, fn () => $screen->call('copied', 'txt2_value', 'tranh-chap.vn'))
        ->assertSet('toast', 'Đã sao chép giá trị dòng xác minh thứ hai.');
    dsIn(1, fn () => $screen->call('copied', 'txt2_name', 'tranh-chap.vn', false))
        ->assertSet('toast', 'Chưa sao chép được — bôi đen rồi chép tay: '.$proof->recordName('www.tranh-chap.vn'));
});

it('shows one TXT pair only when the other spelling is free', function (): void {
    DB::connection('pgsql_admin')->table('domains')->insert([
        'site_id' => 2, 'hostname' => 'tranh-chap.vn', 'kind' => 'custom', 'is_primary' => false, 'is_redirect' => false,
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    dsPair(1, 'tranh-chap.vn');

    dsScreen(dsMember(1, 'owner'))
        ->assertSee(app(OwnershipProof::class)->recordValue(1, 'tranh-chap.vn'))
        ->assertDontSee(app(OwnershipProof::class)->recordValue(1, 'www.tranh-chap.vn'))
        ->assertDontSee('Thêm đủ cả hai dòng');
});

it('verifies each spelling by its own proof, and says when the other one still needs its line', function (): void {
    dsLive(2, 'tranh-chap.vn', primary: true);
    dsPair(1, 'tranh-chap.vn', ['failure_reason' => 'needs_proof'], ['failure_reason' => 'needs_proof']);
    $this->dns->pointHere('tranh-chap.vn', 'www.tranh-chap.vn');
    $proof = app(OwnershipProof::class);
    $this->dns->txt['_site-verification.tranh-chap.vn'] = [$proof->recordValue(1, 'tranh-chap.vn')];

    $screen = dsScreen(dsMember(1, 'owner'));

    dsIn(1, fn () => $screen->call('check', 'tranh-chap.vn'))
        ->assertSet('toast', 'Đã xác minh bạn là chủ tranh-chap.vn. Website kia đã nhận thông báo. Việc tiếp theo: bật kết nối bảo mật.')
        ->assertSet('toastTone', 'success')
        // Only the other spelling's pair is left (design 21, disputeHalf), with its own
        // "Kiểm tra lại" in the block and its row saying another website holds it.
        ->assertDontSee($proof->recordValue(1, 'tranh-chap.vn'))
        ->assertSee($proof->recordValue(1, 'www.tranh-chap.vn'))
        ->assertSee('www.tranh-chap.vn vẫn đang gắn ở website khác, nên cách viết đó cần dòng xác minh riêng dưới đây. Thêm dòng này rồi bấm “Kiểm tra lại”.')
        ->assertSee('Đang gắn ở website khác')
        ->assertSeeHtml('data-check-alt');

    expect(dsRow('tranh-chap.vn')?->verified_at)->not->toBeNull()
        ->and(dsRow('www.tranh-chap.vn')?->verified_at)->toBeNull()
        ->and(dsHosts(2))->toBe(['b.test', 'www.tranh-chap.vn']);

    $this->dns->txt['_site-verification.www.tranh-chap.vn'] = [$proof->recordValue(1, 'www.tranh-chap.vn')];
    RateLimiter::clear('domain-check:1:tranh-chap.vn');

    dsIn(1, fn () => $screen->call('check', 'tranh-chap.vn'))
        ->assertSet('toast', 'Đã xác minh bạn là chủ www.tranh-chap.vn. Website kia đã nhận thông báo; www.tranh-chap.vn giờ tự chuyển về tranh-chap.vn.')
        ->assertSet('toastTone', 'success')
        ->assertDontSee($proof->recordValue(1, 'www.tranh-chap.vn'))
        ->assertDontSee('Đang gắn ở website khác')
        ->assertDontSeeHtml('data-check-alt');

    expect(dsHosts(2))->toBe(['b.test']);
});

it('names “Kiểm tra lại” only where that button is: wherever only the other spelling still needs its line', function (string $runs, bool $half): void {
    // www.mot-nua.vn is held by another site; the running spelling is in state $runs.
    dsPair(1, 'mot-nua.vn', match ($runs) {
        'wait' => [],
        'lost' => ['failing_since' => now()->subDays(40), 'failure_reason' => 'not_pointing'],
        'active' => ['verified_at' => now(), 'ssl_status' => 'active'],
        'failing' => ['verified_at' => now(), 'ssl_status' => 'active', 'failing_since' => now()->subDays(3), 'failure_reason' => 'not_pointing'],
        'claim' => ['failure_reason' => 'needs_proof'],
    }, ['failure_reason' => 'needs_proof']);
    $twinOnly = 'www.mot-nua.vn vẫn đang gắn ở website khác, nên cách viết đó cần dòng xác minh riêng dưới đây. Thêm dòng này rồi bấm “Kiểm tra lại”.';

    $screen = dsScreen(dsMember(1, 'owner'))->assertSee(app(OwnershipProof::class)->recordValue(1, 'www.mot-nua.vn'));

    $half
        ? $screen->assertSee($twinOnly)->assertSeeHtml('data-check-alt')->assertDontSee('Thêm đủ cả hai dòng')
        : $screen->assertDontSee($twinOnly)->assertDontSeeHtml('data-check-alt')->assertSee('Thêm đủ cả hai dòng rồi mới bấm “Kiểm tra”.');
})->with([
    'waiting' => ['wait', true],
    'lost' => ['lost', true],
    'live' => ['active', true],
    'failing' => ['failing', true],
    'both spellings held elsewhere' => ['claim', false],
]);

it('shows the other spelling\'s line in the add dialog without naming a button the dialog lacks', function (): void {
    // Another site serves www.moi.vn only; moi.vn itself is free there.
    dsPair(2, 'moi.vn', [], ['verified_at' => now(), 'ssl_status' => 'active']);
    $proof = app(OwnershipProof::class);
    $screen = dsScreen(dsMember(1, 'owner'));

    $html = dsIn(1, fn () => $screen->call('openAdd')->set('addName', 'moi.vn')->call('submitAdd'))->html();

    // The list behind the dialog shows the half block (sentence + button) once; the dialog adds
    // only the pair. A copy field holds its value twice: the text and the copy call.
    expect(substr_count($html, $proof->recordValue(1, 'www.moi.vn')))->toBe(4)
        ->and(substr_count($html, 'www.moi.vn vẫn đang gắn ở website khác'))->toBe(1)
        ->and(substr_count($html, 'data-check-alt="'))->toBe(1);
});

// ── Checks, spelling, polling ───────────────────────────────────────────────

it('holds a second check for a minute whatever the name\'s state', function (): void {
    dsPair(1, 'tencongty.vn', ['verified_at' => now(), 'ssl_status' => 'active'], []);
    $screen = dsScreen(dsMember(1, 'owner'));
    $this->dns->pointHere('tencongty.vn');

    dsIn(1, fn () => $screen->call('check', 'tencongty.vn'))->assertSet('toast', 'Chưa thấy www.tencongty.vn trỏ về hệ thống. Nơi quản lý tên miền có thể cần thêm thời gian — hệ thống tự kiểm lại mỗi 15 phút.');
    $this->dns->lookups = 0;

    dsIn(1, fn () => $screen->call('check', 'tencongty.vn'))
        ->assertSet('toast', 'Vừa kiểm tra — thử lại sau ít phút. Hệ thống vẫn tự kiểm lại mỗi 15 phút.');
    expect($this->dns->lookups)->toBe(0);
});

it('says why the main name cannot run at its other spelling yet, in the menu and when asked', function (?string $twinSsl, bool $twinVerified, string $why): void {
    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'a.test')->update(['is_primary' => false]);
    dsPair(1, 'tencongty.vn', ['verified_at' => now(), 'ssl_status' => 'active', 'is_primary' => true],
        ['verified_at' => $twinVerified ? now() : null, 'ssl_status' => $twinSsl]);
    $screen = dsScreen(dsMember(1, 'owner'))->assertSee($why);

    dsIn(1, fn () => $screen->call('switchSpelling', 'tencongty.vn'))->assertSet('confirm', 'spelling');
    dsIn(1, fn () => $screen->call('confirmOk'))->assertSet('toast', $why)->assertSet('toastTone', 'error');

    expect(dsRow('tencongty.vn')?->is_primary)->toBeTrue();
})->with([
    'not pointed' => [null, false, 'Chưa chạy được ở www.tencongty.vn: cách viết này chưa trỏ về hệ thống. Trỏ xong rồi thử lại.'],
    'no HTTPS' => ['failed', true, 'Chưa chạy được ở www.tencongty.vn: cách viết này chưa bật kết nối bảo mật.'],
]);

it('looks again for a certificate every 5 seconds at first, then every 30, then stops', function (): void {
    dsPair(1, 'tencongty.vn', ['verified_at' => now(), 'ssl_status' => 'pending'], ['verified_at' => now(), 'ssl_status' => 'pending']);
    $screen = dsScreen(dsMember(1, 'owner'))->assertSeeHtml('wire:poll.5s');

    $this->travel(5)->minutes();
    dsIn(1, fn () => $screen->call('$refresh'))->assertSeeHtml('wire:poll.30s');

    $this->travel(30)->minutes();
    dsIn(1, fn () => $screen->call('$refresh'))->assertDontSeeHtml('wire:poll')->assertSee('Đang bật kết nối bảo mật');
});

// ── Messages ────────────────────────────────────────────────────────────────

it('hides a success message after 6 seconds, an undo after 10, and keeps an error until it is closed', function (): void {
    dsLive(1, 'tencongty.vn');
    dsPair(1, 'cho.vn');
    $screen = dsScreen(dsMember(1, 'owner'));

    $seq = dsIn(1, fn () => $screen->call('copied', 'target', 'cho.vn'))->get('toastSeq');
    dsIn(1, fn () => $screen->call('$refresh'))->assertSeeHtml('dismissToast('.$seq.'), 6000) }, destroy() { clearTimeout(this.timer) }');
    dsIn(1, fn () => $screen->call('switchSpelling', 'tencongty.vn'))->assertSeeHtml('), 10000) }');
    dsIn(1, fn () => $screen->call('check', 'cho.vn'))->assertSet('toastTone', 'error')->assertDontSeeHtml('setTimeout(');

    // The same words again are a new message, with a timer of their own.
    $first = dsIn(1, fn () => $screen->call('copied', 'target', 'cho.vn'))->get('toastSeq');
    expect(dsIn(1, fn () => $screen->call('copied', 'target', 'cho.vn'))->get('toastSeq'))->toBe($first + 1);
});

it('lets a message\'s timer clear only that message, never a newer one that arrived first', function (): void {
    dsPair(1, 'cho.vn');
    $screen = dsScreen(dsMember(1, 'owner'));

    // A success message starts its 6-second timer; the owner presses Check, whose error answers
    // before that timer fires.
    $old = dsIn(1, fn () => $screen->call('copied', 'target', 'cho.vn'))->get('toastSeq');
    dsIn(1, fn () => $screen->call('check', 'cho.vn'))->assertSet('toastTone', 'error');
    $error = $screen->get('toast');

    // The old timer fires now: the error must stay until it is closed.
    dsIn(1, fn () => $screen->call('dismissToast', $old))->assertSet('toast', $error)->assertSet('toastTone', 'error');
    expect($error)->not->toBe('');

    // "Đóng" on the error itself carries its own number and clears it.
    dsIn(1, fn () => $screen->call('dismissToast', $old + 1))->assertSet('toast', '');
});

// ── Entitlement before permission ───────────────────────────────────────────

it('has no Domains page or link on a site not entitled to its own domains', function (): void {
    DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->delete();
    $owner = dsMember(1, 'owner');

    $this->actingAs($owner)->get('http://a.test/admin/settings/domains')->assertForbidden();
    $this->actingAs($owner)->get('http://a.test/admin/settings/security')->assertOk()->assertDontSee('/admin/settings/domains');
});
