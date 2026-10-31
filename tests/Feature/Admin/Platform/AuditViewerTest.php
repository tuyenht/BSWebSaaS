<?php

use App\Modules\Admin\Livewire\Platform\Audit\Index;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\BoundedRead;
use App\Modules\Tenancy\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Operator Audit log (read-only). `audit_logs` is GLOBAL (no RLS; nullable site_id for context)
 * so operators get cross-site visibility directly. Gated by platform.audit.view (super_admin/support,
 * NOT billing). Rows read as plain sentences: no raw action code, site id or user id is shown.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    app()->setLocale('vi'); // Livewire::test renders outside the locale middleware
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    // the seeded ulids are not real ulids, and the screen takes only real ones from the browser
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['ulid' => (string) Str::ulid(), 'name' => 'Nha khoa Tâm Đức']);
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['ulid' => (string) Str::ulid(), 'name' => 'Cơ khí An Vinh']);
});

function auditOperator(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function auditRow(string $action, array $attributes = []): AuditLog
{
    return AuditLog::query()->create($attributes + ['action' => $action, 'actor_label' => 'x@example.test', 'meta' => []]);
}

function auditSiteUlid(int $id): string
{
    return (string) Site::query()->whereKey($id)->value('ulid');
}

it('lists entries as plain sentences with the website name, never the code or the ids', function (): void {
    $member = User::factory()->create(['name' => 'Nguyễn Ngọc Anh']);
    auditRow('rbac.role.assigned', ['actor_id' => $member->id, 'actor_label' => $member->email, 'site_id' => 1, 'meta' => ['role' => 'Biên tập viên']]);
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(auditOperator('support'))->get("http://{$host}/audit")
        ->assertOk()
        ->assertSee('Thêm nhóm quyền cho thành viên')
        ->assertSee('Nguyễn Ngọc Anh')
        ->assertSee('Nha khoa Tâm Đức')
        ->assertSee('Biên tập viên')
        ->assertDontSee('rbac.role.assigned');
});

it('reads an unknown code as "Thao tác khác"', function (): void {
    auditRow('platform.entitlement.toggle');

    Livewire::actingAs(auditOperator('super_admin'))->test(Index::class)
        ->assertSee('Thao tác khác')
        ->assertDontSee('platform.entitlement.toggle');
});

it('shows the audit time in the platform default zone, not UTC', function (): void {
    auditRow('auth.login.succeeded', ['created_at' => '2026-07-10 07:30:00']);
    $host = (string) config('tenancy.platform_host');

    // The platform plane resolves no site (no SetCurrentSite in its route group), so SiteTime
    // falls back to the platform default zone (Asia/Ho_Chi_Minh, UTC+7) — there is no single
    // "right" site to render a cross-site log in: 07:30 UTC stored -> 14:30 shown.
    $this->actingAs(auditOperator('support'))->get("http://{$host}/audit")
        ->assertOk()
        ->assertSee('10/07/2026 14:30')
        ->assertDontSee('10/07/2026 07:30');
});

it('forbids billing (no audit.view) from the audit log', function (): void {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(auditOperator('billing'))->get("http://{$host}/audit")->assertForbidden();
});

it('forbids billing from calling the screen actions too', function (): void {
    Livewire::actingAs(auditOperator('billing'))->test(Index::class)->assertForbidden();
});

it('pages by the last row seen, carrying a ulid and never an id', function (): void {
    foreach (range(1, 12) as $n) {
        auditRow('cms.menu.saved', ['meta' => ['name' => sprintf('menu-%02d', $n)], 'created_at' => now()->subMinutes(20 - $n)]);
    }
    $rows = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'ulid']);

    $page = Livewire::actingAs(auditOperator('support'))->test(Index::class)->set('per', 10)
        ->assertSee('menu-12')->assertSee('menu-03')->assertDontSee('menu-02');
    expect($page->viewData('older'))->toBe($rows[9]->ulid)
        ->and($page->viewData('newer'))->toBeNull();

    $page->call('older', $rows[9]->ulid)
        ->assertSet('after', $rows[9]->ulid)
        ->assertSee('menu-02')->assertSee('menu-01')->assertDontSee('menu-03');
    expect($page->viewData('rows'))->toHaveCount(2)
        ->and($page->viewData('older'))->toBeNull()
        ->and($page->viewData('newer'))->toBe($rows[10]->ulid);

    $page->call('newer', $rows[10]->ulid)->assertSet('before', $rows[10]->ulid)->assertSet('after', '');
    expect($page->viewData('rows')->pluck('ulid')->all())->toBe($rows->take(10)->pluck('ulid')->all())
        ->and($page->viewData('newer'))->toBeNull()
        ->and($page->viewData('older'))->toBe($rows[9]->ulid);

    // a page cursor that is not a ulid (a raw id typed into the URL) is dropped
    $page->set('after', (string) $rows[3]->id)->assertSet('after', '');
});

