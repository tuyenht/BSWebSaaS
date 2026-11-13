<?php

use App\Modules\Admin\Livewire\NotificationBell;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The top bar's bell (design 01 "Thông báo"): every admin page shows the reader's unread count on
 * this site from one bounded query; the panel's list is read only once it opens; a person sees only
 * their own notices of the current site; opening one marks it read and follows a ulid address;
 * a support person signed in as the member never marks anything read for them.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi'); // Livewire::test renders outside the locale middleware
});

function nbMember(int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'editor', ['entries.view']));

    return $user->fresh();
}

/**
 * One notice for $user on $site, written as the privileged role (any time, any site).
 *
 * @param  array<string, mixed>  $data
 */
function nbNotice(User $user, int $site, string $kind = 'lead.new', array $data = [], ?string $at = null, bool $read = false): string
{
    $ulid = (string) Str::ulid();
    DB::connection('pgsql_admin')->table('site_notifications')->insert([
        'ulid' => $ulid, 'site_id' => $site, 'user_id' => $user->id, 'kind' => $kind,
        'data' => json_encode($data + ($kind === 'lead.new' ? ['name' => 'Khách '.$ulid, 'submission' => (string) Str::ulid()] : [])),
        'read_at' => $read ? now() : null, 'created_at' => $at ?? now(),
    ]);

    return $ulid;
}

function nbReadAt(string $ulid): ?string
{
    $value = DB::connection('pgsql_admin')->table('site_notifications')->where('ulid', $ulid)->value('read_at');

    return is_string($value) ? $value : null;
}

it('shows the unread count on the bell with its spoken name, and no list until the panel opens', function (): void {
    $user = nbMember();
    nbNotice($user, 1, 'lead.new', ['name' => 'Trần Văn Hùng']);
    nbNotice($user, 1);
    nbNotice($user, 1);
    nbNotice($user, 1, read: true);

    $this->actingAs($user)->withSession(['locale' => 'vi'])->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('data-bs-lazy="bsBell"', false)
        ->assertSee('aria-label="Thông báo — 3 chưa đọc"', false)
        ->assertSee('<span class="ntf-badge" aria-hidden="true">3</span>', false)
        ->assertSee('aria-haspopup="dialog"', false)
        ->assertSee('role="dialog"', false)
        ->assertDontSee('Trần Văn Hùng'); // the list is read only when the panel opens
});

it('caps the badge at 99+ and says there is nothing unread when there is none', function (): void {
    $busy = nbMember();
    $rows = [];
    foreach (range(1, 120) as $i) {
        $rows[] = ['ulid' => (string) Str::ulid(), 'site_id' => 1, 'user_id' => $busy->id, 'kind' => 'lead.new', 'data' => '{"name":"K"}', 'created_at' => now()];
    }
    DB::connection('pgsql_admin')->table('site_notifications')->insert($rows);
    $calm = nbMember();

    $this->actingAs($busy)->withSession(['locale' => 'vi'])->get('http://a.test/admin/content')
        ->assertOk()->assertSee('aria-label="Thông báo — 99+ chưa đọc"', false)->assertSee('>99+</span>', false);

    $this->actingAs($calm)->withSession(['locale' => 'vi'])->get('http://a.test/admin/content')
        ->assertOk()->assertSee('aria-label="Thông báo — không có thông báo chưa đọc"', false)->assertDontSee('ntf-badge', false);
});

it('lists only the reader\'s own notices of the current site once the panel opens', function (): void {
    $me = nbMember();
    app(Tenancy::class)->enter(2, fn () => grantRole($me, 'editor', ['entries.view']));
    $other = nbMember();
    nbNotice($me, 1, 'lead.new', ['name' => 'Của tôi ở A']);
    nbNotice($other, 1, 'lead.new', ['name' => 'Của người khác ở A']);
    nbNotice($me, 2, 'lead.new', ['name' => 'Của tôi ở B']);

    $bell = app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me->fresh())->test(NotificationBell::class));
    $bell->assertDontSee('Của tôi ở A');

    app(Tenancy::class)->enter(1, fn () => $bell->call('load'))
        ->assertSee('Có khách liên hệ mới: Của tôi ở A.')
        ->assertDontSee('Của người khác ở A')
        ->assertDontSee('Của tôi ở B')
        ->assertSee('1 chưa đọc');
});

it('shows the empty state when the reader has no notices', function (): void {
    $me = nbMember();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me)->test(NotificationBell::class)->call('load'))
        ->assertSee('Bạn chưa có thông báo mới.')
        ->assertDontSee('Đánh dấu đã đọc tất cả');
});

