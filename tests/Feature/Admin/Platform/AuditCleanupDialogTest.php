<?php

use App\Modules\Admin\Livewire\Platform\Audit\CleanupDialog;
use App\Modules\Admin\Livewire\Platform\Audit\Index;
use App\Modules\Admin\Support\PurgeAuditMonth;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Support\AuditMonths;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * "Dọn nhật ký" on the platform audit screen: only the platform super-admin sees it, and every action
 * of the dialog refuses anyone else. The months come from the planner's estimates; only the chosen
 * month is counted exactly. Every refusal of PurgeAuditMonth shows where the design puts it: password
 * refusals under the field, month refusals as the alert above the buttons.
 *
 * The log is split by month (see AuditMonthsTest): each test starts with the running month, the 24
 * before it and the coming ones; a test that removes a month puts it back in `finally`.
 */

beforeEach(function (): void {
    app()->setLocale('vi'); // Livewire::test renders outside the locale middleware
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate audit_logs restart identity cascade');
    $admin->statement('select public.audit_log_months_ensure()');
    foreach (range(-24, 0) as $offset) {
        $admin->statement('select public.audit_log_month_create(?::date)', [acdMonth($offset).'-01']);
    }
});

/** The month $offset months from the running one (UTC, database clock), 'YYYY-MM'. */
function acdMonth(int $offset): string
{
    return (string) DB::connection('pgsql_admin')->scalar(
        "select to_char(date_trunc('month', now() at time zone 'UTC') + make_interval(months => ?), 'YYYY-MM')",
        [$offset],
    );
}

/** 'YYYY-MM' as the dialog writes it. */
function acdLabel(string $month): string
{
    return substr($month, 5, 2).'/'.substr($month, 0, 4);
}

function acdExists(string $month): bool
{
    return (bool) DB::connection('pgsql_admin')->scalar(
        "select exists (select 1 from pg_inherits where inhparent = 'public.audit_logs'::regclass and inhrelid = to_regclass(?))",
        ['public.audit_logs_y'.substr($month, 0, 4).'m'.substr($month, 5, 2)],
    );
}

function acdCreate(string $month): void
{
    DB::connection('pgsql_admin')->statement('select public.audit_log_month_create(?::date)', [$month.'-01']);
}

function acdDropAsAdmin(string $month): void
{
    DB::connection('pgsql_admin')->statement('drop table if exists public.audit_logs_y'.substr($month, 0, 4).'m'.substr($month, 5, 2));
}

function acdRow(string $createdAt): AuditLog
{
    return AuditLog::query()->create(['action' => 'cms.menu.saved', 'meta' => [], 'created_at' => $createdAt]);
}

