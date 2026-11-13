<?php

use App\Modules\Admin\Support\SupportSessions;
use App\Modules\Admin\Support\SupportSignIn;
use App\Modules\Audit\AuditService;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * The console's list of support sessions and its "Kết thúc phiên ngay" (design.md v2.34), read
 * out of the audit trail: a session is its `entered` row, ended by the first `ended` row with its
 * code or by its time running out. Ending a session from the console, or opening another one,
 * signs the member out on the site at the next request without a second end row.
 */

const SS_UA = 'Mozilla/5.0 (support desk)';

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications, users, site_settings, platform_settings, audit_logs restart identity cascade');
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['name' => 'Nha khoa Tâm Đức']);
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['name' => 'Cơ khí An Vinh']);
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    app()->setLocale('vi');
    Notification::fake();
    Mail::fake();
});

function ssStaff(string $role, string $name = ''): User
{
    $user = User::factory()->create(['name' => $name !== '' ? $name : 'Staff '.$role]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

/** A fresh user holding the corporate role template $name in $site. */
function ssMember(int $site, string $name, string $label = ''): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];
    $user = User::factory()->create($label !== '' ? ['name' => $label] : []);

    app(Tenancy::class)->enter($site, function () use ($name, $tpl, $perms, $user): void {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);
        $user->assignRole($role);
    });
    app(TeamContext::class)->forget();

    return $user;
}

/**
 * An entered row as the site writes it, $ago minutes back.
 *
 * @param  array<string, mixed>  $meta
 */
function ssSession(User $staff, User $member, int $site = 1, int $ago = 1, int $minutes = 30, array $meta = [], ?CarbonImmutable $at = null): string
{
    $code = (string) Str::ulid();
    AuditLog::query()->create([
        'action' => SupportSessions::ENTERED,
        'actor_id' => $member->getKey(),
        'actor_label' => $member->email,
        'impersonator_id' => $staff->getKey(),
        'impersonator_label' => SupportSession::staffLabel($staff),
        'site_id' => $site,
        'support_session_ulid' => $code,
        'meta' => $meta + ['reason' => 'Khách nhờ sửa trang chủ', 'minutes' => $minutes, 'member_name' => SupportSession::labelOf($member)],
        'created_at' => $at ?? CarbonImmutable::now()->subMinutes($ago),
    ]);

    return $code;
}

function ssEnd(string $code, string $why, int $site = 1, int $ago = 0, ?User $actor = null): void
{
    AuditLog::query()->create([
        'action' => SupportSessions::ENDED,
        'actor_id' => $actor?->getKey(),
        'actor_label' => $actor?->email,
        'site_id' => $site,
        'support_session_ulid' => $code,
        'meta' => ['why' => $why],
        'created_at' => CarbonImmutable::now()->subMinutes($ago),
    ]);
}

/** @return list<string> the session codes of one page */
function ssCodes(array $page): array
{
    return array_column($page['rows'], 'session');
}

function sessions(): SupportSessions
{
    return app(SupportSessions::class);
}

/** Run $action as the console does: on the platform host, without the site host's session. */
function fromConsole(User $actor, Closure $action): mixed
{
    $site = app('request');
    app()->instance('request', Request::create('http://'.config('tenancy.platform_host').'/support'));
    Auth::forgetGuards();
    Auth::setUser($actor);

    try {
        return $action();
    } finally {
        Auth::forgetGuards();
        app()->instance('request', $site);
    }
}

function ssEnter(mixed $test, User $staff, User $member, int $site, string $host, int $minutes = 30): mixed
{
    $token = app(SupportSignIn::class)->issue($staff, Site::query()->findOrFail($site), $member, 'Khách nhờ sửa trang chủ', $minutes, SS_UA);

    return $test->withHeader('User-Agent', SS_UA)->get("http://{$host}/admin/support/enter?token={$token}");
}

/** Another browser: the site host's session starts empty (what it held is handed back). */
function otherBrowser(): array
{
    $store = app('session')->driver();
    $held = $store->all();
    $store->flush();
    Auth::forgetGuards();

    return $held;
}

function backToBrowser(array $held): void
{
    $store = app('session')->driver();
    $store->flush();
    $store->put($held);
    Auth::forgetGuards();
}

// ── the list ────────────────────────────────────────────────────────────────────────

