<?php

use App\Modules\Admin\Livewire\Notifications;
use App\Modules\Admin\Livewire\Platform\Notifications as PlatformNotifications;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Notifications\Console\PruneSiteNotifications;
use App\Modules\Notifications\Support\NotificationFeed;
use App\Modules\Notifications\Support\NotificationSearchText;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * "Thông báo" (design c-thong-bao, the bell's "Xem tất cả thông báo"): the reader's own notices on
 * this site, grouped by the site's day, one tab per group with its count, eight per page by keyset;
 * "Đã đọc" / "Đánh dấu đã đọc tất cả" with "Hoàn tác"; a row opens where it leads; a support person
 * signed in as the member reads but never marks anything; every endpoint answers members only.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi'); // Livewire::test renders outside the locale middleware
});

/** @param  list<string>  $extra */
function npMember(int $site = 1, array $extra = []): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'editor', ['entries.view', ...$extra]));

    return $user->fresh();
}

/** @param  array<string, mixed>  $data */
function npNotice(User $user, int $site, string $kind = 'lead.new', array $data = [], ?string $at = null, bool $read = false): string
{
    $ulid = (string) Str::ulid();
    $data += $kind === 'lead.new' ? ['name' => 'Khách '.$ulid, 'submission' => (string) Str::ulid()] : [];
    DB::connection('pgsql_admin')->table('site_notifications')->insert([
        'ulid' => $ulid, 'site_id' => $site, 'user_id' => $user->id, 'kind' => $kind,
        'data' => json_encode($data), 'search_text' => NotificationSearchText::for($data),
        'read_at' => $read ? now() : null, 'created_at' => $at ?? now(),
    ]);

    return $ulid;
}

function npReadAt(string $ulid): ?string
{
    $value = DB::connection('pgsql_admin')->table('site_notifications')->where('ulid', $ulid)->value('read_at');

    return is_string($value) ? $value : null;
}

function npPage(User $user, array $query = []): Testable
{
    return app(Tenancy::class)->enter(1, fn () => Livewire::withQueryParams($query)->actingAs($user)->test(Notifications::class));
}

/** Typing in the search box, on site 1. */
function npSet(Testable $page, string $q): Testable
{
    return app(Tenancy::class)->enter(1, fn () => $page->set('q', $q));
}

/** Each later request to the page runs on site 1, as the tenancy middleware would. */
function npCall(Testable $page, string $method, mixed ...$params): Testable
{
    return app(Tenancy::class)->enter(1, fn () => $page->call($method, ...$params));
}

it('lists the reader\'s own notices on this site by day, newest first, with each tab\'s count', function (): void {
    $me = npMember(extra: ['forms.view']);
    $other = npMember();
    npNotice($me, 1, 'lead.new', ['name' => 'Nguyễn Thị Mai'], now()->subDay()->toDateTimeString());
    npNotice($me, 1, 'workflow.returned', ['entry' => (string) Str::ulid(), 'title' => 'Sai số gia công', 'actor' => 'Chị Hà', 'note' => 'Thêm bảng dung sai.'], read: true);
    npNotice($me, 1, 'lead.new', ['name' => 'Trần Văn Hùng']);
    npNotice($other, 1, 'lead.new', ['name' => 'Không phải của tôi']);
    npNotice($me, 2, 'lead.new', ['name' => 'Website khác']);

    $html = $this->actingAs($me)->withSession(['locale' => 'vi'])->get('http://a.test/admin/notifications')
        ->assertOk()
        ->assertSee('<h1 class="text-title font-semibold leading-[1.4] text-text-strong">Thông báo</h1>', false)
        ->assertSee('Thông báo được giữ 90 ngày, sau đó tự xóa.')
        ->assertSee('Lý do: Thêm bảng dung sai.')
        ->assertSee('Khách liên hệ · ', false)
        ->assertDontSee('Không phải của tôi')
        ->assertDontSee('Website khác')
        ->assertDontSee('sx-note', false)
        ->assertDontSee('Chờ backend')
        ->assertDontSee('Tùy chọn thông báo') // the account page is not built yet
        ->getContent();

    expect(strpos($html, 'Trần Văn Hùng'))->toBeLessThan(strpos($html, 'Sai số gia công'))
        ->and(strpos($html, 'Sai số gia công'))->toBeLessThan(strpos($html, 'Nguyễn Thị Mai'))
        ->and(strpos($html, '>Hôm nay</h2>'))->toBeLessThan(strpos($html, '>Hôm qua</h2>'));
    expect($html)->toMatch('~id="tb-tab-tat-ca"[^>]*aria-selected="true".*?>3</span>~s')
        ->toMatch('~id="tb-tab-chua-doc".*?>2</span>~s')
        ->toMatch('~id="tb-tab-duyet-bai".*?>1</span>~s')
        ->toMatch('~id="tb-tab-khach-lien-he".*?>2</span>~s')
        ->toMatch('~id="tb-tab-bao-mat".*?>0</span>~s');
});

