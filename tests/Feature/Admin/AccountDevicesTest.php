<?php

use App\Modules\Admin\Livewire\Account;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SignedInDevices;
use App\Modules\Admin\Support\SupportSessions;
use App\Modules\Admin\Support\SupportSignIn;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Request;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * "Phiên đăng nhập" on My account: the member's signed-in devices, kept in a small index next to
 * the sessions. A member sees and ends only their own devices, by an opaque key (never a session
 * id); ending every other device also ends a support session (on record, on that session's own
 * website) without counting it; a device signed out elsewhere is signed out at its next request;
 * staff signed in for support only look.
 */

const DV_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const DV_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
const DV_ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36';

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['name' => 'Nha khoa Tâm Đức']);
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['name' => 'Cơ khí An Vinh']);
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    fakePwnedVerifier();
    app()->setLocale('vi');
    forgetMonitoringKeys('signed_in_device');
});

afterEach(function (): void {
    forgetMonitoringKeys('signed_in_device');
});

function dvMember(): User
{
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    return $user->fresh();
}

function dvDevices(): SignedInDevices
{
    return app(SignedInDevices::class);
}

/** A session id as Laravel makes them (40 letters and digits). */
function dvSid(): string
{
    return Str::random(40);
}

/**
 * Another browser of $user, signed in on $site: its session is in the store and in the index.
 *
 * @param  array{site_id: int, session_ulid: string|null}|null  $support
 */
function dvOtherDevice(User $user, string $ua = DV_IPHONE, ?int $site = 1, ?array $support = null, int $ago = 0): string
{
    $sid = dvSid();
    app('session.store')->getHandler()->write($sid, 'a:0:{}');
    $key = dvDevices()->record($user, $sid, $ua, $site, $support);
    if ($ago > 0) {
        Redis::zadd("signed_in_devices:{$user->id}", now()->getTimestamp() - $ago * 60, $key);
    }

    return $sid;
}

function dvAlive(string $sid): bool
{
    return app('session.store')->getHandler()->read($sid) !== '';
}

/** This test's own session, as the account page reads it. */
function dvThisSession(): string
{
    $sid = dvSid();
    session()->setId($sid);
    session()->start();

    return $sid;
}

/** An open support session of $member on $site, as the site's entered row records it. */
function dvSupportCode(User $member, int $site): string
{
    $staff = User::factory()->create(['name' => 'Nhân viên Hà']);
    $code = (string) Str::ulid();
    AuditLog::query()->create([
        'action' => SupportSessions::ENTERED,
        'actor_id' => $member->getKey(),
        'actor_label' => $member->email,
        'impersonator_id' => $staff->getKey(),
        'impersonator_label' => SupportSession::staffLabel($staff),
        'site_id' => $site,
        'support_session_ulid' => $code,
        'meta' => ['reason' => 'Khách nhờ sửa trang chủ', 'minutes' => 30, 'member_name' => SupportSession::labelOf($member)],
        'created_at' => CarbonImmutable::now()->subMinutes(2),
    ]);

    return $code;
}

/** @return list<AuditLog> */
function dvEndRows(string $code): array
{
    return AuditLog::query()->where('action', SupportSessions::ENDED)->where('support_session_ulid', $code)->get()->all();
}

function dvSignedOutRows(User $user): int
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'auth.session.signed_out')->where('actor_id', $user->id)->count();
}

// ── what the index keeps ───────────────────────────────────────────────────────────

it('reads the kind of device and browser from the user agent', function (string $ua, string $device, string $browser): void {
    expect(SignedInDevices::describe($ua))->toBe([$device, $browser]);
})->with([
    'windows chrome' => [DV_WINDOWS, 'windows', 'chrome'],
    'iphone safari' => [DV_IPHONE, 'iphone', 'safari'],
    'android samsung' => [DV_ANDROID, 'android', 'samsung'],
    'mac safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'mac', 'safari'],
    'windows edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0', 'windows', 'edge'],
    'linux firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0', 'linux', 'firefox'],
    'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.7 Mobile/15E148 Safari/604.1', 'ipad', 'safari'],
    'android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'android_tablet', 'chrome'],
    'coc coc' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) coc_coc_browser/137.0.0 Chrome/131.0.0.0 Safari/537.36', 'windows', 'coccoc'],
    'nothing' => ['', 'other', 'other'],
]);