it('lists sessions newest first and pages both ways by the session code', function (): void {
    $staff = ssStaff('support');
    $member = ssMember(1, 'editor');
    $codes = [];
    foreach ([50, 40, 30, 20, 10] as $ago) {
        $codes[] = ssSession($staff, $member, ago: $ago);
    }
    [$s1, $s2, $s3, $s4, $s5] = $codes;

    $first = sessions()->page($staff, per: 2);
    expect(ssCodes($first))->toBe([$s5, $s4])
        ->and($first['newer'])->toBeNull()
        ->and($first['older'])->toBe($s4);

    $second = sessions()->page($staff, after: $first['older'], per: 2);
    expect(ssCodes($second))->toBe([$s3, $s2])
        ->and($second['newer'])->toBe($s3);

    $last = sessions()->page($staff, after: $second['older'], per: 2);
    expect(ssCodes($last))->toBe([$s1])->and($last['older'])->toBeNull();

    $back = sessions()->page($staff, before: $second['newer'], per: 2);
    expect(ssCodes($back))->toBe([$s5, $s4])->and($back['newer'])->toBeNull();

    // a code that names no session gives the first page
    expect(ssCodes(sessions()->page($staff, after: (string) Str::ulid(), per: 2)))->toBe([$s5, $s4]);
});

it('pages sessions entered in the same instant without skipping or repeating any', function (): void {
    $staff = ssStaff('support');
    $member = ssMember(1, 'editor');
    $at = CarbonImmutable::now()->subMinutes(5)->startOfSecond();
    $codes = [];
    foreach (range(1, 5) as $_) {
        $codes[] = ssSession($staff, $member, at: $at);
    }

    $seen = [];
    $after = '';
    do {
        $page = sessions()->page($staff, after: $after, per: 2);
        $seen = [...$seen, ...ssCodes($page)];
        $after = (string) $page['older'];
    } while ($after !== '');

    expect($seen)->toBe(array_reverse($codes));
});

it('tells open, ended and expired sessions apart, with why and when each ends, and counts each tab', function (): void {
    $staff = ssStaff('support');
    $member = ssMember(1, 'editor');
    $open = ssSession($staff, $member, ago: 5, minutes: 30);
    $left = ssSession($staff, $member, ago: 20, minutes: 30);
    ssEnd($left, 'left', ago: 12);
    $expired = ssSession($staff, $member, ago: 45, minutes: 15);
    $late = ssSession($staff, $member, ago: 120, minutes: 15);
    ssEnd($late, 'expired', ago: 90); // written at the first request after its time was up

    $rows = collect(sessions()->page($staff)['rows'])->keyBy('session');

    expect($rows[$open]['status'])->toBe('open')
        ->and($rows[$open]['why'])->toBeNull()
        ->and($rows[$open]['ends_at']->equalTo($rows[$open]['started_at']->addMinutes(30)))->toBeTrue()
        ->and($rows[$open]['can_end'])->toBeTrue()
        ->and($rows[$left]['status'])->toBe('ended')
        ->and($rows[$left]['why'])->toBe('left')
        ->and($rows[$left]['ends_at']->equalTo($rows[$left]['started_at']->addMinutes(8)))->toBeTrue()
        ->and($rows[$left]['can_end'])->toBeFalse()
        ->and($rows[$expired]['status'])->toBe('expired')
        ->and($rows[$expired]['why'])->toBe('expired')
        ->and($rows[$expired]['ends_at']->equalTo($rows[$expired]['started_at']->addMinutes(15)))->toBeTrue()
        // the end row came later than the deadline: the session ended at its deadline
        ->and($rows[$late]['status'])->toBe('ended')
        ->and($rows[$late]['ends_at']->equalTo($rows[$late]['started_at']->addMinutes(15)))->toBeTrue();

    expect(ssCodes(sessions()->page($staff, SupportSessions::TAB_OPEN)))->toBe([$open])
        ->and(ssCodes(sessions()->page($staff, SupportSessions::TAB_ENDED)))->toBe([$left, $expired, $late])
        ->and(sessions()->counts($staff))->toBe(['all' => 4, 'open' => 1, 'ended' => 3]);
});