it('opens a tab from its address word and lists only that tab', function (): void {
    $me = npMember();
    npNotice($me, 1, 'lead.new', ['name' => 'Khách chưa đọc']);
    npNotice($me, 1, 'lead.new', ['name' => 'Khách đã đọc'], read: true);
    npNotice($me, 1, 'workflow.published', ['entry' => (string) Str::ulid(), 'title' => 'Bài đã đăng', 'actor' => 'Chị Hà'], read: true);

    npPage($me, ['tab' => 'chua-doc'])->assertSee('Khách chưa đọc')->assertDontSee('Khách đã đọc')->assertDontSee('Bài đã đăng');
    npPage($me, ['tab' => 'duyet-bai'])->assertSee('Bài đã đăng')->assertDontSee('Khách chưa đọc');
    npPage($me, ['tab' => 'nhap-bay'])->assertSet('tab', 'tat-ca')->assertSee('Khách đã đọc');
    npPage($me)->call('show', 'khach-lien-he')->assertSet('tab', 'khach-lien-he')->assertDontSee('Bài đã đăng')
        ->call('show', 'chua-doc OR 1=1')->assertStatus(404);
});

it('pages eight at a time by keyset and keeps the pages on screen as it goes', function (): void {
    $me = npMember();
    foreach (range(1, 20) as $i) {
        npNotice($me, 1, 'lead.new', ['name' => sprintf('Khách %02d', $i)], now()->subMinutes(100 - $i)->toDateTimeString());
    }

    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });
    $page = npPage($me)->assertSee('Khách 20')->assertSee('Khách 13')->assertDontSee('Khách 12')
        ->assertSee('Đang hiện 8 / 20 thông báo');
    npCall($page, 'more')->assertSee('Khách 13')->assertSee('Khách 05')->assertDontSee('Khách 04')
        ->assertSee('Đang hiện 16 / 20 thông báo');
    npCall($page, 'more')->assertSee('Khách 01')->assertDontSee('Tải thêm')
        ->assertSee('Đã hiện hết thông báo trong 90 ngày qua (20).');

    expect(collect($sql)->filter(fn (string $q): bool => str_contains($q, 'site_notifications') && str_contains($q, ' offset ')))->toBeEmpty();
});

it('marks one read with "Hoàn tác", and never another person\'s', function (): void {
    $me = npMember();
    $mine = npNotice($me, 1);
    $theirs = npNotice(npMember(), 1);

    $page = npCall(npPage($me), 'read', $mine)->assertSet('toast', 'Đã đánh dấu đã đọc.')->assertSet('toastUndo', true);
    expect(npReadAt($mine))->not->toBeNull();

    npCall($page, 'undo')->assertSet('toast', 'Đã hoàn tác.')->assertSet('toastUndo', false);
    expect(npReadAt($mine))->toBeNull();

    npCall($page, 'read', $theirs)->assertSet('toast', 'Thông báo này không còn nữa — danh sách đã được làm mới.');
    expect(npReadAt($theirs))->toBeNull();
    npCall($page, 'read', 'not-a-ulid')->assertStatus(422);
});