it('names a device by an opaque key and keeps neither the session id in it nor the user agent or address', function (): void {
    $user = dvMember();
    $sid = dvOtherDevice($user, DV_WINDOWS);
    $key = SignedInDevices::keyOf($sid);

    expect($key)->toMatch('/\A[0-9a-f]{32}\z/')
        ->and($key)->not->toContain(substr($sid, 0, 8))
        ->and(SignedInDevices::keyOf(dvSid()))->not->toBe($key);

    $entry = Redis::hgetall("signed_in_device:{$user->id}:{$key}");
    expect($entry)->toHaveKeys(['sid', 'fp', 'since', 'kind', 'site', 'device', 'browser'])
        ->and($entry['device'])->toBe('windows')->and($entry['browser'])->toBe('chrome')
        ->and(implode('|', $entry))->not->toContain('Mozilla')
        ->and($entry)->not->toHaveKeys(['ip', 'ua', 'user_agent']);
});

it('keeps only the website and the code of a support session, never the staff member\'s device', function (): void {
    $user = dvMember();
    $sid = dvOtherDevice($user, DV_WINDOWS, 1, ['site_id' => 2, 'session_ulid' => 'CODE']);

    $entry = Redis::hgetall("signed_in_device:{$user->id}:".SignedInDevices::keyOf($sid));
    expect($entry['kind'])->toBe('support')->and($entry['site'])->toBe('2')->and($entry['code'])->toBe('CODE')
        ->and($entry)->not->toHaveKeys(['device', 'browser']);
});

// ── the middleware ─────────────────────────────────────────────────────────────────

it('indexes the session on a signed-in admin page and moves its last use at most every five minutes', function (): void {
    $user = dvMember();
    $sid = dvSid();
    $key = SignedInDevices::keyOf($sid);
    $open = fn () => $this->actingAs($user)->withCookie(config('session.cookie'), $sid)
        ->withHeader('User-Agent', DV_WINDOWS)->get('http://a.test/admin/account')->assertOk();

    $open();
    $first = (int) Redis::zscore("signed_in_devices:{$user->id}", $key);
    expect($first)->toBeGreaterThan(0)
        ->and(Redis::hget("signed_in_device:{$user->id}:{$key}", 'site'))->toBe('1');

    $this->travel(4)->minutes();
    $open();
    expect((int) Redis::zscore("signed_in_devices:{$user->id}", $key))->toBe($first);

    $this->travel(2)->minutes();
    $open();
    expect((int) Redis::zscore("signed_in_devices:{$user->id}", $key))->toBeGreaterThanOrEqual($first + 360);
});

it('signs a device out at its next request once it was signed out from another one', function (): void {
    $user = dvMember();
    $sid = dvSid();
    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')->assertOk();

    dvDevices()->forget($user->id, SignedInDevices::keyOf($sid));

    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')
        ->assertRedirectContains('/admin/login');
    $this->assertGuest();
});

it('signs a device out at its next request once the password changed elsewhere, and lists it no more', function (): void {
    $user = dvMember();
    $sid = dvSid();
    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')->assertOk();

    $user->forceFill(['password' => Hash::make('new-password-456')])->save();
    expect(collect(dvDevices()->rows($user->fresh(), dvSid(), DV_IPHONE, 1))->pluck('key'))->not->toContain(SignedInDevices::keyOf($sid));

    $this->actingAs($user->fresh())->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')
        ->assertRedirectContains('/admin/login');
    $this->assertGuest();
});

it('never brings back a signed-out device when a request of it still in flight moves its last use', function (): void {
    $user = dvMember();
    $sid = dvOtherDevice($user);
    $key = SignedInDevices::keyOf($sid);
    expect(dvDevices()->refresh($user->id, $key))->toBeTrue();

    dvDevices()->forget($user->id, $key);

    expect(dvDevices()->refresh($user->id, $key))->toBeFalse()
        ->and(dvDevices()->exists($user->id, $key))->toBeFalse()
        ->and(Redis::zscore("signed_in_devices:{$user->id}", $key))->toBeFalsy();
});