it('shows the site, the member with their roles, the staff member and the reason', function (): void {
    $staff = ssStaff('support', 'Lê Hoàng Phúc');
    $member = ssMember(2, 'editor', 'Nguyễn Ngọc Anh');
    ssSession($staff, $member, site: 2, meta: ['member_roles' => [['name' => 'owner', 'system' => true], ['name' => 'Kế toán', 'system' => false]]]);
    // written before the roles were recorded: the member's current name, no role
    $old = User::factory()->create(['name' => 'Trần Văn Bình']);
    $code = (string) Str::ulid();
    AuditLog::query()->create(['action' => SupportSessions::ENTERED, 'actor_id' => $old->id, 'actor_label' => $old->email,
        'impersonator_id' => $staff->id, 'impersonator_label' => 'Lê Hoàng Phúc', 'site_id' => 1, 'support_session_ulid' => $code,
        'meta' => ['reason' => 'Cũ', 'minutes' => 15], 'created_at' => now()->subHours(3)]);

    [$new, $older] = sessions()->page($staff)['rows'];

    expect($new)->toMatchArray([
        'site_ulid' => 'SITEB000000000000000000001', 'site_name' => 'Cơ khí An Vinh',
        'member' => 'Nguyễn Ngọc Anh', 'member_roles' => ['Chủ website', 'Kế toán'],
        'staff' => 'Lê Hoàng Phúc', 'reason' => 'Khách nhờ sửa trang chủ', 'minutes' => 30,
    ])
        ->and($new)->not->toHaveKeys(['id', 'site_id', 'actor_id', 'impersonator_id'])
        ->and($older)->toMatchArray(['session' => $code, 'member' => 'Trần Văn Bình', 'member_roles' => [], 'site_name' => 'Nha khoa Tâm Đức']);
});

it('narrows by staff member and by period, the counts too', function (): void {
    $a = ssStaff('support', 'Nhân viên A');
    $b = ssStaff('support', 'Nhân viên B');
    $member = ssMember(1, 'editor');
    $recent = ssSession($a, $member, ago: 5);
    $tenDays = ssSession($b, $member, ago: 10 * 24 * 60);
    $fortyDays = ssSession($a, $member, ago: 40 * 24 * 60);
    $hundredDays = ssSession($a, $member, ago: 100 * 24 * 60);

    expect(ssCodes(sessions()->page($a, staff: [$b->ulid])))->toBe([$tenDays])
        ->and(ssCodes(sessions()->page($a, staff: [$a->ulid])))->toBe([$recent, $fortyDays, $hundredDays])
        ->and(ssCodes(sessions()->page($a, staff: [$a->ulid, $b->ulid])))->toHaveCount(4)
        ->and(sessions()->counts($a, staff: [$b->ulid]))->toBe(['all' => 1, 'open' => 0, 'ended' => 1])
        ->and(ssCodes(sessions()->page($a, days: 7)))->toBe([$recent])
        ->and(ssCodes(sessions()->page($a, days: 30)))->toBe([$recent, $tenDays])
        ->and(ssCodes(sessions()->page($a, days: 90)))->toBe([$recent, $tenDays, $fortyDays])
        ->and(sessions()->counts($a, days: 30))->toBe(['all' => 2, 'open' => 1, 'ended' => 1])
        // a period that is not one of the choices is no filter at all
        ->and(ssCodes(sessions()->page($a, days: 3)))->toHaveCount(4);
});

it('reaches back with no period chosen only as far as the log keeps rows: 24 full months before the running one', function (): void {
    $staff = ssStaff('support');
    $code = ssSession($staff, ssMember(1, 'editor'));

    $this->travelTo(now()->addMonthsNoOverflow(24));
    expect(ssCodes(sessions()->page($staff)))->toBe([$code])
        ->and(sessions()->counts($staff))->toBe(['all' => 1, 'open' => 0, 'ended' => 1]);

    $this->travelTo(now()->addMonthsNoOverflow(1));
    expect(sessions()->page($staff)['rows'])->toBe([])
        ->and(sessions()->counts($staff))->toBe(['all' => 0, 'open' => 0, 'ended' => 0]);
});

it('searches site, member, staff member and reason without diacritics or case', function (string $needle, string $expected): void {
    $staff = ssStaff('support', 'Lê Hoàng Phúc');
    $other = ssStaff('support', 'Phạm Thu');
    $anh = ssMember(1, 'editor', 'Nguyễn Ngọc Anh');
    $binh = ssMember(2, 'editor', 'Trần Bình');
    $codes = [
        'site' => ssSession($other, $binh, site: 1, ago: 30, meta: ['reason' => 'Cập nhật giá', 'member_name' => 'Khác']),
        'member' => ssSession($other, $anh, site: 2, ago: 20, meta: ['reason' => 'Cập nhật giá']),
        'staff' => ssSession($staff, $binh, site: 2, ago: 10, meta: ['reason' => 'Cập nhật giá']),
        'reason' => ssSession($other, $binh, site: 2, ago: 5, meta: ['reason' => 'Khách nhờ SỬA trang chủ']),
    ];

    expect(ssCodes(sessions()->page($staff, search: $needle)))->toBe([$codes[$expected]])
        ->and(sessions()->counts($staff, search: $needle)['all'])->toBe(1);
})->with([
    'site name' => ['tam duc', 'site'],
    'member name' => ['NGUYEN NGOC', 'member'],
    'staff label' => ['le hoang', 'staff'],
    'reason' => ['sua trang', 'reason'],
]);