it('marks all read and "Hoàn tác" puts back exactly those', function (): void {
    $me = npMember();
    $before = npNotice($me, 1, read: true);
    DB::connection('pgsql_admin')->table('site_notifications')->where('ulid', $before)->update(['read_at' => now()->subMinute()]);
    $a = npNotice($me, 1);
    $b = npNotice($me, 1);
    $elsewhere = npNotice($me, 2);

    $page = npCall(npPage($me), 'readAll')->assertSet('toast', 'Đã đánh dấu đã đọc 2 thông báo.');
    expect(npReadAt($a))->not->toBeNull()->and(npReadAt($b))->not->toBeNull()->and(npReadAt($elsewhere))->toBeNull();

    npCall($page, 'undo');
    expect(npReadAt($a))->toBeNull()->and(npReadAt($b))->toBeNull()->and(npReadAt($before))->not->toBeNull();

    npCall(npCall($page, 'readAll'), 'readAll')->assertSet('toast', 'Không còn thông báo chưa đọc.');
});

it('puts back after "Đánh dấu đã đọc tất cả" only what it marked, not one the reader read in that same second', function (): void {
    $me = npMember();
    $this->freezeTime();
    $single = npNotice($me, 1);
    $a = npNotice($me, 1);
    $b = npNotice($me, 1);

    $page = npCall(npPage($me), 'read', $single);
    $page = npCall($page, 'readAll')->assertSet('toast', 'Đã đánh dấu đã đọc 2 thông báo.');
    expect(npReadAt($single))->toBe(npReadAt($a)); // the same second on record

    $late = npNotice($me, 1); // arrives after the click: the reader has not seen it
    npCall($page, 'undo');
    expect(npReadAt($a))->toBeNull()->and(npReadAt($b))->toBeNull()
        ->and(npReadAt($single))->not->toBeNull()->and(npReadAt($late))->toBeNull();
});

it('neither marks nor opens another site\'s notice of the same reader from this site\'s page', function (): void {
    $me = npMember();
    app(Tenancy::class)->enter(2, fn () => grantRole($me, 'editor', ['entries.view']));
    $there = npNotice($me, 2);

    $page = npCall(npPage($me), 'read', $there)->assertSet('toast', 'Thông báo này không còn nữa — danh sách đã được làm mới.');
    npCall($page, 'open', $there)->assertNoRedirect()->assertSet('toast', 'Thông báo này không còn nữa — danh sách đã được làm mới.');
    expect(npReadAt($there))->toBeNull();
});

it('opens a notice where it leads and marks it read; a notice gone meanwhile only redraws the list', function (): void {
    $me = npMember();
    $entry = (string) Str::ulid();
    $one = npNotice($me, 1, 'workflow.published', ['entry' => $entry, 'title' => 'Bài', 'actor' => 'Chị Hà']);
    $gone = npNotice($me, 1);

    $page = npPage($me)->assertSee('href="/admin/content/'.$entry.'/edit"', false);
    app(Tenancy::class)->enter(1, fn () => $page->call('open', $one))->assertRedirect('/admin/content/'.$entry.'/edit');
    expect(npReadAt($one))->not->toBeNull();

    DB::connection('pgsql_admin')->table('site_notifications')->where('ulid', $gone)->delete();
    app(Tenancy::class)->enter(1, fn () => $page->call('open', $gone))->assertNoRedirect()
        ->assertSet('toast', 'Thông báo này không còn nữa — danh sách đã được làm mới.');
});

it('says when the member\'s role no longer allows what a notice asks, and still links it', function (): void {
    $me = npMember(); // editor without forms.view
    npNotice($me, 1, 'lead.new', ['name' => 'Phạm Quốc Bảo']);

    npPage($me)->assertSee('Phạm Quốc Bảo')->assertSee('Nhóm quyền của bạn đã đổi, không còn làm được việc này.')
        ->assertSee('Xem liên hệ');
    npPage(npMember(extra: ['forms.view']))->assertDontSee('Nhóm quyền của bạn đã đổi');
});