it('never indexes again a session that was ended, even from a request of it still in flight', function (): void {
    $user = dvMember();
    dvThisSession();
    $phone = dvOtherDevice($user, DV_IPHONE);
    $key = SignedInDevices::keyOf($phone);

    expect(dvDevices()->signOut($user, session()->getId(), $key))->not->toBeNull()
        ->and(dvDevices()->record($user, $phone, DV_IPHONE, 1))->toBeNull()
        ->and(dvDevices()->exists($user->id, $key))->toBeFalse()
        ->and(dvDevices()->ended($user->id, $key))->toBeTrue();
});

it('signs out a session written back after it was ended, at its next request', function (): void {
    $user = dvMember();
    $sid = dvSid();
    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')->assertOk();
    $payload = app('session.store')->getHandler()->read($sid);

    expect(dvDevices()->signOut($user, dvSid(), SignedInDevices::keyOf($sid)))->not->toBeNull();
    // a request of that device still in flight saves its session again when it finishes
    app('session.store')->getHandler()->write($sid, $payload);

    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')
        ->assertRedirectContains('/admin/login');
    $this->assertGuest();
    expect(dvDevices()->exists($user->id, SignedInDevices::keyOf($sid)))->toBeFalse();
});

it('signs out an ended session that was saved back without its marker', function (): void {
    $user = dvMember();
    $sid = dvSid();
    // two first requests at once: one indexed the session, the member ended it, the other (refused) saved last
    dvDevices()->forget($user->id, SignedInDevices::keyOf($sid));

    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')
        ->assertRedirectContains('/admin/login');
    $this->assertGuest();
    expect(dvDevices()->exists($user->id, SignedInDevices::keyOf($sid)))->toBeFalse();
});

it('answers a Livewire update of a signed-out device with the expired page, which signs in again', function (): void {
    $user = dvMember();
    $sid = dvSid();
    dvDevices()->forget($user->id, SignedInDevices::keyOf($sid));

    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->withHeader('X-Livewire', '1')
        ->post('http://a.test/livewire/update', [])
        ->assertStatus(419)
        ->assertSee('Phiên làm việc đã hết');
    $this->assertGuest();
});

it('keeps a member signed in whose entry expired while the session lived on, and indexes it again', function (): void {
    $user = dvMember();
    $sid = dvSid();
    $key = SignedInDevices::keyOf($sid);
    $open = fn () => $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account');
    $open()->assertOk();

    // the storefront kept the session alive; the entry ran out of time (not ended)
    Redis::del("signed_in_device:{$user->id}:{$key}");
    Redis::zrem("signed_in_devices:{$user->id}", $key);

    $open()->assertOk();
    $this->travel(6)->minutes();
    $open()->assertOk();
    expect(dvDevices()->exists($user->id, $key))->toBeTrue()->and(dvDevices()->ended($user->id, $key))->toBeFalse();
});

it('checks the password on /livewire/update too, as the admin pages do', function (): void {
    expect(Route::getRoutes()->getByName('livewire.update')->gatherMiddleware())->toContain('auth.session');
});

it('drops the entry of a session that signs out', function (): void {
    $user = dvMember();
    $sid = dvSid();
    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')->assertOk();
    expect(dvDevices()->exists($user->id, SignedInDevices::keyOf($sid)))->toBeTrue();

    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->post('http://a.test/admin/logout')->assertRedirect();

    expect(dvDevices()->exists($user->id, SignedInDevices::keyOf($sid)))->toBeFalse();
});

// ── the list ───────────────────────────────────────────────────────────────────────

it('lists this device first, then the member\'s other ones with browser, website and last use', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 08:00:00', 'UTC'));
    $user = dvMember();
    dvThisSession();
    dvOtherDevice($user, DV_IPHONE, 2, null, 125);
    dvOtherDevice($user, DV_ANDROID, 1, null, 20);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'sessions'])->test(Account::class)
        ->assertSeeInOrder(['Phiên đăng nhập', 'Đăng xuất mọi thiết bị khác', 'Thấy thiết bị lạ?', 'Thiết bị này',
            'Điện thoại Android', 'Samsung Internet · Nha khoa Tâm Đức · Hoạt động 20 phút trước',
            'iPhone', 'Safari · Cơ khí An Vinh · Hoạt động 2 giờ trước'])
        ->assertSee('Đăng xuất iPhone (Safari)')
        ->assertDontSee('Chỉ còn thiết bị này đang đăng nhập.'));
});