it('finds a member by the name they have now when the row does not carry it, and finds nothing for a stranger', function (): void {
    $staff = ssStaff('support');
    $member = ssMember(1, 'editor', 'Đỗ Minh Châu');
    $code = ssSession($staff, $member, meta: ['member_name' => '']);

    expect(ssCodes(sessions()->page($staff, search: 'do minh chau')))->toBe([$code])
        ->and(ssCodes(sessions()->page($staff, search: '50%_x')))->toBe([])
        ->and(sessions()->counts($staff, search: 'không ai'))->toBe(['all' => 0, 'open' => 0, 'ended' => 0]);
});

it('keeps each end row to its own session, and never lists rows of a session as sessions', function (): void {
    $staff = ssStaff('support');
    $a = ssMember(1, 'editor');
    $b = ssMember(2, 'editor');
    $onA = ssSession($staff, $a, site: 1, ago: 10);
    $onB = ssSession($staff, $b, site: 2, ago: 5);
    ssEnd($onA, 'left', site: 1);
    // ordinary rows written during a support session carry the staff member and the code
    AuditLog::query()->create(['action' => 'cms.entry.updated', 'actor_id' => $b->id, 'impersonator_id' => $staff->id,
        'impersonator_label' => 'x', 'site_id' => 2, 'support_session_ulid' => $onB, 'meta' => []]);

    $rows = collect(sessions()->page($staff)['rows'])->keyBy('session');

    expect($rows->keys()->all())->toBe([$onB, $onA])
        ->and($rows[$onB]['status'])->toBe('open')
        ->and($rows[$onB]['site_name'])->toBe('Cơ khí An Vinh')
        ->and($rows[$onA]['status'])->toBe('ended')
        ->and($rows[$onA]['site_name'])->toBe('Nha khoa Tâm Đức');
});

it('lets only staff who may sign in as members read the list', function (string $what): void {
    $billing = ssStaff('billing');

    expect(fn () => $what === 'page' ? sessions()->page($billing) : sessions()->counts($billing))
        ->toThrow(AuthorizationException::class);
})->with(['page', 'counts']);

it('offers "end" on an open session to its staff member and to the platform super-admin only', function (): void {
    $mine = ssStaff('support');
    $colleague = ssStaff('support');
    $super = ssStaff('super_admin');
    $code = ssSession($colleague, ssMember(1, 'editor'));

    expect(sessions()->page($mine)['rows'][0])->toMatchArray(['session' => $code, 'can_end' => false])
        ->and(sessions()->page($colleague)['rows'][0]['can_end'])->toBeTrue()
        ->and(sessions()->page($super)['rows'][0]['can_end'])->toBeTrue();
});

it('reads one page with a fixed number of queries, however many sessions it shows', function (): void {
    $budget = function (int $sessions): int {
        DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
        $staff = ssStaff('support');
        foreach (range(1, $sessions) as $i) {
            $code = ssSession($staff, User::factory()->create(), site: $i % 2 + 1, ago: $i);
            if ($i % 3 === 0) {
                ssEnd($code, 'ended_by', site: $i % 2 + 1, actor: ssStaff('support'));
            }
        }
        app(PlatformAccess::class)->forgetMemo();

        sessions()->page($staff, per: 25); // the permission cache is warm on a real request

        return countQueries(fn () => sessions()->page($staff, per: 25));
    };

    // rows · end rows · sites · people, plus the viewer's permission checks
    expect($budget(12))->toBe($budget(3))->toBeLessThanOrEqual(7);
});

// ── recorded at enter ───────────────────────────────────────────────────────────────

it('writes the member\'s name and roles into the entered row, as the site knows them', function (): void {
    $staff = ssStaff('support');
    $member = ssMember(1, 'editor', 'Nguyễn Ngọc Anh');

    ssEnter($this, $staff, $member, 1, 'a.test')->assertStatus(303);

    expect(AuditLog::query()->where('action', SupportSessions::ENTERED)->sole()->meta)->toBe([
        'reason' => 'Khách nhờ sửa trang chủ',
        'minutes' => 30,
        'member_name' => 'Nguyễn Ngọc Anh',
        'member_roles' => [['name' => 'editor', 'system' => true]],
    ])
        ->and(sessions()->page($staff)['rows'][0]['member_roles'])->toBe(['Biên tập']);
});