it('filters by action group, website (the platform included) and person', function (): void {
    $anh = User::factory()->create(['name' => 'Nguyễn Ngọc Anh']);
    $phuc = User::factory()->create(['name' => 'Lê Hoàng Phúc']);
    auditRow('auth.login.succeeded', ['actor_id' => $anh->id, 'site_id' => 1, 'meta' => ['name' => 'row-login']]);
    auditRow('cms.menu.saved', ['actor_id' => $phuc->id, 'site_id' => 2, 'meta' => ['name' => 'row-menu']]);
    auditRow('tenancy.plan.created', ['actor_id' => $phuc->id, 'meta' => ['name' => 'row-plan']]);
    auditRow('support.session.entered', ['actor_id' => $anh->id, 'impersonator_id' => $phuc->id, 'site_id' => 1, 'meta' => ['name' => 'row-support']]);

    $page = Livewire::actingAs(auditOperator('support'))->test(Index::class);

    $page->set('groups', ['menus', 'plans'])
        ->assertSee('row-menu')->assertSee('row-plan')->assertDontSee('row-login')->assertDontSee('row-support');

    $page->set('groups', [])->set('sites', [Index::NO_SITE])
        ->assertSee('row-plan')->assertDontSee('row-menu')->assertDontSee('row-login');
    $page->set('sites', [auditSiteUlid(2), Index::NO_SITE])
        ->assertSee('row-plan')->assertSee('row-menu')->assertDontSee('row-login');

    // a person matches as the member and as the staff member behind a support sign-in
    $page->set('sites', [])->set('people', [$phuc->ulid])
        ->assertSee('row-menu')->assertSee('row-plan')->assertSee('row-support')->assertDontSee('row-login');
    expect(array_column($page->viewData('peopleChoices'), 'label'))->toBe(['Lê Hoàng Phúc', 'Nguyễn Ngọc Anh']);

    // values the screen does not know are dropped, not passed to the query
    $page->set('people', ['42'])->assertSet('people', [])
        ->set('groups', ['nope', 'menus'])->assertSet('groups', ['menus'])
        ->set('sites', ['2'])->assertSet('sites', []);
});

it('shows only support actions, and every action of one support session', function (): void {
    $session = (string) Str::ulid();
    $other = (string) Str::ulid();
    auditRow('support.session.issued', ['support_session_ulid' => $session, 'meta' => ['reason' => 'Khách nhờ sửa trình đơn', 'name' => 'row-issued']]);
    $edit = auditRow('cms.menu.saved', ['impersonator_id' => 7, 'impersonator_label' => 'staff@example.test', 'support_session_ulid' => $session, 'site_id' => 1, 'meta' => ['name' => 'row-edit']]);
    auditRow('cms.menu.saved', ['impersonator_id' => 7, 'support_session_ulid' => $other, 'site_id' => 1, 'meta' => ['name' => 'row-other']]);
    auditRow('cms.menu.saved', ['site_id' => 1, 'meta' => ['name' => 'row-plain']]);

    $page = Livewire::actingAs(auditOperator('support'))->test(Index::class)
        ->set('support', true)
        ->assertSee('row-issued')->assertSee('row-edit')->assertSee('row-other')->assertDontSee('row-plain');

    $page->set('support', false)->call('openDetail', $edit->ulid)
        ->assertSee('Lý do của phiên: Khách nhờ sửa trình đơn')
        ->assertSee('Mọi thao tác của phiên này')
        ->call('showSession')
        ->assertSet('session', $session)
        ->assertSet('open', '')
        ->assertSee('row-issued')->assertSee('row-edit')->assertDontSee('row-other')->assertDontSee('row-plain');
});