it('says only this device is signed in when there is no other', function (): void {
    $user = dvMember();
    dvThisSession();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'sessions'])->test(Account::class)
        ->assertSee('Thiết bị này')
        ->assertSee('Không còn thiết bị nào khác để đăng xuất.')
        ->assertSee('Chỉ còn thiết bị này đang đăng nhập.'));
});

it('leaves out a device whose session is gone from the store', function (): void {
    $user = dvMember();
    dvThisSession();
    $gone = dvOtherDevice($user, DV_IPHONE);
    app('session.store')->getHandler()->destroy($gone);
    $this->travel(3)->minutes();

    $rows = dvDevices()->rows($user, session()->getId(), DV_WINDOWS, 1);
    expect($rows)->toHaveCount(1)->and($rows[0]['current'])->toBeTrue()
        ->and(dvDevices()->exists($user->id, SignedInDevices::keyOf($gone)))->toBeFalse()
        // gone by itself, not ended: nothing to sign out
        ->and(dvDevices()->ended($user->id, SignedInDevices::keyOf($gone)))->toBeFalse();
});

it('keeps a device that just signed in although its session is not saved yet', function (): void {
    $user = dvMember();
    dvThisSession();
    $sid = dvSid();
    dvDevices()->record($user, $sid, DV_IPHONE, 1); // its request has not finished: nothing in the store yet

    expect(collect(dvDevices()->rows($user, session()->getId(), DV_WINDOWS, 1))->pluck('key'))->toContain(SignedInDevices::keyOf($sid))
        ->and(dvDevices()->exists($user->id, SignedInDevices::keyOf($sid)))->toBeTrue();
});

it('ends a support session on record when the member\'s password changed elsewhere', function (): void {
    $user = dvMember();
    dvThisSession();
    $code = dvSupportCode($user, 2);
    $support = dvOtherDevice($user, DV_WINDOWS, 2, ['site_id' => 2, 'session_ulid' => $code]);

    $user->forceFill(['password' => Hash::make('reset-by-link-789')])->save();
    dvDevices()->rows($user->fresh(), session()->getId(), DV_WINDOWS, 1);

    expect(dvAlive($support))->toBeFalse()
        ->and(dvEndRows($code))->toHaveCount(1)
        ->and(dvEndRows($code)[0]->site_id)->toBe(2);
});

it('never lists another member\'s devices', function (): void {
    $user = dvMember();
    $other = dvMember();
    dvThisSession();
    dvOtherDevice($other, DV_IPHONE);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'sessions'])->test(Account::class)
        ->assertDontSee('iPhone')
        ->assertSee('Chỉ còn thiết bị này đang đăng nhập.'));
});

it('does not list a support session yet (its row waits for its design)', function (): void {
    $user = dvMember();
    dvThisSession();
    dvOtherDevice($user, DV_IPHONE, 1, ['site_id' => 1, 'session_ulid' => dvSupportCode($user, 1)]);

    $rows = dvDevices()->rows($user, session()->getId(), DV_WINDOWS, 1);
    expect(array_column($rows, 'kind'))->toBe(['member', 'support']);

    // never "only this device" while staff are signed in as the member: ending the others ends it
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'sessions'])->test(Account::class)
        ->assertDontSee('iPhone')
        ->assertSee('Thấy thiết bị lạ?')
        ->assertDontSee('Chỉ còn thiết bị này đang đăng nhập.')
        ->assertDontSee('Không còn thiết bị nào khác để đăng xuất.'));
});

it('ends a support session that is the only other one with "Đăng xuất mọi thiết bị khác"', function (): void {
    $user = dvMember();
    dvThisSession();
    $code = dvSupportCode($user, 1);
    $support = dvOtherDevice($user, DV_WINDOWS, 1, ['site_id' => 1, 'session_ulid' => $code]);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'sessions'])->test(Account::class)
        ->call('signOutOtherDevices')
        ->assertSet('toast', 'Đã kết thúc phiên của nhân viên hỗ trợ.')
        ->assertSee('Chỉ còn thiết bị này đang đăng nhập.'));

    expect(dvAlive($support))->toBeFalse()->and(dvEndRows($code))->toHaveCount(1)->and(dvSignedOutRows($user))->toBe(0);
});