// ── ending from the console ─────────────────────────────────────────────────────────

it('lets a staff member end their own session, on record as ended by them', function (): void {
    $staff = ssStaff('support');
    $code = ssSession($staff, ssMember(2, 'editor'), site: 2);

    expect(fromConsole($staff, fn (): bool => app(SupportSignIn::class)->end($staff, $code)))->toBeTrue();

    $end = AuditLog::query()->where('action', SupportSessions::ENDED)->sole();
    expect($end->meta)->toBe(['why' => 'ended_by'])
        ->and($end->support_session_ulid)->toBe($code)
        ->and($end->site_id)->toBe(2)
        ->and($end->actor_id)->toBe($staff->id)
        ->and($end->getAttribute('impersonator_id'))->toBeNull();

    $row = sessions()->page($staff)['rows'][0];
    expect($row['status'])->toBe('ended')->and($row['why'])->toBe('ended_by')->and($row['ended_by'])->toBe('Staff support');
});

it('refuses ending a colleague\'s session to staff who are not the platform super-admin', function (): void {
    $colleague = ssStaff('support');
    $staff = ssStaff('support');
    $code = ssSession($colleague, ssMember(1, 'editor'));

    expect(fn () => fromConsole($staff, fn (): bool => app(SupportSignIn::class)->end($staff, $code)))
        ->toThrow(AuthorizationException::class, 'Chỉ Quản trị nền tảng kết thúc được phiên của người khác.')
        ->and(AuditLog::query()->where('action', SupportSessions::ENDED)->count())->toBe(0);

    $super = ssStaff('super_admin');
    expect(fromConsole($super, fn (): bool => app(SupportSignIn::class)->end($super, $code)))->toBeTrue()
        ->and(AuditLog::query()->where('action', SupportSessions::ENDED)->sole()->actor_id)->toBe($super->id);
});

it('refuses ending to staff without the right, and for a code that names no session', function (): void {
    $billing = ssStaff('billing');
    $staff = ssStaff('support');
    $code = ssSession($staff, ssMember(1, 'editor'));

    expect(fn () => app(SupportSignIn::class)->end($billing, $code))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SupportSignIn::class)->end($staff, (string) Str::ulid()))->toThrow(ValidationException::class)
        ->and(fn () => app(SupportSignIn::class)->end($staff, ''))->toThrow(ValidationException::class)
        ->and(AuditLog::query()->where('action', SupportSessions::ENDED)->count())->toBe(0);
});

it('writes nothing more when a session is ended twice, or is already over', function (): void {
    $staff = ssStaff('support');
    $member = ssMember(1, 'editor');
    $code = ssSession($staff, $member);
    $expired = ssSession($staff, $member, ago: 40, minutes: 15);
    $left = ssSession($staff, $member, ago: 10);
    ssEnd($left, 'left');

    $end = fn (string $c): bool => fromConsole($staff, fn (): bool => app(SupportSignIn::class)->end($staff, $c));

    expect($end($code))->toBeTrue()
        ->and($end($code))->toBeFalse()
        ->and($end($expired))->toBeFalse()
        ->and($end($left))->toBeFalse()
        ->and(AuditLog::query()->where('action', SupportSessions::ENDED)->pluck('support_session_ulid')->sort()->values()->all())
        ->toBe(collect([$code, $left])->sort()->values()->all());
});

it('signs the member out at the next request once the console ends the session, writing no second row', function (string $via): void {
    $staff = ssStaff('support');
    ssEnter($this, $staff, ssMember(1, 'editor'), 1, 'a.test')->assertStatus(303);
    $this->get('http://a.test/admin')->assertOk();
    $code = (string) AuditLog::query()->where('action', SupportSessions::ENTERED)->value('support_session_ulid');

    expect(fromConsole($staff, fn (): bool => app(SupportSignIn::class)->end($staff, $code)))->toBeTrue();

    $via === 'page'
        ? $this->get('http://a.test/admin')->assertRedirectContains('/admin/login')
        : $this->withHeader('X-Livewire', '1')->post('http://a.test/livewire/update', [])->assertForbidden();

    $this->assertGuest();
    expect(AuditLog::query()->where('action', SupportSessions::ENDED)->get(['meta', 'support_session_ulid'])->map->only(['meta', 'support_session_ulid'])->all())
        ->toBe([['meta' => ['why' => 'ended_by'], 'support_session_ulid' => $code]]);
})->with(['page', 'livewire']);