it('groups by the site\'s day, says how long ago, and links by ulid with the board\'s words', function (): void {
    $me = nbMember();
    $entry = (string) Str::ulid();
    $lead = (string) Str::ulid();
    nbNotice($me, 1, 'workflow.returned', ['entry' => $entry, 'title' => 'Sai số gia công', 'actor' => 'Chị Hà'], now()->subSeconds(10)->toDateTimeString());
    nbNotice($me, 1, 'lead.new', ['name' => 'Nguyễn Thị Mai', 'submission' => $lead], now()->subMinutes(12)->toDateTimeString());
    nbNotice($me, 1, 'workflow.pending_review', ['entry' => $entry, 'title' => 'Gia công CNC', 'actor' => 'Chị Lan', 'change' => true], now()->subDays(3)->toDateTimeString(), read: true);

    $html = app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me)->test(NotificationBell::class)->call('load')->html());

    expect($html)
        ->toContain('<div class="ntf-day">Hôm nay</div>')
        ->toContain('Vừa xong')->toContain('12 phút trước')
        ->toContain('href="/admin/content/'.$entry.'/edit"')->toContain('Sửa bài')
        ->toContain('href="/admin/forms/'.$lead.'"')->toContain('Xem liên hệ')
        ->toContain('href="/admin/content/review"')->toContain('Mở hộp duyệt')
        ->toContain(now('Asia/Ho_Chi_Minh')->subDays(3)->format('d/m/Y'))
        ->not->toMatch('~href="[^"]*/\d+(/|")~'); // no bigint id in any address
});

it('reads the two-step notice as turned off or turned on, each with its own link', function (): void {
    $me = nbMember();
    nbNotice($me, 1, 'security.two_factor_disabled', ['at' => '13/11/2026 09:00']);
    nbNotice($me, 1, 'security.two_factor_disabled', ['sentence' => 'enabled', 'at' => '13/11/2026 10:00']);

    $html = app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me)->test(NotificationBell::class)->call('load')->html());

    expect($html)
        ->toContain('Xác thực 2 lớp của tài khoản đã được tắt lúc 13/11/2026 09:00.')->toContain('Bật lại xác thực 2 lớp')
        ->toContain('Xác thực 2 lớp của tài khoản đã được bật lúc 13/11/2026 10:00.')->toContain('Mở Bảo mật')
        ->toContain('href="/admin/account?tab=security"');
});

it('marks one notice read when opened and follows its address', function (): void {
    $me = nbMember();
    $entry = (string) Str::ulid();
    $ulid = nbNotice($me, 1, 'workflow.assigned', ['entry' => $entry, 'title' => 'Máy phay', 'actor' => 'Anh Nam']);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me)->test(NotificationBell::class)->call('load')->call('open', $ulid))
        ->assertRedirect('/admin/content/'.$entry.'/edit');

    expect(nbReadAt($ulid))->not->toBeNull();
});

it('never opens another person\'s notice or another site\'s, answering both as a notice that is gone', function (): void {
    $me = nbMember();
    app(Tenancy::class)->enter(2, fn () => grantRole($me, 'editor', ['entries.view']));
    $other = nbMember();
    $theirs = nbNotice($other, 1, 'workflow.assigned', ['entry' => (string) Str::ulid(), 'title' => 'Của người khác']);
    $mineOnB = nbNotice($me, 2, 'workflow.assigned', ['entry' => (string) Str::ulid(), 'title' => 'Ở trang B']);

    foreach ([$theirs, $mineOnB] as $ulid) {
        app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me->fresh())->test(NotificationBell::class)->call('load')->call('open', $ulid))
            ->assertOk()->assertNoRedirect()
            ->assertDispatched('bs-toast', text: 'Thông báo này không còn nữa — danh sách đã được làm mới.')
            ->assertDontSee('Của người khác')->assertDontSee('Ở trang B');
    }
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me->fresh())->test(NotificationBell::class)->call('open', '1 or 1=1'))->assertStatus(422);

    expect(nbReadAt($theirs))->toBeNull()->and(nbReadAt($mineOnB))->toBeNull();
});

it('redraws the panel without a notice removed while it was open, and says so in a toast', function (): void {
    $me = nbMember();
    $gone = nbNotice($me, 1, 'lead.new', ['name' => 'Khách đã xóa']);
    nbNotice($me, 1, 'lead.new', ['name' => 'Khách còn lại']);

    $bell = app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me)->test(NotificationBell::class)->call('load')
        ->assertSee('Khách đã xóa')->assertSee('<span class="ntf-badge" aria-hidden="true">2</span>', false));
    DB::connection('pgsql_admin')->table('site_notifications')->where('ulid', $gone)->delete();

    app(Tenancy::class)->enter(1, fn () => $bell->call('open', $gone))
        ->assertOk()->assertNoRedirect()
        ->assertDispatched('bs-toast', text: 'Thông báo này không còn nữa — danh sách đã được làm mới.')
        ->assertDontSee('Khách đã xóa')->assertSee('Khách còn lại')->assertSee('<span class="ntf-badge" aria-hidden="true">1</span>', false);
});