it('narrows by period in the platform zone, a date range overriding the presets', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Ho_Chi_Minh'));
    auditRow('cms.menu.saved', ['meta' => ['name' => 'row-today'], 'created_at' => CarbonImmutable::parse('2026-10-20 00:30', 'Asia/Ho_Chi_Minh')->utc()]);
    auditRow('cms.menu.saved', ['meta' => ['name' => 'row-yesterday'], 'created_at' => CarbonImmutable::parse('2026-10-19 23:30', 'Asia/Ho_Chi_Minh')->utc()]);
    auditRow('cms.menu.saved', ['meta' => ['name' => 'row-old'], 'created_at' => CarbonImmutable::parse('2026-10-01 12:00', 'Asia/Ho_Chi_Minh')->utc()]);

    $page = Livewire::actingAs(auditOperator('support'))->test(Index::class)
        ->set('when', 'today')
        ->assertSee('row-today')->assertDontSee('row-yesterday')->assertDontSee('row-old');
    $page->set('when', '7')->assertSee('row-yesterday')->assertDontSee('row-old');

    $page->set('from', '2026-10-01')->set('to', '2026-10-19')
        ->assertSet('when', Index::RANGE)
        ->assertSee('row-old')->assertSee('row-yesterday')->assertDontSee('row-today');

    $page->set('when', '')->assertSet('from', '')->assertSet('to', '')->assertSee('row-today');
    $page->set('from', '2026-02-30')->assertSet('from', '')->assertSet('when', '');

    // dates picked the wrong way round: the same span, and the chip reads it in order
    $page->set('from', '2026-10-19')->set('to', '2026-10-01')
        ->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-19')
        ->assertSee('row-old')->assertDontSee('row-today')
        ->assertSee('01/10/2026 – 19/10/2026');

    $page->set('siteQuery', 'tam')->call('clearFilters')
        ->assertSet('siteQuery', '')->assertSet('from', '')->assertSet('when', '');
});

it('searches without diacritics across sentences, names, emails, websites, IPs and details', function (): void {
    $anh = User::factory()->create(['name' => 'Nguyễn Ngọc Anh', 'email' => 'ngocanh.'.Str::lower(Str::random(8)).'@example.test']);
    auditRow('auth.login.succeeded', ['actor_id' => $anh->id, 'actor_label' => $anh->email, 'meta' => ['name' => 'row-person']]);
    auditRow('auth.login.failed', ['actor_label' => 'someone@example.test', 'ip_address' => '113.161.72.18', 'meta' => ['name' => 'row-ip']]);
    auditRow('tenancy.plan.created', ['site_id' => 1, 'meta' => ['name' => 'row-site']]);
    auditRow('auth.password.reset', ['meta' => ['reason' => 'Khách quên mật khẩu', 'name' => 'row-detail']]);
    auditRow('cms.menu.saved', ['meta' => ['name' => 'row-menu']]);
    auditRow('cms.menu.deleted'); // empty details are stored as a JSON list, not an object

    $page = Livewire::actingAs(auditOperator('support'))->test(Index::class);

    $page->set('q', 'nguyen ngoc')->assertSee('row-person')->assertDontSee('row-ip');
    $page->set('q', 'NGOCANH.')->assertSee('row-person')->assertDontSee('row-ip');
    $page->set('q', '113.161')->assertSee('row-ip')->assertDontSee('row-person');
    $page->set('q', 'tam duc')->assertSee('row-site')->assertDontSee('row-menu');
    $page->set('q', 'quen mat khau')->assertSee('row-detail')->assertDontSee('row-person');
    $page->set('q', 'trình đơn')->assertSee('row-menu')->assertDontSee('row-site');
    // keys of the details are not searched, and LIKE wildcards match only themselves
    $page->set('q', 'reason')->assertSee('Không có dòng nào khớp');
    $page->set('q', '%')->assertSee('Không có dòng nào khớp');
});