it('keeps a session going when only another session\'s end is on record', function (): void {
    $staff = ssStaff('support');
    $other = ssSession(ssStaff('support'), ssMember(2, 'editor'), site: 2, ago: 3);
    ssEnter($this, $staff, ssMember(1, 'editor'), 1, 'a.test')->assertStatus(303);
    ssEnd($other, 'left', site: 2);

    $this->get('http://a.test/admin')->assertOk();
    $this->assertAuthenticated();
});

// ── one open session per staff member ───────────────────────────────────────────────

it('ends the staff member\'s other open session, on another site and browser, when they enter a new one', function (): void {
    $staff = ssStaff('support');
    $colleague = ssStaff('support');
    $colleagues = ssSession($colleague, ssMember(1, 'viewer'), site: 1);
    ssEnter($this, $staff, ssMember(1, 'editor'), 1, 'a.test')->assertStatus(303);
    $first = (string) AuditLog::query()->where('action', SupportSessions::ENTERED)->where('site_id', 1)->whereNotNull('impersonator_id')
        ->where('impersonator_id', $staff->id)->value('support_session_ulid');
    $browserOne = otherBrowser();

    ssEnter($this, $staff, ssMember(2, 'editor'), 2, 'b.test')->assertStatus(303);
    $second = (string) AuditLog::query()->where('action', SupportSessions::ENTERED)->where('site_id', 2)->value('support_session_ulid');

    $end = AuditLog::query()->where('action', SupportSessions::ENDED)->sole();
    expect($end->meta)->toBe(['why' => 'replaced'])
        ->and($end->support_session_ulid)->toBe($first)
        ->and($end->site_id)->toBe(1)
        ->and($end->actor_id)->toBe($staff->id);

    // the first browser is signed out at its next request, and no second end row is written
    $this->get('http://b.test/admin')->assertOk();
    backToBrowser($browserOne);
    $this->get('http://a.test/admin')->assertRedirectContains('/admin/login');
    $this->assertGuest();

    $rows = collect(sessions()->page($staff)['rows'])->keyBy('session');
    expect(AuditLog::query()->where('action', SupportSessions::ENDED)->count())->toBe(1)
        ->and($rows[$first]['why'])->toBe('replaced')
        ->and($rows[$second]['status'])->toBe('open')
        ->and($rows[$colleagues]['status'])->toBe('open');
});

it('starts no session and keeps no entered row when the enter fails after writing it, and leaves the browser signed out', function (): void {
    $staff = ssStaff('support');
    $member = ssMember(1, 'editor');
    // The row is written, then the step after it fails (the owners' notices cannot be queued):
    // the row must not stay behind for the console to list as open.
    ssMember(1, 'owner');
    app()->instance(ChannelManager::class, new class(app()) extends ChannelManager
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('queue unavailable');
        }
    });

    ssEnter($this, $staff, $member, 1, 'a.test')->assertServerError();

    $this->assertGuest();
    expect(app('session')->driver()->all())->not->toHaveKey('support_session')
        ->and(AuditLog::query()->where('action', SupportSessions::ENTERED)->count())->toBe(0);
    $this->get('http://a.test/admin')->assertRedirectContains('/admin/login');
});

// ── the audit row's session code ────────────────────────────────────────────────────

it('takes the code of the session an end row names only outside a support session', function (): void {
    $member = ssMember(1, 'editor');
    $inside = (string) Str::ulid();
    $named = (string) Str::ulid();

    fromConsole(ssStaff('support'), fn () => Audit::record(AuditService::SUPPORT_SESSION_ENDED, ['support_session_ulid' => $named]));

    $session = app('session')->driver();
    $session->flush();
    request()->setLaravelSession($session);
    auth()->login($member);
    app(SupportSession::class)->start(ssStaff('support'), $member, 1, 'Kiểm tra', now()->addMinutes(30), $inside);
    app(Tenancy::class)->enter(1, fn () => Audit::record(AuditService::SUPPORT_SESSION_ENDED, ['support_session_ulid' => $named]));
    $session->flush();

    expect(AuditLog::query()->orderBy('id')->pluck('support_session_ulid')->all())->toBe([$named, $inside]);
});