it('marks all of the reader\'s notices on this site read, nobody else\'s', function (): void {
    $me = nbMember();
    app(Tenancy::class)->enter(2, fn () => grantRole($me, 'editor', ['entries.view']));
    $other = nbMember();
    $a1 = nbNotice($me, 1);
    $a2 = nbNotice($me, 1);
    $b = nbNotice($me, 2);
    $theirs = nbNotice($other, 1);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me->fresh())->test(NotificationBell::class)->call('load')->call('readAll'))
        ->assertDispatched('bs-toast', text: 'Đã đánh dấu đã đọc 2 thông báo')
        ->assertDontSee('Đánh dấu đã đọc tất cả')
        ->assertDontSee('ntf-badge');

    expect(nbReadAt($a1))->not->toBeNull()->and(nbReadAt($a2))->not->toBeNull()
        ->and(nbReadAt($b))->toBeNull()->and(nbReadAt($theirs))->toBeNull();
});

it('never marks anything read for the member while a support person is signed in as them', function (): void {
    $me = nbMember();
    $staff = User::factory()->create(['name' => 'Lê Hoàng Phúc']);
    $entry = (string) Str::ulid();
    $one = nbNotice($me, 1, 'workflow.published', ['entry' => $entry, 'title' => 'Bài', 'actor' => 'Chị Hà']);
    $two = nbNotice($me, 1);

    // Livewire's test requests skip the middleware, so nothing starts the session on them: hand each
    // one this session, as StartSession does on a real request.
    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($me);
    app(SupportSession::class)->start($staff, $me, 1, 'Kiểm tra', now()->addMinutes(30));
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    $bell = app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($me)->test(NotificationBell::class)->call('load'));

    $bell->assertSee('aria-disabled="true"', false)
        ->assertSee('Đang đăng nhập hỗ trợ — không đánh dấu đã đọc thay thành viên');
    app(Tenancy::class)->enter(1, fn () => $bell->call('readAll'))
        ->assertDispatched('bs-toast', text: 'Đang đăng nhập hỗ trợ — không đánh dấu đã đọc thay thành viên');
    app(Tenancy::class)->enter(1, fn () => $bell->call('open', $one))->assertRedirect('/admin/content/'.$entry.'/edit');

    expect(nbReadAt($one))->toBeNull()->and(nbReadAt($two))->toBeNull();
});

it('answers only a member of the site', function (): void {
    $stranger = User::factory()->create();
    nbNotice($stranger, 1);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($stranger)->test(NotificationBell::class))->assertStatus(403);
});

it('reads the badge as the number up to 99 and as "99+" from 100 unread', function (int $count, string $badge): void {
    $me = nbMember();
    $rows = [];
    foreach (range(1, $count) as $i) {
        $rows[] = ['ulid' => (string) Str::ulid(), 'site_id' => 1, 'user_id' => $me->id, 'kind' => 'lead.new', 'data' => '{"name":"K"}', 'created_at' => now()];
    }
    DB::connection('pgsql_admin')->table('site_notifications')->insert($rows);

    $this->actingAs($me)->withSession(['locale' => 'vi'])->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('<span class="ntf-badge" aria-hidden="true">'.$badge.'</span>', false)
        ->assertSee('aria-label="Thông báo — '.$badge.' chưa đọc"', false);
})->with([
    '99 unread' => [99, '99'],
    '100 unread' => [100, '99+'],
]);

it('costs an admin page one bounded read of the notices, however many there are', function (): void {
    $me = nbMember();
    foreach (range(1, 30) as $i) {
        nbNotice($me, 1);
    }

    $reads = [];
    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'site_notifications')) {
            $reads[] = $query->sql;
        }
    });
    $this->actingAs($me)->withSession(['locale' => 'vi'])->get('http://a.test/admin/content')->assertOk();

    expect($reads)->toHaveCount(1)
        ->and($reads[0])->toContain('limit 100')->toContain('count(*)');
});

it('leaves the bell out of an error page inside the frame, which never repeats the missing code', function (): void {
    $me = nbMember();
    nbNotice($me, 1);

    $html = $this->actingAs($me)->withSession(['locale' => 'vi'])->get('http://a.test/admin/content/01JZZZZZZZZZZZZZZZZZZZZZZZ/edit')
        ->assertNotFound()->getContent();

    expect($html)->toContain('bsSession(')->not->toContain('data-bs-lazy="bsBell"')->not->toContain('01JZZZZZZZZZZZZZZZZZZZZZZZ');
});