it('shows each tab\'s empty state as the board words it', function (): void {
    $me = npMember();
    npPage($me)->assertSee('Bạn chưa có thông báo mới.')->assertDontSee('Tải thêm');
    npPage($me, ['tab' => 'chua-doc'])->assertSee('Bạn đã đọc hết thông báo.')->assertSee('Xem tất cả');
    npPage($me, ['tab' => 'khach-lien-he'])->assertSee('Nhóm quyền của bạn không xem khách liên hệ.');
    npPage($me, ['tab' => 'bao-mat'])->assertSee('Không có cảnh báo bảo mật nào.')->assertSee('href="'.route('admin.settings.security').'"', false);
    npPage($me, ['tab' => 'he-thong'])->assertSee('Tab này thường trống: hệ thống chỉ báo khi thư viện sắp đầy');
    npPage($me, ['tab' => 'duyet-bai'])->assertSee('Website đang đăng thẳng, không qua bước duyệt.');
});

it('never marks anything read for the member while a support person is signed in as them', function (): void {
    $me = npMember();
    $staff = User::factory()->create(['name' => 'Lê Hoàng Phúc']);
    $one = npNotice($me, 1);

    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($me);
    app(SupportSession::class)->start($staff, $me, 1, 'Kiểm tra', now()->addMinutes(30));
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    $page = npPage($me)->assertSee('Bạn đang đăng nhập dưới tên thành viên để hỗ trợ')
        ->assertSee('aria-disabled="true"', false)->assertDontSee('Đánh dấu đã đọc: ');
    app(Tenancy::class)->enter(1, fn () => $page->call('read', $one)->call('readAll'))
        ->assertSet('toast', 'Đang đăng nhập hỗ trợ — không đánh dấu đã đọc thay thành viên');
    expect(npReadAt($one))->toBeNull();

    // Opening a notice follows it without marking it; "Hoàn tác" changes nothing either.
    DB::connection('pgsql_admin')->table('site_notifications')->where('ulid', $one)->update(['read_at' => now()]);
    app(Tenancy::class)->enter(1, fn () => $page->call('undo'));
    expect(npReadAt($one))->not->toBeNull();
    DB::connection('pgsql_admin')->table('site_notifications')->where('ulid', $one)->update(['read_at' => null]);
    app(Tenancy::class)->enter(1, fn () => $page->call('open', $one));
    expect(npReadAt($one))->toBeNull();
});

it('answers only a member of the site', function (): void {
    $stranger = User::factory()->create();
    npNotice($stranger, 1);

    $this->actingAs($stranger)->get('http://a.test/admin/notifications')->assertForbidden();
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($stranger)->test(Notifications::class))->assertStatus(403);
});

it('reads the page in the same number of queries for 10 or 200 notices', function (): void {
    $me = npMember(extra: ['forms.view']);
    $read = function () use ($me): int {
        $this->actingAs($me)->get('http://a.test/admin/notifications')->assertOk(); // the permission and settings caches fill on the first request

        return countQueries(fn () => $this->actingAs($me)->get('http://a.test/admin/notifications')->assertOk()->assertSee('Đang hiện 8 / '));
    };
    $seed = function (int $n) use ($me): void {
        $kinds = ['lead.new', 'workflow.published', 'system.storage_near_limit', 'security.two_factor_disabled'];
        foreach (range(1, $n) as $i) {
            npNotice($me, 1, $kinds[$i % 4], ['entry' => (string) Str::ulid(), 'title' => 'Bài '.$i, 'actor' => 'Chị Hà'], read: $i % 3 === 0);
        }
    };

    $seed(10);
    $few = $read();
    $seed(190);
    $many = $read();

    // A fixed ceiling: one page of rows, the 'Tải thêm' probe and every tab's capped count in one
    // statement, plus the shell and the top bar's bell.
    expect($many)->toBe($few)->and($few)->toBe(10);
});