it('says the search took too long instead of failing when the time limit is hit', function (): void {
    auditRow('cms.menu.saved');
    app()->instance(BoundedRead::class, new class extends BoundedRead
    {
        #[Override]
        public function run(Closure $read, int $milliseconds = 3000): mixed
        {
            return parent::run(function () use ($read): mixed {
                DB::select('select pg_sleep(0.2)');

                return $read();
            }, 50);
        }
    });

    Livewire::actingAs(auditOperator('support'))->test(Index::class)
        ->assertSee('Tìm lâu quá 3 giây nên đã dừng')
        // the filters that do narrow a search: a person or a website first, then a shorter period
        ->assertSee('chọn Người làm hoặc Website, hoặc chọn Thời gian ngắn hơn')
        ->assertSee('Thử lại');
});

it('reports any other failed read and offers to try again, keeping the filters', function (): void {
    Exceptions::fake();
    app()->instance(BoundedRead::class, new class extends BoundedRead
    {
        #[Override]
        public function run(Closure $read, int $milliseconds = 3000): mixed
        {
            return parent::run(fn (): mixed => DB::select('select * from audit_table_that_does_not_exist'));
        }
    });

    Livewire::actingAs(auditOperator('support'))->test(Index::class, ['q' => 'menu'])
        ->assertSee('Đã có lỗi khi tải nhật ký')
        ->assertSee('Thử lại')
        ->assertSet('q', 'menu');

    Exceptions::assertReported(QueryException::class);
});

it('narrows the website choices by name without diacritics and keeps a chosen website listed', function (): void {
    $labels = fn ($page): array => array_column($page->viewData('siteChoices'), 'label');

    $page = Livewire::actingAs(auditOperator('support'))->test(Index::class)->set('siteQuery', 'TAM DUC');
    expect($labels($page))->toContain('Nha khoa Tâm Đức')->not->toContain('Cơ khí An Vinh');

    $page->set('sites', [auditSiteUlid(2)]);
    expect($labels($page))->toContain('Nha khoa Tâm Đức')->toContain('Cơ khí An Vinh');
});

it('shows a support row as the staff member on behalf of the member, with the reason', function (): void {
    $anh = User::factory()->create(['name' => 'Nguyễn Ngọc Anh']);
    $phuc = User::factory()->create(['name' => 'Lê Hoàng Phúc']);
    $session = (string) Str::ulid();
    auditRow('support.session.issued', ['support_session_ulid' => $session, 'meta' => ['reason' => 'Khách nhờ đổi ảnh', 'minutes' => 30]]);
    $row = auditRow('support.session.ended', [
        'actor_id' => $anh->id, 'impersonator_id' => $phuc->id, 'impersonator_label' => $phuc->email, 'support_session_ulid' => $session,
        'site_id' => 1, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36',
        'meta' => ['why' => 'expired', 'session_ulid' => $session],
    ]);
    $brand = (string) config('admin.brand.name');

    Livewire::actingAs(auditOperator('support'))->test(Index::class)
        ->assertSee("Lê Hoàng Phúc ({$brand}) thay mặt Nguyễn Ngọc Anh")
        ->call('openDetail', $row->ulid)
        ->assertSee('Kết thúc phiên đăng nhập hỗ trợ')
        ->assertSee("Thao tác do Lê Hoàng Phúc (nhân viên hỗ trợ {$brand}) làm khi đăng nhập dưới tên Nguyễn Ngọc Anh.")
        ->assertSee('Lý do của phiên: Khách nhờ đổi ảnh')
        ->assertSee('hết giờ')
        ->assertSee('Chrome · Windows')
        ->assertDontSee($session)
        ->call('closeDetail')
        ->assertSet('open', '')
        ->assertDontSee('Lý do của phiên');
});

it('reads one page with a fixed number of queries, however many rows it shows', function (): void {
    $budget = function (int $rows): int {
        DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
        foreach (range(1, $rows) as $n) {
            $user = User::factory()->create();
            auditRow('cms.menu.saved', ['actor_id' => $user->id, 'impersonator_id' => $user->id, 'site_id' => 1 + $n % 2]);
        }
        $operator = auditOperator('support');
        Livewire::actingAs($operator)->test(Index::class); // warm the permission cache first

        return countQueries(fn () => Livewire::actingAs($operator)->test(Index::class)->set('per', 50));
    };

    expect($budget(3))->toBe($budget(30));
});