function acdOperator(string $role, string $password = 'password'): User
{
    truncateRbac();
    seedTenancy();
    test()->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $user = User::factory()->create(['password' => $password]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh() ?? $user;
}

/** The dialog opened by $actor with the months read and $month chosen and counted. */
function acdConfirming(User $actor, string $month): Testable
{
    return Livewire::actingAs($actor)->test(CleanupDialog::class)
        ->call('openDialog')->call('loadMonths')
        ->set('month', $month)->call('next')->call('countRows');
}

it('shows "Dọn nhật ký" to the platform super-admin only', function (string $role, bool $shown): void {
    $host = (string) config('tenancy.platform_host');
    $page = $this->actingAs(acdOperator($role))->get("http://{$host}/audit")->assertOk();

    $shown
        ? $page->assertSee('Dọn nhật ký')->assertSee('id="audit-cleanup-open"', false)
        : $page->assertDontSee('Dọn nhật ký')->assertDontSee('audit-cleanup-open');
})->with([
    'super-admin' => ['super_admin', true],
    'support staff' => ['support', false],
]);

it('refuses every action of the dialog to anyone but the platform super-admin', function (string $method, array $args): void {
    $admin = acdOperator('super_admin');
    $support = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $support->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    acdRow(acdMonth(-13).'-03 00:00:00+00');
    $dialog = acdConfirming($admin, acdMonth(-13));

    $this->actingAs($support->fresh() ?? $support);
    app(PlatformAccess::class)->forgetMemo();

    $dialog->call($method, ...$args)->assertForbidden();
    expect(acdExists(acdMonth(-13)))->toBeTrue();
})->with([
    'open' => ['openDialog', []],
    'load' => ['loadMonths', []],
    'next' => ['next', []],
    'count' => ['countRows', []],
    'retry' => ['retry', []],
    'back' => ['back', []],
    'purge' => ['purge', ['password']],
    'close' => ['close', []],
    'dismiss' => ['dismissToast', []],
]);

it('refuses the dialog itself to support staff', function (): void {
    Livewire::actingAs(acdOperator('support'))->test(CleanupDialog::class)->assertForbidden();
});

it('lists the old months with their estimates, the kept ones in one note, and never the months ahead', function (): void {
    $dialog = Livewire::actingAs(acdOperator('super_admin'))->test(CleanupDialog::class)
        ->call('openDialog')
        ->assertSee('Đang tải danh sách tháng…')
        ->call('loadMonths');

    $dialog->assertSee('Tháng có thể xóa (cũ nhất ở trên)')
        ->assertSee('Tháng '.acdLabel(acdMonth(-24)))
        ->assertSee('Tháng '.acdLabel(acdMonth(-13)))
        ->assertDontSee('Tháng '.acdLabel(acdMonth(-12)))
        ->assertSee('khoảng 0 ghi chép')
        ->assertSee('Luôn giữ lại: '.acdLabel(acdMonth(-12)).' – '.acdLabel(acdMonth(0)))
        ->assertSee('(13 tháng, khoảng 0 ghi chép).')
        ->assertSee('Nhật ký của tháng này và 12 tháng trước đó không xóa được, để việc gần đây luôn tra lại được.')
        ->assertSee('Việc này không bắt buộc: mỗi tháng hệ thống tự xóa nhật ký cũ hơn 24 tháng.')
        ->assertDontSee(acdLabel(acdMonth(1)))
        ->assertDontSee(acdLabel(acdMonth(3)));
    expect(array_column((array) $dialog->get('months'), 'month'))->not->toContain(acdMonth(1));
});

it('says when no month is old enough yet, and from when the oldest one can go', function (): void {
    $gone = array_map(fn (int $o): string => acdMonth($o), range(-24, -13));
    try {
        array_map(acdDropAsAdmin(...), $gone);

        Livewire::actingAs(acdOperator('super_admin'))->test(CleanupDialog::class)
            ->call('openDialog')->call('loadMonths')
            ->assertSee('Chưa có tháng nào đủ cũ để xóa')
            ->assertSee('Nhật ký của tháng này và 12 tháng trước đó luôn được giữ lại. Tháng cũ nhất hiện có là '
                .acdLabel(acdMonth(-12)).'; tháng đó xóa được từ tháng '.acdLabel(acdMonth(1)).'.')
            ->assertSee('Luôn giữ lại: '.acdLabel(acdMonth(-12)).' – '.acdLabel(acdMonth(0)))
            ->assertDontSee('Tiếp tục');
    } finally {
        array_map(acdCreate(...), $gone);
    }
});

it('says the months could not be read and reads them again on "Thử lại"', function (): void {
    $real = app(AuditMonths::class);
    app()->instance(AuditMonths::class, new class
    {
        public function runningMonth(): string
        {
            throw new QueryException('pgsql', 'select public.audit_log_months()', [], new RuntimeException('down'));
        }
    });

    $dialog = Livewire::actingAs(acdOperator('super_admin'))->test(CleanupDialog::class)
        ->call('openDialog')->call('loadMonths')
        ->assertSee('Chưa tải được danh sách tháng. Mạng chậm hoặc máy chủ đang bận.')
        ->assertSee('Thử lại')
        ->assertDontSee('Tiếp tục');

    app()->instance(AuditMonths::class, $real);
    $dialog->call('retry')->assertSee('Đang tải danh sách tháng…')
        ->call('loadMonths')->assertSee('Tháng '.acdLabel(acdMonth(-13)));
});

it('asks for a month when none is chosen, and takes only a month the list offers', function (): void {
    Livewire::actingAs(acdOperator('super_admin'))->test(CleanupDialog::class)
        ->call('openDialog')->call('loadMonths')
        ->call('next')
        ->assertSet('step', 'pick')
        ->assertSee('Hãy chọn một tháng trong danh sách.')
        ->set('month', acdMonth(-12))
        ->assertSet('month', '')
        ->call('next')
        ->assertSet('step', 'pick')
        ->set('month', acdMonth(-13))
        ->assertDontSee('Hãy chọn một tháng trong danh sách.')
        ->call('next')
        ->assertSet('step', 'confirm');
});

it('counts the chosen month exactly before the delete button can be used', function (): void {
    $month = acdMonth(-13);
    acdRow($month.'-03 00:00:00+00');
    acdRow($month.'-04 00:00:00+00');

    Livewire::actingAs(acdOperator('super_admin'))->test(CleanupDialog::class)
        ->call('openDialog')->call('loadMonths')->set('month', $month)->call('next')
        ->assertSee('Đang đếm số ghi chép của tháng '.acdLabel($month).'…')
        ->assertSee('Bước 2/2 · Xác nhận xóa')
        ->call('countRows')
        ->assertSet('exact', 2)
        ->assertSee('Xóa vĩnh viễn nhật ký tháng '.acdLabel($month).' — 2 ghi chép')
        ->assertSee('Nhập lại mật khẩu để xác nhận là bạn')
        ->assertSee('Xóa tháng '.acdLabel($month))
        ->assertSee('Đang xóa…');
});

it('reads the estimates once to list the months and counts only the chosen one', function (): void {
    $actor = acdOperator('super_admin');
    $sql = [];
    $dialog = Livewire::actingAs($actor)->test(CleanupDialog::class)->call('openDialog');
    DB::listen(function (QueryExecuted $q) use (&$sql): void {
        $sql[] = $q->sql;
    });

    $listing = countQueries(fn () => $dialog->call('loadMonths'));
    $listed = $sql;
    $sql = [];
    $dialog->set('month', acdMonth(-13))->call('next');
    $counting = countQueries(fn () => $dialog->call('countRows'));

    $fewer = array_map(fn (int $o): string => acdMonth($o), range(-24, -20));
    try {
        array_map(acdDropAsAdmin(...), $fewer);
        $again = Livewire::actingAs($actor)->test(CleanupDialog::class)->call('openDialog');
        $listingFewer = countQueries(fn () => $again->call('loadMonths'));
    } finally {
        array_map(acdCreate(...), $fewer);
    }

    expect($listing)->toBe($listingFewer)
        ->and(implode("\n", $listed))->toContain('audit_log_months()')
        ->and(implode("\n", $listed))->not->toContain('audit_log_month_count')
        ->and(array_values(array_filter($sql, fn (string $s): bool => str_contains($s, 'audit_log_month_count'))))->toHaveCount(1)
        ->and($counting)->toBeLessThanOrEqual(5);
});

it('asks for the password under the field when it is left empty', function (): void {
    acdConfirming(acdOperator('super_admin'), acdMonth(-13))
        ->call('purge', '')
        ->assertSee('Hãy nhập mật khẩu để xác nhận.')
        ->assertSee('aria-describedby="audit-cleanup-pw-err"', false);
    expect(acdExists(acdMonth(-13)))->toBeTrue();
});

it('shows a wrong password under the field, then the wait after too many', function (): void {
    $dialog = acdConfirming(acdOperator('super_admin'), acdMonth(-13));

    foreach (range(1, PurgeAuditMonth::ATTEMPTS) as $attempt) {
        $dialog->call('purge', 'not-it')->assertSee('Mật khẩu chưa đúng. Hãy nhập mật khẩu bạn dùng để đăng nhập.');
    }

    $dialog->call('purge', 'password')
        ->assertSee('Bạn đã nhập sai mật khẩu nhiều lần. Hãy thử lại sau')
        ->assertSet('monthError', '');
    expect(acdExists(acdMonth(-13)))->toBeTrue();
});

it('tells an account without a password to set one', function (): void {
    $actor = acdOperator('super_admin');
    DB::connection('pgsql_admin')->table('users')->where('id', $actor->id)->update(['password' => '']);

    acdConfirming($actor->fresh() ?? $actor, acdMonth(-13))
        ->call('purge', 'anything')
        ->assertSee('Tài khoản của bạn chưa có mật khẩu. Hãy đặt mật khẩu trong phần Cài đặt rồi thử lại.');
    expect(acdExists(acdMonth(-13)))->toBeTrue();
});

it('shows a busy log as the alert above the buttons and lets the member try again', function (): void {
    $dialog = acdConfirming(acdOperator('super_admin'), acdMonth(-13));
    $admin = DB::connection('pgsql_admin');
    $admin->beginTransaction();
    $admin->statement('lock table public.audit_logs in access share mode');

    try {
        $dialog->call('purge', 'password');
    } finally {
        $admin->rollBack();
    }

    $dialog->assertSee('Nhật ký đang bận ghi nên chưa xoá được. Hãy thử lại sau ít phút.')
        ->assertSee('id="audit-cleanup-alert"', false)
        ->assertSet('monthRefused', false)
        ->assertSet('passwordError', '');
    expect(acdExists(acdMonth(-13)))->toBeTrue();
});

it('takes a month gone meanwhile off the list and offers another', function (): void {
    $month = acdMonth(-13);
    $dialog = acdConfirming(acdOperator('super_admin'), $month);

    try {
        acdDropAsAdmin($month);
        $dialog->call('purge', 'password');
    } finally {
        acdCreate($month);
    }

    $dialog->assertSee('Nhật ký không có tháng này: tháng đã được xoá trước đó hoặc chưa từng có ghi chép nào.')
        ->assertSet('monthRefused', true);
    expect(array_column((array) $dialog->get('months'), 'month'))->not->toContain($month);

    $dialog->call('back')->assertSet('step', 'pick')->assertSet('month', '')
        ->assertDontSee('Tháng '.acdLabel($month));
});

it('shows each month refusal as the alert, and only a refused month asks for another', function (string $key, bool $another): void {
    $message = (string) __($key, ['months' => AuditMonths::MANUAL_FLOOR_MONTHS]);
    $dialog = acdConfirming(acdOperator('super_admin'), acdMonth(-13));
    app()->instance(PurgeAuditMonth::class, new class($message)
    {
        public function __construct(private string $message) {}

        public function handle(User $actor, string $month, string $password): int
        {
            throw ValidationException::withMessages(['month' => $this->message]);
        }
    });

    $dialog->call('purge', 'password')
        ->assertSee($message)
        ->assertSet('monthRefused', $another)
        ->assertSet('open', true);
})->with([
    'too recent' => ['admin.audit.purge.too_recent', true],
    'busy' => ['admin.audit.purge.busy', false],
    'clock skew' => ['admin.audit.purge.unrecorded', false],
]);

it('deletes the month, records it, closes with the toast and brings the list back to its first page', function (): void {
    $actor = acdOperator('super_admin');
    $month = acdMonth(-13);
    acdRow($month.'-03 00:00:00+00');
    acdRow($month.'-04 00:00:00+00');

    try {
        $dialog = acdConfirming($actor, $month)->call('purge', 'password');
        $gone = ! acdExists($month);
    } finally {
        acdCreate($month);
    }

    $dialog->assertSet('open', false)
        ->assertDispatched('audit-month-deleted')
        ->assertSee('Đã xóa nhật ký tháng '.acdLabel($month).': 2 ghi chép. Việc xóa đã được ghi vào nhật ký.');
    $row = AuditLog::query()->where('action', PurgeAuditMonth::ACTION)->sole();
    expect($gone)->toBeTrue()
        ->and($row->getAttribute('actor_id'))->toBe($actor->id)
        ->and($row->meta)->toMatchArray(['month' => $month, 'rows' => 2, 'confirmed' => 'password']);

    Livewire::actingAs($actor)->test(Index::class)
        ->set('open', (string) $row->ulid)
        ->assertSee('Nhật ký tháng '.acdLabel($month))
        ->assertSee('Số ghi chép đã xóa')
        ->assertSee('Xác nhận bằng')
        ->assertSee('Nhập lại mật khẩu')
        ->dispatch('audit-month-deleted')
        ->assertSet('open', '')
        ->assertSet('after', '');
});