// ── signing out ────────────────────────────────────────────────────────────────────

it('signs one other device out at once, on record', function (): void {
    $user = dvMember();
    dvThisSession();
    $phone = dvOtherDevice($user, DV_IPHONE);
    $tablet = dvOtherDevice($user, DV_ANDROID);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'sessions'])->test(Account::class)
        ->call('signOutDevice', SignedInDevices::keyOf($phone))
        ->assertSet('toast', 'Đã đăng xuất iPhone.')
        ->assertDontSee('Đăng xuất iPhone (Safari)'));

    expect(dvAlive($phone))->toBeFalse()->and(dvAlive($tablet))->toBeTrue()
        ->and(dvDevices()->exists($user->id, SignedInDevices::keyOf($phone)))->toBeFalse()
        ->and(dvSignedOutRows($user))->toBe(1);
});

it('never signs out another member\'s device, whatever key is sent', function (): void {
    $user = dvMember();
    $other = dvMember();
    dvThisSession();
    $theirs = dvOtherDevice($other, DV_IPHONE);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->test(Account::class)
        ->call('signOutDevice', SignedInDevices::keyOf($theirs))
        ->assertSet('toast', 'Thiết bị đó đã đăng xuất rồi.')
        ->call('signOutDevice', $theirs)
        ->assertSet('toast', 'Thiết bị đó đã đăng xuất rồi.')
        ->call('signOutDevice', '../../'.SignedInDevices::keyOf($theirs))
        ->assertSet('toast', 'Thiết bị đó đã đăng xuất rồi.'));

    expect(dvAlive($theirs))->toBeTrue()
        ->and(dvDevices()->exists($other->id, SignedInDevices::keyOf($theirs)))->toBeTrue()
        ->and(dvSignedOutRows($user))->toBe(0);
});

it('does not sign out the device asking', function (): void {
    $user = dvMember();
    $sid = dvThisSession();
    app('session.store')->getHandler()->write($sid, 'a:0:{}');
    dvDevices()->record($user, $sid, DV_WINDOWS, 1);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->test(Account::class)
        ->call('signOutDevice', SignedInDevices::keyOf($sid))
        ->assertSet('toast', 'Thiết bị đó đã đăng xuất rồi.'));

    expect(dvAlive($sid))->toBeTrue()->and(dvDevices()->exists($user->id, SignedInDevices::keyOf($sid)))->toBeTrue();
});

it('signs every other device out, ends a support session on its own website, and counts only the member\'s devices', function (): void {
    $user = dvMember();
    dvThisSession();
    $phone = dvOtherDevice($user, DV_IPHONE);
    $tablet = dvOtherDevice($user, DV_ANDROID, 2);
    $code = dvSupportCode($user, 2);
    $support = dvOtherDevice($user, DV_WINDOWS, 2, ['site_id' => 2, 'session_ulid' => $code]);

    // the member is on website 1; the support session is on website 2
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'sessions'])->test(Account::class)
        ->call('signOutOtherDevices')
        ->assertSet('toast', 'Đã đăng xuất 2 thiết bị khác.')
        ->assertSee('Chỉ còn thiết bị này đang đăng nhập.'));

    expect(dvAlive($phone))->toBeFalse()->and(dvAlive($tablet))->toBeFalse()->and(dvAlive($support))->toBeFalse();

    $ended = dvEndRows($code);
    expect($ended)->toHaveCount(1)
        ->and($ended[0]->site_id)->toBe(2)
        ->and($ended[0]->actor_id)->toBe($user->id)
        ->and($ended[0]->impersonator_id)->toBeNull()
        ->and($ended[0]->meta)->toBe(['why' => 'member_ended'])
        ->and(app(SupportSessions::class)->hasEnded($code, CarbonImmutable::now()->subHour()))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'auth.session.signed_out')->where('actor_id', $user->id)->sole()->site_id)->toBe(1);
});