it('re-checks the permission on every action, not only when the screen opens', function (): void {
    $row = auditRow('cms.menu.saved');
    $operator = auditOperator('support');
    $page = Livewire::actingAs($operator)->test(Index::class)->assertOk();

    app(PlatformAccess::class)->onPlatform(fn () => $operator->removeRole('support'));
    app(PlatformAccess::class)->forgetMemo();

    $page->call('openDetail', $row->ulid)->assertForbidden();
});

it('pages rows written in the same instant without skipping or repeating any', function (): void {
    $at = now()->subMinute();
    foreach (range(1, 5) as $n) {
        auditRow('cms.menu.saved', ['meta' => ['name' => "tie-{$n}"], 'created_at' => $at]);
    }

    $all = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('ulid')->all();
    $page = Livewire::actingAs(auditOperator('support'))->test(Index::class)->set('per', 10);
    expect($page->viewData('rows')->pluck('ulid')->all())->toBe($all);

    // the time alone does not order these rows: the id tie-break must
    $seen = [];
    foreach ([0, 1, 2, 3] as $i) {
        $page->call('older', $all[$i]);
        $seen[] = $page->viewData('rows')->pluck('ulid')->all();
    }
    expect($seen)->toBe([array_slice($all, 1), array_slice($all, 2), array_slice($all, 3), array_slice($all, 4)]);

    $page->call('newer', $all[4]);
    expect($page->viewData('rows')->pluck('ulid')->all())->toBe(array_slice($all, 0, 4));
});

it('shows the first page when a page link has gone stale', function (): void {
    foreach (range(1, 3) as $n) {
        auditRow('cms.menu.saved', ['meta' => ['name' => "row-{$n}"], 'created_at' => now()->subMinutes(10 - $n)]);
    }
    $newest = AuditLog::query()->orderByDesc('created_at')->value('ulid');
    $oldest = AuditLog::query()->orderBy('created_at')->value('ulid');

    Livewire::actingAs(auditOperator('support'))->withQueryParams(['before' => $newest])->test(Index::class)
        ->assertSet('before', '')
        ->assertSee('row-3')->assertDontSee('Chưa có thao tác nào được ghi');
    Livewire::actingAs(auditOperator('support'))->withQueryParams(['after' => $oldest])->test(Index::class)
        ->assertSet('after', '')
        ->assertSee('row-1')->assertSee('row-3');
});

it('names a plan feature by its name, never its key, and keeps at most 50 choices', function (): void {
    auditRow('tenancy.site.feature_overridden', ['site_id' => 1, 'meta' => ['feature' => 'locales.max', 'value' => 3, 'was' => 1]]);
    $row = auditRow('tenancy.site.feature_overridden', ['site_id' => 1, 'meta' => ['feature' => 'gone.feature', 'value' => true]]);

    $page = Livewire::actingAs(auditOperator('support'))->test(Index::class)
        ->assertSee('Số ngôn ngữ tối đa')
        ->assertDontSee('locales.max')
        ->call('openDetail', $row->ulid)
        ->assertDontSee('gone.feature');

    $page->set('people', array_map(fn () => (string) Str::ulid(), range(1, 80)));
    expect($page->get('people'))->toHaveCount(50);

    // the name shown is what search finds
    $page->set('people', [])->call('closeDetail')->set('q', 'so ngon ngu')
        ->assertSee('Số ngôn ngữ tối đa');
    expect($page->viewData('rows'))->toHaveCount(1);
});

it('opens one entry from a link, and ignores a link to nothing', function (): void {
    $row = auditRow('cms.menu.saved', ['meta' => ['reason' => 'Đổi thứ tự trình đơn']]);

    Livewire::actingAs(auditOperator('support'))->withQueryParams(['open' => $row->ulid])->test(Index::class)
        ->assertSet('open', $row->ulid)
        ->assertSee('Đổi thứ tự trình đơn');
    Livewire::actingAs(auditOperator('support'))->withQueryParams(['open' => (string) Str::ulid()])->test(Index::class)
        ->assertSet('open', '');
    Livewire::actingAs(auditOperator('support'))->withQueryParams(['open' => (string) $row->id])->test(Index::class)
        ->assertSet('open', '');
});