it('links the bell\'s foot to the page on a website and in the platform console', function (): void {
    $me = npMember();
    $this->actingAs($me)->get('http://a.test/admin/content')
        ->assertSee('<div class="ntf-f">', false)
        ->assertSee('href="http://a.test/admin/notifications"', false)
        ->assertSee('Xem tất cả thông báo');

    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();
    $this->actingAs($operator->fresh())->get("http://{$host}/tenants")
        ->assertSee('href="'.route('platform.notifications').'"', false);
});

it('shows the platform console\'s page empty, with nothing read from a store', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $this->actingAs($operator->fresh())->get("http://{$host}/notifications")->assertOk()
        ->assertSee('Bạn chưa có thông báo mới.')
        ->assertSee('id="tb-tab-chua-doc"', false)->assertDontSee('id="tb-tab-duyet-bai"', false)
        ->assertDontSee('Chờ backend')->assertDontSee('kho thông báo riêng');

    Livewire::actingAs($operator->fresh())->test(PlatformNotifications::class)->call('show', 'chua-doc')
        ->assertSee('Bạn đã đọc hết thông báo.')->call('show', 'duyet-bai')->assertStatus(404);
    Livewire::actingAs(npMember())->test(PlatformNotifications::class)->assertStatus(403);
});

it('finds the reader\'s notices by their facts with accents and case ignored, never by the sentence\'s wording', function (): void {
    $me = npMember(extra: ['forms.view']);
    $other = npMember();
    npNotice($me, 1, 'lead.new', ['name' => 'Nguyễn Văn Nam']);
    npNotice($me, 1, 'workflow.pending_review', ['entry' => (string) Str::ulid(), 'title' => 'Máy phay 5 trục', 'actor' => 'Chị Hà']);
    npNotice($me, 1, 'workflow.returned', ['entry' => (string) Str::ulid(), 'title' => 'Bàn giao dây chuyền', 'actor' => 'Anh Đức', 'note' => 'Thêm bảng dung sai.']);
    npNotice($me, 1, 'system.domain_problem', ['sentence' => 'failing', 'domains' => 'anvinh.vn', 'until' => '20/11/2026', 'target' => 'sites.example.test']);
    npNotice($other, 1, 'lead.new', ['name' => 'Nguyễn Văn Nam Khác']);
    npNotice($me, 2, 'lead.new', ['name' => 'Nguyễn Văn Nam Website Khác']);

    $page = npPage($me)->assertSee('Tìm theo nội dung hoặc người liên quan')->assertSee('Anh Nam, máy phay 5 trục…');
    npSet($page, 'nguyen nam')->assertSee('Nguyễn Văn Nam')->assertDontSee('Máy phay 5 trục')
        ->assertDontSee('Nam Khác')->assertDontSee('Website Khác')->assertSee('Đã hiện hết thông báo trong 90 ngày qua (1).');
    npSet($page, 'NAM  NGUYỄN')->assertSee('Nguyễn Văn Nam');                    // any order, any case
    npSet($page, 'ay 5')->assertSee('Máy phay 5 trục')->assertDontSee('Nguyễn Văn Nam'); // part of a word
    npSet($page, 'duc')->assertSee('Bàn giao dây chuyền');                        // who acted, đ → d
    npSet($page, 'dung sai')->assertSee('Bàn giao dây chuyền');                   // the return note
    npSet($page, 'anvinh')->assertSee('anvinh.vn');                               // the domain
    // the fixed wording of a sentence finds nothing: the tabs cover the kinds
    npSet($page, 'đang chờ bạn duyệt')->assertSee('Không có thông báo nào khớp «đang chờ bạn duyệt»')
        ->assertSee('Thử từ ngắn hơn, tên người liên quan hoặc một phần tiêu đề bài.')
        ->assertDontSee('Máy phay 5 trục');
    // the tabs still count everything (the board's counts ignore the search)
    $page->assertSeeHtml('id="tb-tab-tat-ca"');
    expect(collect($page->viewData('tabs'))->firstWhere('key', 'tat-ca')['n'])->toBe('4');

    npCall($page, 'clearSearch')->assertSet('q', '')->assertSee('Nguyễn Văn Nam')->assertSee('Máy phay 5 trục');
});