it('writes no second end row for a support session that is already over', function (): void {
    $user = dvMember();
    dvThisSession();
    $code = dvSupportCode($user, 1);
    AuditLog::query()->create([
        'action' => SupportSessions::ENDED, 'site_id' => 1, 'support_session_ulid' => $code,
        'meta' => ['why' => 'ended_by'], 'created_at' => CarbonImmutable::now()->subMinute(),
    ]);
    $support = dvOtherDevice($user, DV_WINDOWS, 1, ['site_id' => 1, 'session_ulid' => $code]);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->test(Account::class)
        ->call('signOutDevice', SignedInDevices::keyOf($support))
        ->assertSet('toast', 'Đã kết thúc phiên của nhân viên hỗ trợ.'));

    expect(dvAlive($support))->toBeFalse()->and(dvEndRows($code))->toHaveCount(1);
});

it('ends only a support session signed in as the member asking', function (): void {
    $user = dvMember();
    $other = dvMember();
    $code = dvSupportCode($other, 1);

    expect(app(SupportSignIn::class)->endAsMember($user, $code))->toBeFalse()
        ->and(dvEndRows($code))->toBe([]);
});

it('signs the other devices out on a password change and says how many', function (): void {
    $user = dvMember();
    dvThisSession();
    $phone = dvOtherDevice($user, DV_IPHONE);
    $tablet = dvOtherDevice($user, DV_ANDROID);
    $support = dvOtherDevice($user, DV_WINDOWS, 1, ['site_id' => 1, 'session_ulid' => dvSupportCode($user, 1)]);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'security'])->test(Account::class)
        ->set('current_password', 'old-password-123')
        ->set('password', 'mua-ca-phe-luc-7-gio')
        ->set('password_confirmation', 'mua-ca-phe-luc-7-gio')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertSet('passwordOthers', 2)
        ->assertSee('Đã đổi mật khẩu. 2 thiết bị khác đã tự đăng xuất.'));

    expect(dvAlive($phone))->toBeFalse()->and(dvAlive($tablet))->toBeFalse()->and(dvAlive($support))->toBeFalse();
});

it('says only that the password changed when no other device was signed in', function (): void {
    $user = dvMember();
    dvThisSession();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'security'])->test(Account::class)
        ->set('current_password', 'old-password-123')
        ->set('password', 'mua-ca-phe-luc-7-gio')
        ->set('password_confirmation', 'mua-ca-phe-luc-7-gio')
        ->call('changePassword')
        ->assertSet('passwordOthers', 0)
        ->assertSee('Đã đổi mật khẩu.')
        ->assertDontSee('thiết bị khác đã tự đăng xuất'));
});

it('only lets support staff look at the devices: signing out is refused during a support session', function (string $method, array $args): void {
    $user = dvMember();
    $phone = dvOtherDevice($user, DV_IPHONE);

    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($user);
    app(SupportSession::class)->start($user, $user, 1, 'Kiểm tra', now()->addMinutes(30));
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    $args = array_map(fn (string $a): string => $a === 'PHONE' ? SignedInDevices::keyOf($phone) : $a, $args);
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->withQueryParams(['tab' => 'sessions'])->test(Account::class)
        ->assertSee('iPhone')
        ->call($method, ...$args)
        ->assertForbidden());

    expect(dvAlive($phone))->toBeTrue();
})->with([
    'one device' => ['signOutDevice', ['PHONE']],
    'every other device' => ['signOutOtherDevices', []],
]);

// ── what an ended session can still reach, and the edges of the index ──────────────

/** A member who may manage settings on website 1, with Google sign-in and the mail OAuth app set up there. */
function dvOAuthManager(): User
{
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    app(Tenancy::class)->enter(1, function (): void {
        app(SettingsRepository::class)->put('oauth_social', 'google', ['client_id' => 'social-client', 'client_secret' => SecretCipher::encrypt('social-secret'), 'enabled' => true]);
        app(SettingsRepository::class)->put('email', 'config', ['method' => 'smtp_password', 'oauth' => ['client_id' => 'mail-client', 'client_secret' => SecretCipher::encrypt('mail-secret')]]);
    });
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'manager', ['entries.view', 'settings.manage']));
    app(TeamContext::class)->forget();

    return $user->fresh();
}

