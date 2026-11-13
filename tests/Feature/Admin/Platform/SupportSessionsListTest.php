<?php

use App\Modules\Admin\Livewire\Platform\Support\Index;
use App\Modules\Admin\Support\SupportSessions;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\BoundedRead;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * "Đăng nhập hỗ trợ" in the platform console (board 20): the support sessions read out of the
 * audit trail, in groups with their counts, searched and filtered, paged by the last session seen,
 * and "Kết thúc phiên ngay" for an open one: one's own, or anyone's for the platform super-admin.
 * Staff without the right to sign in as members see the no-right card and nothing else. Sessions
 * are named by their code in the page and in every call, never by a row id.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications, users, site_settings, platform_settings, audit_logs restart identity cascade');
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['ulid' => (string) Str::ulid(), 'name' => 'Nha khoa Tâm Đức']);
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['ulid' => (string) Str::ulid(), 'name' => 'Cơ khí An Vinh']);
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    app()->setLocale('vi'); // Livewire::test renders outside the locale middleware
    Notification::fake();
    Mail::fake();
});

function slStaff(string $role, string $name = ''): User
{
    $user = User::factory()->create(['name' => $name !== '' ? $name : 'Staff '.$role.' '.Str::random(4)]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

/** An entered row as the site writes it, $ago minutes back; returns the session's code. */
function slSession(User $staff, string $member = 'Nguyễn Ngọc Anh', int $site = 1, int $ago = 1, int $minutes = 30, string $reason = 'Khách nhờ sửa trang chủ'): string
{
    $code = (string) Str::ulid();
    $user = User::factory()->create(['name' => $member]);
    AuditLog::query()->create([
        'action' => SupportSessions::ENTERED,
        'actor_id' => $user->getKey(),
        'actor_label' => $user->email,
        'impersonator_id' => $staff->getKey(),
        'impersonator_label' => SupportSession::staffLabel($staff),
        'site_id' => $site,
        'support_session_ulid' => $code,
        'meta' => ['reason' => $reason, 'minutes' => $minutes, 'member_name' => $member, 'member_roles' => [['name' => 'editor', 'system' => true]]],
        'created_at' => CarbonImmutable::now()->subMinutes($ago),
    ]);

    return $code;
}

function slEnd(string $code, string $why, int $site = 1, int $ago = 0): void
{
    AuditLog::query()->create([
        'action' => SupportSessions::ENDED,
        'site_id' => $site,
        'support_session_ulid' => $code,
        'meta' => ['why' => $why],
        'created_at' => CarbonImmutable::now()->subMinutes($ago),
    ]);
}

function slHost(): string
{
    return 'http://'.config('tenancy.platform_host');
}

/** @return list<string> the session codes on the page the component shows now */
function slShown(mixed $page): array
{
    return array_column($page->viewData('rows'), 'session');
}

it('lists the sessions as plain words with the counts of each group', function (): void {
    $staff = slStaff('support', 'Lê Hoàng Phúc');
    slSession($staff, 'Nguyễn Ngọc Anh', 2, reason: 'Khách nhờ sửa trình đơn đầu trang');
    $ended = slSession($staff, 'Hồ Ngọc Anh', 1, ago: 20);
    slEnd($ended, 'left', ago: 10);
    slSession($staff, 'Phạm Minh Đức', 1, ago: 120, minutes: 15);

    $this->actingAs($staff)->get(slHost().'/support')
        ->assertOk()
        ->assertSee('Đăng nhập hỗ trợ')
        ->assertSee('Cơ khí An Vinh')
        ->assertSee('Dưới tên Nguyễn Ngọc Anh (Biên tập)')
        ->assertSee('Khách nhờ sửa trình đơn đầu trang')
        ->assertSee('Lê Hoàng Phúc')
        ->assertSee('Đang mở')
        ->assertSee('bấm Quay lại')
        ->assertSee('hết giờ')
        ->assertSee('tối đa 15 phút')
        ->assertDontSee('support.session.entered');

    Livewire::actingAs($staff)->test(Index::class)
        ->assertViewHas('counts', ['all' => 3, 'open' => 1, 'ended' => 2]);
});

it('narrows by group, staff, period and search, and clears it all with Bỏ lọc', function (): void {
    $phuc = slStaff('support', 'Lê Hoàng Phúc');
    $ha = slStaff('support', 'Trần Thu Hà');
    $open = slSession($phuc, 'Nguyễn Ngọc Anh', 2);
    $left = slSession($ha, 'Đỗ Thị Lan', 1, ago: 30, reason: 'Đăng thông báo tuyển sinh');
    slEnd($left, 'left', ago: 20);
    $old = slSession($phuc, 'Vũ Minh Khang', 1, ago: 60 * 24 * 20);

    $page = Livewire::actingAs($phuc)->test(Index::class);
    expect(slShown($page))->toBe([$open, $left, $old]);

    $page->set('tab', 'open');
    expect(slShown($page))->toBe([$open]);
    $page->set('tab', 'ended');
    expect(slShown($page))->toBe([$left, $old]);

    $page->set('tab', 'all')->set('staff', [$ha->ulid]);
    expect(slShown($page))->toBe([$left])
        ->and(array_column($page->viewData('staffChoices'), 'count', 'label'))->toBe(['Lê Hoàng Phúc' => 2, 'Trần Thu Hà' => 1]);
    $page->assertSee('Nhân viên: Trần Thu Hà');

    $page->set('staff', [])->set('days', '7');
    expect(slShown($page))->toBe([$open, $left])
        ->and($page->viewData('counts'))->toBe(['all' => 2, 'open' => 1, 'ended' => 1]);

    $page->set('days', '')->set('search', 'tuyen sinh');
    expect(slShown($page))->toBe([$left]);
    $page->set('search', 'AN VINH');
    expect(slShown($page))->toBe([$open]);

    $page->set('search', 'không có ai')->assertSee('Không có phiên nào khớp');
    $page->call('clearFilters');
    expect(slShown($page))->toBe([$open, $left, $old])
        ->and($page->get('search'))->toBe('')->and($page->get('days'))->toBe('')->and($page->get('tab'))->toBe('all');
});

it('keeps only choices the screen knows from the address', function (): void {
    $staff = slStaff('support');
    slSession($staff);

    Livewire::withQueryParams(['tab' => 'trash', 'days' => '45', 'staff' => ['1', 'x'], 'after' => '17'])
        ->actingAs($staff)->test(Index::class)
        ->assertSet('tab', 'all')->assertSet('days', '')->assertSet('staff', [])->assertSet('after', '');
});

it('moves page by page both ways, by the code of the session at the edge', function (): void {
    $staff = slStaff('support');
    $codes = [];
    for ($i = 0; $i < 12; $i++) {
        $codes[] = slSession($staff, 'Thành viên '.$i, ago: $i + 1);
    }

    $page = Livewire::actingAs($staff)->test(Index::class)->set('per', 10);
    expect(slShown($page))->toBe(array_slice($codes, 0, 10))
        ->and($page->viewData('newer'))->toBeNull()
        ->and($page->viewData('older'))->toBe($codes[9]);

    $page->call('older', $codes[9]);
    expect(slShown($page))->toBe(array_slice($codes, 10))
        ->and($page->viewData('older'))->toBeNull()
        ->and($page->viewData('newer'))->toBe($codes[10]);

    $page->call('newer', $codes[10]);
    expect(slShown($page))->toBe(array_slice($codes, 0, 10))
        ->and($page->viewData('newer'))->toBeNull();

    // the page size is the member's own and stays for the next visit
    Livewire::actingAs($staff->fresh())->test(Index::class)->assertSet('per', 10);
});

it('shows staff without the right the no-right card, reads nothing and refuses every action', function (): void {
    $code = slSession(slStaff('support'), 'Nguyễn Ngọc Anh', 2);
    $billing = slStaff('billing');

    $this->actingAs($billing)->get(slHost().'/support')
        ->assertOk()
        ->assertSee('Bạn chưa được xem các phiên hỗ trợ')
        ->assertDontSee('Cơ khí An Vinh')
        ->assertDontSee($code);

    $page = Livewire::actingAs($billing)->test(Index::class);
    $page->call('askEnd', $code)->assertForbidden();
    Livewire::actingAs($billing)->test(Index::class)->call('older', $code)->assertForbidden();
    Livewire::actingAs($billing)->test(Index::class)->call('clearFilters')->assertForbidden();
    Livewire::actingAs($billing)->test(Index::class)->call('endNow')->assertForbidden();
    Livewire::actingAs($billing)->test(Index::class)->call('dismissToast')->assertForbidden();
    Livewire::actingAs($billing)->test(Index::class)->set('search', 'An Vinh')->assertForbidden();
    expect(AuditLog::query()->where('action', SupportSessions::ENDED)->count())->toBe(0);
});

it('keeps a member who is no operator out of the screen', function (): void {
    $member = User::factory()->create();

    $this->actingAs($member)->get(slHost().'/support')->assertForbidden();
    Livewire::actingAs($member)->test(Index::class)->assertForbidden();
});

it('lets staff end their own open session after asking, on record as ended by them', function (): void {
    $staff = slStaff('support', 'Lê Hoàng Phúc');
    $code = slSession($staff, 'Nguyễn Ngọc Anh', 2);

    $page = Livewire::actingAs($staff)->test(Index::class)
        ->assertSee('Kết thúc phiên ngay…')
        ->call('askEnd', $code)
        ->assertSet('endOpen', true)->assertSet('ending', $code)
        ->assertSee('Kết thúc phiên ngay?')
        ->assertSee('Lê Hoàng Phúc sẽ bị đưa ra khỏi trang quản trị của Cơ khí An Vinh ngay');
    expect(AuditLog::query()->where('action', SupportSessions::ENDED)->count())->toBe(0);

    $page->call('endNow')
        ->assertSet('endOpen', false)->assertSet('ending', '')
        ->assertSet('toastTone', 'success')
        ->assertSee('Đã kết thúc phiên của Lê Hoàng Phúc. Thao tác trong phiên vẫn nằm ở Nhật ký hệ thống.');

    $end = AuditLog::query()->where('action', SupportSessions::ENDED)->sole();
    expect($end->meta)->toBe(['why' => 'ended_by'])
        ->and($end->support_session_ulid)->toBe($code)
        ->and($end->site_id)->toBe(2)
        ->and($end->actor_id)->toBe($staff->id);
    expect($page->viewData('rows')[0]['status'])->toBe('ended')
        ->and($page->viewData('counts'))->toBe(['all' => 1, 'open' => 0, 'ended' => 1]);
    $page->assertSee('quản trị kết thúc');
});

it('refuses a colleague\'s session to staff who are not the platform super-admin, saying why', function (): void {
    $colleague = slStaff('support', 'Trần Thu Hà');
    $staff = slStaff('support', 'Lê Hoàng Phúc');
    $code = slSession($colleague);

    Livewire::actingAs($staff)->test(Index::class)
        // the item stays in the menu, locked, with the reason under it
        ->assertSeeHtml('class="list-mwhy">Chỉ Quản trị nền tảng kết thúc được phiên của người khác.</span>')
        ->assertDontSeeHtml('askEnd(')
        ->call('askEnd', $code)
        ->assertSet('endOpen', false)->assertSet('ending', '')
        ->assertSet('toastTone', 'error')
        ->assertSet('toast', 'Chỉ Quản trị nền tảng kết thúc được phiên của người khác.');

    expect(AuditLog::query()->where('action', SupportSessions::ENDED)->count())->toBe(0);
});

it('lets the platform super-admin end anyone\'s session', function (): void {
    $colleague = slStaff('support', 'Trần Thu Hà');
    $super = slStaff('super_admin', 'Đinh Quang Minh');
    $code = slSession($colleague);

    Livewire::actingAs($super)->test(Index::class)
        ->call('askEnd', $code)->assertSet('endOpen', true)
        ->call('endNow')
        ->assertSee('Đã kết thúc phiên của Trần Thu Hà.');

    $end = AuditLog::query()->where('action', SupportSessions::ENDED)->sole();
    expect($end->meta)->toBe(['why' => 'ended_by'])->and($end->actor_id)->toBe($super->id);
});

it('says a session is already over instead of asking, and names no unknown code', function (): void {
    $staff = slStaff('support');
    $left = slSession($staff, ago: 10);
    slEnd($left, 'left', ago: 5);

    Livewire::actingAs($staff)->test(Index::class)
        ->call('askEnd', $left)
        ->assertSet('endOpen', false)->assertSet('toastTone', 'info')->assertSet('toast', 'Phiên hỗ trợ đã kết thúc.')
        ->call('askEnd', (string) Str::ulid())
        ->assertSet('endOpen', false)->assertSet('toastTone', 'error')->assertSet('toast', 'Không tìm thấy phiên hỗ trợ này.')
        ->call('askEnd', '1')
        ->assertSet('toast', 'Không tìm thấy phiên hỗ trợ này.');

    expect(AuditLog::query()->where('action', SupportSessions::ENDED)->count())->toBe(1);
});

it('keeps the end question and the toast out of the browser\'s hands', function (): void {
    $staff = slStaff('support');
    $code = slSession(slStaff('support'));

    $page = Livewire::actingAs($staff)->test(Index::class);
    expect(fn () => $page->set('ending', $code))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $page->set('toast', 'x'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('names a chosen staff member with no session at 0, and nobody for a code that is not staff', function (): void {
    $phuc = slStaff('support', 'Lê Hoàng Phúc');
    $idle = slStaff('support', 'Trần Thu Hà');
    slSession($phuc);
    $member = User::factory()->create(['name' => 'Khách Võ Thị Mai']);

    $page = Livewire::actingAs($phuc)->test(Index::class)->set('staff', [$idle->ulid, $member->ulid]);

    expect(array_column($page->viewData('staffChoices'), 'count', 'label'))->toBe(['Lê Hoàng Phúc' => 1, 'Trần Thu Hà' => 0]);
    $page->assertDontSee('Khách Võ Thị Mai');
});

it('shows the empty state with the way to the websites', function (): void {
    $staff = slStaff('support');

    Livewire::actingAs($staff)->test(Index::class)
        ->assertSee('Chưa có phiên hỗ trợ nào')
        ->assertSee('Mở trang Website')
        ->assertSeeHtml('href="'.route('platform.sites.index').'"');
});

it('reports a failed read and offers to try again, keeping the filters', function (): void {
    Exceptions::fake();
    $staff = slStaff('support');
    slSession($staff);
    app()->instance(BoundedRead::class, new class extends BoundedRead
    {
        #[Override]
        public function run(Closure $read, int $milliseconds = 3000): mixed
        {
            return parent::run(fn (): mixed => DB::select('select * from support_table_that_does_not_exist'));
        }
    });

    Livewire::actingAs($staff)->test(Index::class, ['search' => 'menu'])
        ->assertSee('Chưa tải được các phiên hỗ trợ')
        ->assertSee('Bộ lọc bạn chọn vẫn giữ nguyên.')
        ->assertSee('Thử lại')
        ->assertSet('search', 'menu');

    Exceptions::assertReported(QueryException::class);
});

it('links each session to its website and to its own entries in the system log', function (): void {
    $staff = slStaff('support');
    $code = slSession($staff, site: 2);
    $site = (string) DB::connection('pgsql_admin')->table('sites')->where('id', 2)->value('ulid');

    Livewire::actingAs($staff)->test(Index::class)
        ->assertSeeHtml('href="'.route('platform.sites.show', $site).'"')
        ->assertSeeHtml('href="'.e(route('platform.audit.index', ['session' => $code])).'"');
});

it('reads a page in the same number of queries however many sessions it shows', function (): void {
    $staff = slStaff('support');
    slSession($staff);
    $queries = function () use ($staff): int {
        $this->actingAs($staff)->get(slHost().'/support')->assertOk(); // warm up: grants, settings

        return countQueries(fn () => $this->actingAs($staff)->get(slHost().'/support')->assertOk());
    };

    $few = $queries();
    for ($i = 0; $i < 15; $i++) {
        $other = slStaff('support');
        $code = slSession($other, 'Thành viên '.$i, site: $i % 2 + 1, ago: $i + 2);
        if ($i % 3 === 0) {
            slEnd($code, $i % 2 === 0 ? 'left' : 'ended_by', site: $i % 2 + 1);
        }
    }
    $many = $queries();

    expect($many)->toBe($few)->and($few)->toBeLessThanOrEqual(30);
});

it('never puts a row id into the page or its state, only session codes', function (): void {
    $staff = slStaff('super_admin');
    $code = slSession($staff, site: 2);
    $ids = AuditLog::query()->pluck('id')->all();

    $page = Livewire::actingAs($staff)->test(Index::class)->call('askEnd', $code);
    $html = $page->html();

    expect($html)->toMatch('~askEnd\((&#039;|&quot;|\')'.$code.'~')
        ->and($html)->toContain('wire:key="ss-'.$code.'"')
        ->and($html)->not->toMatch('~askEnd\(\d+\)~')
        ->and($html)->not->toMatch('~href="[^"]*/\d+(/|"|\?)~');
    foreach ($ids as $id) {
        expect($html)->not->toContain('ss-'.$id.'"');
    }

    // the only numbers the component keeps are the page size and the toast counter
    $ints = array_keys(array_filter($page->instance()->all(), 'is_int'));
    sort($ints);
    expect($ints)->toBe(['per', 'toastSeq'])
        ->and($page->get('ending'))->toBe($code);
});