it('matches %, _ and \\ typed in the search as plain characters, and a NUL as a space', function (): void {
    $me = npMember(extra: ['forms.view']);
    npNotice($me, 1, 'lead.new', ['name' => 'Giảm 50% Phúc'], now()->subMinutes(3)->toDateTimeString());
    npNotice($me, 1, 'lead.new', ['name' => 'Ông A_B'], now()->subMinutes(2)->toDateTimeString());
    npNotice($me, 1, 'lead.new', ['name' => 'Thư mục C\\D'], now()->subMinutes(1)->toDateTimeString());
    npNotice($me, 1, 'lead.new', ['name' => 'Ông AxB 500 Phúc'], now()->toDateTimeString());

    $page = npPage($me, ['tab' => 'khach-lien-he']);
    npSet($page, '50%')->assertSee('Giảm 50% Phúc')->assertDontSee('Ông AxB 500 Phúc');
    npSet($page, 'a_b')->assertSee('Ông A_B')->assertDontSee('Ông AxB');
    npSet($page, 'c\\d')->assertSee('Thư mục C\\D')->assertDontSee('Giảm 50%');
    npSet($page, "ph\u{0000}50%")->assertOk()->assertSee('Giảm 50% Phúc')->assertDontSee('Ông AxB 500 Phúc');
});

it('searches within the tab and pages the matches eight at a time', function (): void {
    $me = npMember(extra: ['forms.view']);
    foreach (range(1, 20) as $i) {
        npNotice($me, 1, 'lead.new', ['name' => sprintf('Trần Khách %02d', $i)], now()->subMinutes(100 - $i)->toDateTimeString());
        npNotice($me, 1, 'workflow.published', ['entry' => (string) Str::ulid(), 'title' => sprintf('Bài Trần %02d', $i), 'actor' => 'Chị Hà'], now()->subMinutes(100 - $i)->toDateTimeString());
    }

    $page = npSet(npPage($me, ['tab' => 'khach-lien-he']), 'tran')
        ->assertSee('Trần Khách 20')->assertSee('Trần Khách 13')->assertDontSee('Trần Khách 12')->assertDontSee('Bài Trần')
        ->assertSee('Đang hiện 8 / 20 thông báo');
    npCall($page, 'more')->assertSee('Trần Khách 05')->assertDontSee('Trần Khách 04')->assertSee('Đang hiện 16 / 20 thông báo');
    npCall($page, 'more')->assertSee('Trần Khách 01')->assertDontSee('Tải thêm')->assertSee('Đã hiện hết thông báo trong 90 ngày qua (20).');

    // a new search starts again at its first page
    npSet($page, 'khach')->assertSee('Trần Khách 13')->assertDontSee('Trần Khách 12');
    npCall($page, 'show', 'duyet-bai')->assertSee('Không có thông báo nào khớp «khach»');
});

it('moves a search\'s "Tải thêm" to where the draw on screen ended, with one walk a click, and starts again when that notice is gone', function (): void {
    $me = npMember(extra: ['forms.view']);
    $ulids = [];
    foreach (range(1, 20) as $i) {
        $ulids[$i] = npNotice($me, 1, 'lead.new', ['name' => sprintf('Võ Khách %02d', $i)], now()->subMinutes(100 - $i)->toDateTimeString());
    }
    $page = npSet(npPage($me), 'vo')->assertSee('Đang hiện 8 / 20 thông báo');
    expect($page->get('nextUntil'))->toBe($ulids[5]);

    $walks = 0;
    DB::listen(function ($query) use (&$walks): void {
        $walks += str_contains($query->sql, '"search_text"') ? 1 : 0;
    });
    npCall($page, 'more')->assertSee('Võ Khách 05')->assertDontSee('Võ Khách 04')->assertSee('Đang hiện 16 / 20 thông báo');
    expect($walks)->toBe(1)->and($page->get('nextUntil'))->toBe($ulids[1]);

    // the notice the next page would end at is cleaned up meanwhile: the search starts again at its first page
    DB::connection('pgsql_admin')->table('site_notifications')->where('ulid', $ulids[1])->delete();
    npCall($page, 'more')->assertSet('until', null)->assertSee('Võ Khách 13')->assertDontSee('Võ Khách 12')->assertSee('Đang hiện 8 / 19 thông báo');
});