/**
 * Signs $sid in on an admin page, ends it from another device, then writes it back as a request of
 * it still in flight would (without its marker when $marker is false).
 */
function dvEndedWrittenBack(object $test, User $user, string $sid, bool $marker = true): string
{
    $test->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')->assertOk();
    $payload = app('session.store')->getHandler()->read($sid);
    if (! $marker) {
        $data = json_decode($payload, true);
        unset($data[SignedInDevices::MARKER]);
        $payload = (string) json_encode($data);
    }

    expect(dvDevices()->signOut($user, dvSid(), SignedInDevices::keyOf($sid)))->not->toBeNull();
    app('session.store')->getHandler()->write($sid, $payload);

    return $payload;
}

/** @return list<string> the OAuth states written while $run ran */
function dvOAuthStates(Closure $run): array
{
    $states = [];
    Event::listen(KeyWritten::class, function (KeyWritten $e) use (&$states): void {
        if (str_starts_with($e->key, 'oauth-state:')) {
            $states[] = $e->key;
        }
    });
    $run();

    return $states;
}

it('refuses the start of an OAuth link or sender connect to an ended session written back by a request in flight', function (string $purpose): void {
    $user = dvOAuthManager();
    $sid = dvSid();
    dvEndedWrittenBack($this, $user, $sid);

    $states = dvOAuthStates(fn () => $this->actingAs($user)->withCookie(config('session.cookie'), $sid)
        ->get("http://a.test/admin/oauth/google/start?purpose={$purpose}")
        ->assertRedirectContains('/admin/login'));

    $this->assertGuest();
    expect($states)->toBe([])
        ->and(DB::connection('pgsql_admin')->table('social_identities')->where('user_id', $user->id)->count())->toBe(0);
})->with(['connect', 'email']);

it('refuses the start of an OAuth link or sender connect from a session whose password changed elsewhere', function (string $purpose): void {
    $user = dvOAuthManager();
    $sid = dvSid();
    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')->assertOk();
    $user->forceFill(['password' => Hash::make('new-password-456')])->save();

    $states = dvOAuthStates(fn () => $this->actingAs($user->fresh())->withCookie(config('session.cookie'), $sid)
        ->get("http://a.test/admin/oauth/google/start?purpose={$purpose}")
        ->assertRedirectContains('/admin/login'));

    $this->assertGuest();
    expect($states)->toBe([]);
})->with(['connect', 'email']);

it('still starts an OAuth link or sender connect from a live session', function (string $purpose, string $client): void {
    $user = dvOAuthManager();
    $sid = dvSid();
    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')->assertOk();

    $states = dvOAuthStates(fn () => $this->actingAs($user)->withCookie(config('session.cookie'), $sid)
        ->get("http://a.test/admin/oauth/google/start?purpose={$purpose}")
        ->assertRedirectContains('accounts.google.com')
        ->assertRedirectContains("client_id={$client}"));

    $this->assertAuthenticatedAs($user);
    expect($states)->toHaveCount(1);
})->with([
    'link a sign-in account' => ['connect', 'social-client'],
    'connect the sender' => ['email', 'mail-client'],
]);

it('keeps social sign-in open to a guest', function (): void {
    dvOAuthManager();

    $states = dvOAuthStates(fn () => $this->get('http://a.test/admin/oauth/google/start?purpose=login')
        ->assertRedirectContains('accounts.google.com')
        ->assertRedirectContains('client_id=social-client'));

    expect($states)->toHaveCount(1);
});

it('stamps a session with the time it signed in', function (): void {
    $user = dvMember();
    dvThisSession();
    request()->setLaravelSession(app('session')->driver());
    $this->travelTo(CarbonImmutable::parse('2026-11-14 08:00:00', 'UTC'));

    auth()->login($user);

    expect(session()->get(SignedInDevices::SIGNED_IN_AT))->toBe(CarbonImmutable::parse('2026-11-14 08:00:00', 'UTC')->getTimestamp());
});