it('reaches as far back as the daily prune keeps notices, and keeps the typed words out of the address', function (): void {
    $me = npMember(extra: ['forms.view']);
    npNotice($me, 1, 'lead.new', ['name' => 'Lê Cũ'], now()->subDays(NotificationFeed::SEARCH_DAYS + 1)->toDateTimeString());
    npNotice($me, 1, 'lead.new', ['name' => 'Lê Mới'], now()->subDays(NotificationFeed::SEARCH_DAYS - 1)->toDateTimeString());

    // the walk has no time filter of its own (created_at is not in the index it reads): what bounds it
    // is the daily prune, which keeps SEARCH_DAYS by default, like the list without a search
    $prune = new PruneSiteNotifications;
    expect($prune->getDefinition()->getOption('days')->getDefault())->toBe((string) NotificationFeed::SEARCH_DAYS);
    $this->artisan('notifications:prune')->assertSuccessful();

    npSet(npPage($me), 'le')->assertSee('Lê Mới')->assertDontSee('Lê Cũ')->assertSee('Đã hiện hết thông báo trong 90 ngày qua (1).');
    expect((new ReflectionProperty(Notifications::class, 'q'))->getAttributes(Livewire\Attributes\Url::class))->toBeEmpty();
});

it('never sends a notice\'s bigint id to the browser while searching and paging: the cursors are ulids', function (): void {
    $me = npMember(extra: ['forms.view']);
    $ids = [];
    foreach (range(1, 20) as $i) {
        $ids[$i] = 987_654_321_000 + $i * 7;
        DB::connection('pgsql_admin')->table('site_notifications')->insert([
            'id' => $ids[$i], 'ulid' => (string) Str::ulid(), 'site_id' => 1, 'user_id' => $me->id, 'kind' => 'lead.new',
            'data' => json_encode(['name' => sprintf('Đỗ Khách %02d', $i)]), 'search_text' => sprintf('do khach %02d', $i),
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }
    $sent = fn (Testable $page): string => $page->html().json_encode((fn () => $this->lastState->getSnapshot())->call($page));

    $page = npSet(npPage($me), 'khach')->assertSee('Đỗ Khách 20')->assertSee('Đang hiện 8 / 20 thông báo');
    $seen = [$sent($page)];
    expect($page->get('nextUntil'))->toBeString()->toHaveLength(26);
    npCall($page, 'more')->assertSee('Đỗ Khách 05')->assertSee('Đang hiện 16 / 20 thông báo');
    $seen[] = $sent($page);
    expect($page->get('until'))->toBeString()->toHaveLength(26);
    npCall($page, 'more')->assertSee('Đỗ Khách 01');
    $seen[] = $sent($page);

    foreach ($seen as $out) {
        foreach ($ids as $id) {
            expect($out)->not->toContain((string) $id);
        }
    }
});

it('reads a search in a fixed number of queries, one walk of the reader\'s notices', function (): void {
    $me = npMember(extra: ['forms.view']);
    foreach (range(1, 30) as $i) {
        npNotice($me, 1, 'lead.new', ['name' => 'Phạm Khách '.$i]);
    }
    $page = npPage($me);

    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        if (str_contains($query->sql, 'site_notifications')) {
            $sql[] = $query->sql;
        }
    });
    npSet($page, 'pham');

    // the walk, the rows shown by id, the six tab counts
    expect($sql)->toHaveCount(3)
        ->and(collect($sql)->filter(fn (string $q): bool => str_contains($q, '"search_text"'))->count())->toBe(1);
});