it('signs out a session written back after it was ended once its mark ran out, signed in over a month ago', function (bool $marker): void {
    $user = dvMember();
    $sid = dvSid();
    $key = SignedInDevices::keyOf($sid);
    $payload = dvEndedWrittenBack($this, $user, $sid, $marker);

    // kept alive by the storefront alone until its ended mark ran out (Valkey keeps its own clock:
    // the mark is dropped by hand)
    $this->travel(SignedInDevices::ENDED_TTL + 60)->seconds();
    app('session.store')->getHandler()->write($sid, $payload);
    Redis::del("signed_in_device_ended:{$user->id}:{$key}");

    $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')
        ->assertRedirectContains('/admin/login');
    $this->assertGuest();
    expect(dvDevices()->exists($user->id, $key))->toBeFalse();
})->with(['with its marker' => true, 'saved back without its marker' => false]);

it('keeps a session signed in over a month ago while the index still has it', function (): void {
    $user = dvMember();
    $sid = dvSid();
    $open = fn () => $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account');
    $open()->assertOk();
    $payload = app('session.store')->getHandler()->read($sid);

    $this->travel(SignedInDevices::ENDED_TTL + 60)->seconds();
    app('session.store')->getHandler()->write($sid, $payload); // used every day since

    $open()->assertOk();
    $this->assertAuthenticatedAs($user);
    expect(session()->get(SignedInDevices::SIGNED_IN_AT))->toBeLessThan(now()->getTimestamp() - SignedInDevices::ENDED_TTL);
});

it('marks a device ended before its session goes, so it is still signed out when the store fails in between', function (): void {
    $user = dvMember();
    dvThisSession();
    $phone = dvOtherDevice($user, DV_IPHONE);
    $key = SignedInDevices::keyOf($phone);

    $store = app('session.store');
    $real = $store->getHandler();
    $store->setHandler(new class($real) implements SessionHandlerInterface
    {
        public function __construct(private SessionHandlerInterface $inner) {}

        public function close(): bool
        {
            return $this->inner->close();
        }

        public function destroy(string $id): bool
        {
            throw new RuntimeException('Valkey went away');
        }

        public function gc(int $max_lifetime): int|false
        {
            return $this->inner->gc($max_lifetime);
        }

        public function open(string $path, string $name): bool
        {
            return $this->inner->open($path, $name);
        }

        public function read(string $id): string|false
        {
            return $this->inner->read($id);
        }

        public function write(string $id, string $data): bool
        {
            return $this->inner->write($id, $data);
        }
    });
    try {
        expect(fn () => dvDevices()->signOut($user, session()->getId(), $key))->toThrow(RuntimeException::class);
    } finally {
        $store->setHandler($real);
    }

    expect(dvAlive($phone))->toBeTrue()
        ->and(dvDevices()->ended($user->id, $key))->toBeTrue()
        ->and(dvDevices()->exists($user->id, $key))->toBeFalse();
    $this->actingAs($user)->withCookie(config('session.cookie'), $phone)->get('http://a.test/admin/account')
        ->assertRedirectContains('/admin/login');
    $this->assertGuest();
});

it('lets the request through, and reports it, when Valkey fails while checking for an ended mark', function (): void {
    Exceptions::fake();
    $user = dvMember();
    $sid = dvSid();
    dvDevices()->forget($user->id, SignedInDevices::keyOf($sid));

    $config = config('database.redis');
    $flaky = new class(app(), Arr::pull($config, 'client', 'phpredis'), $config) extends RedisManager
    {
        public function __call($method, $parameters)
        {
            if (strtolower($method) === 'exists' && str_starts_with((string) ($parameters[0] ?? ''), 'signed_in_device_ended:')) {
                throw new RuntimeException('Valkey went away');
            }

            return parent::__call($method, $parameters);
        }
    };
    $real = app('redis');
    app()->instance('redis', $flaky);
    Redis::clearResolvedInstance('redis');
    try {
        $this->actingAs($user)->withCookie(config('session.cookie'), $sid)->get('http://a.test/admin/account')->assertOk();
    } finally {
        app()->instance('redis', $real);
        Redis::clearResolvedInstance('redis');
    }

    // fails open: the session store is Valkey too, so in production such a request fails there anyway
    $this->assertAuthenticatedAs($user);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Valkey went away');
});
