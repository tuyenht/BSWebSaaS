<?php

use App\Modules\Admin\Livewire\EmailRecentMail;
use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The «Email» page's «Thư gần đây» card: the website's newest mails, newest first, each with its kind,
 * its first address, whether it left and, when it did not, why in plain words. «Đã gửi» and «Lỗi»
 * narrow the list; their counts come from the day counts of the days the log keeps. Another
 * website's mails never show. The shared outbox's row says how many mails today still has.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate site_settings, platform_settings, mail_logs, mail_daily restart identity cascade');
    $admin->statement('select public.mail_log_months_ensure()');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    // noon on the website's clock, so «today» and «yesterday» never straddle a midnight
    Carbon::setTestNow(CarbonImmutable::now('UTC')->setTime(5, 0));
});

afterEach(function (): void {
    Carbon::setTestNow();
    DB::connection('pgsql_admin')->statement('truncate mail_logs, mail_daily restart identity cascade');
});

/** A new member of website 1 in its own role. Call OUTSIDE any site context: it enters the site itself. */
function ermAdmin(string $role = 'site-admin', array $permissions = ['settings.manage']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, $role, $permissions));

    return $user->fresh();
}

/** @param array<string, mixed> $row */
function ermLog(int $site, CarbonImmutable $at, array $row = []): void
{
    $admin = DB::connection('pgsql_admin');
    $admin->statement('select public.mail_log_month_create(?::date)', [$at->utc()->startOfMonth()->toDateString()]);
    $admin->table('mail_logs')->insert([
        'site_id' => $site, 'kind' => 'lead', 'via' => 'platform', 'recipient_count' => 1,
        'to_first' => 'khach@vidu.vn', 'status' => 'sent', 'reason_code' => null, 'created_at' => $at->utc(), ...$row,
    ]);
}

it('lists the website\'s newest mails with their kind, address, state and the reason a mail did not leave', function (): void {
    $now = CarbonImmutable::now('UTC');
    ermLog(1, $now->subMinutes(30), ['kind' => 'password_reset', 'to_first' => 'thuha@vidu.vn']);
    ermLog(1, $now->subDay(), ['kind' => 'invitation', 'to_first' => 'sai@dia-chi', 'status' => 'failed', 'reason_code' => 'rejected']);
    ermLog(1, $now->subDays(5), ['to_first' => 'a@vidu.vn', 'recipient_count' => 3]);
    ermLog(2, $now->subMinutes(10), ['to_first' => 'cua-website-khac@vidu.vn']);

    $user = ermAdmin();
    app(Tenancy::class)->enter(1, function () use ($now, $user): void {
        $local = $now->subMinutes(30)->setTimezone(app(SiteTime::class)->zone());
        Livewire::actingAs($user)->test(EmailRecentMail::class)
            ->assertSeeInOrder([$local->format('H:i'), 'Đặt lại mật khẩu', 'thuha@vidu.vn', 'Đã gửi',
                'Hôm qua', 'Lời mời thành viên', 'sai@dia-chi', 'Không gửi được', 'Máy chủ nhận từ chối thư này',
                'a@vidu.vn và 2 người khác'])
            ->assertDontSee('cua-website-khac@vidu.vn')
            ->assertSee('Giữ nhật ký 90 ngày');
    });
});

it('narrows the list to the sent or the failed mails, counted from the day counts of the days kept', function (): void {
    $now = CarbonImmutable::now('UTC');
    ermLog(1, $now->subHour(), ['to_first' => 'ok@vidu.vn']);
    ermLog(1, $now->subHours(2), ['to_first' => 'loi@vidu.vn', 'status' => 'failed', 'reason_code' => 'auth']);
    $admin = DB::connection('pgsql_admin');
    $admin->table('mail_daily')->insert([
        ['site_id' => 1, 'day' => $now->toDateString(), 'via' => 'platform', 'sent' => 4, 'failed' => 1, 'held' => 0],
        ['site_id' => 1, 'day' => $now->subDays(3)->toDateString(), 'via' => 'own', 'sent' => 2, 'failed' => 1, 'held' => 0],
        ['site_id' => 1, 'day' => $now->subDays(120)->toDateString(), 'via' => 'platform', 'sent' => 50, 'failed' => 9, 'held' => 0],
        ['site_id' => 2, 'day' => $now->toDateString(), 'via' => 'platform', 'sent' => 70, 'failed' => 7, 'held' => 0],
    ]);

    $user = ermAdmin();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EmailRecentMail::class)
            ->assertSee(['Đã gửi 6', 'Lỗi 2'])
            ->assertSee(['ok@vidu.vn', 'loi@vidu.vn'])
            ->call('setFilter', 'failed')
            ->assertSet('filter', 'failed')
            ->assertSee(['loi@vidu.vn', 'Máy chủ gửi thư từ chối đăng nhập'])
            ->assertDontSee('ok@vidu.vn')
            ->call('setFilter', 'sent')
            ->assertSee('ok@vidu.vn')
            ->assertDontSee('loi@vidu.vn')
            ->call('setFilter', 'spam')
            ->assertSet('filter', 'all');
    });
});

it('shows at most its row count, newest first', function (): void {
    $now = CarbonImmutable::now('UTC');
    foreach (range(1, EmailRecentMail::ROWS + 2) as $i) {
        ermLog(1, $now->subMinutes($i), ['to_first' => "moi{$i}@vidu.vn"]);
    }

    $user = ermAdmin();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EmailRecentMail::class)
            ->assertSee(['moi1@vidu.vn', 'moi'.EmailRecentMail::ROWS.'@vidu.vn'])
            ->assertDontSee('moi'.(EmailRecentMail::ROWS + 1).'@vidu.vn');
    });
});

it('lists and counts the same days: the website\'s last 90, today included', function (): void {
    $user = ermAdmin();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $today = app(SiteTime::class)->now()->startOfDay();
        $first = $today->subDays(EmailRecentMail::KEEP_DAYS - 1);
        ermLog(1, $first->setTime(8, 0), ['status' => 'failed', 'reason_code' => 'connection', 'to_first' => null]);
        ermLog(1, $first->subDay()->setTime(23, 0), ['status' => 'failed', 'reason_code' => 'auth', 'to_first' => 'cu@vidu.vn']);
        DB::connection('pgsql_admin')->table('mail_daily')->insert([
            ['site_id' => 1, 'day' => $first->toDateString(), 'via' => 'platform', 'sent' => 0, 'failed' => 1, 'held' => 0],
            ['site_id' => 1, 'day' => $first->subDay()->toDateString(), 'via' => 'platform', 'sent' => 0, 'failed' => 5, 'held' => 0],
        ]);

        Livewire::actingAs($user)->test(EmailRecentMail::class)
            ->call('setFilter', 'failed')
            ->assertSee(['Lỗi 1', 'Không kết nối được tới máy chủ gửi thư', 'Không ghi lại người nhận'])
            ->assertDontSee(['Lỗi 6', 'cu@vidu.vn', 'Máy chủ gửi thư từ chối đăng nhập'])
            ->call('setFilter', 'sent')
            ->assertSee('Không có thư nào ở trạng thái này trong 90 ngày qua.');
    });
});

it('tells a website with no mail yet that nothing was sent', function (): void {
    $user = ermAdmin();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EmailRecentMail::class)
            ->assertSee(['Đã gửi 0', 'Lỗi 0', 'Website chưa gửi thư nào trong 90 ngày qua.']);
    });
});

it('is the Email page\'s card, closed to anyone who cannot manage the website\'s settings', function (): void {
    $admin = ermAdmin();
    $writer = ermAdmin('site-writer', ['entries.view']);
    app(Tenancy::class)->enter(1, function () use ($admin, $writer): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSeeLivewire(EmailRecentMail::class);
        Livewire::actingAs($writer)->test(EmailRecentMail::class)
            ->assertForbidden();
    });
});

it('says on the shared outbox how many mails today still has, and its daily limit in the explanation', function (): void {
    $user = ermAdmin();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $today = app(SiteTime::class)->now()->toDateString();
        DB::connection('pgsql_admin')->table('mail_daily')->insert([
            ['site_id' => 1, 'day' => $today, 'via' => 'platform', 'sent' => 7, 'failed' => 0, 'held' => 0],
            ['site_id' => 1, 'day' => $today, 'via' => 'own', 'sent' => 9, 'failed' => 0, 'held' => 0],
        ]);

        Livewire::actingAs($user)->test(EmailSettings::class)
            ->assertSee('Còn 23 thư hôm nay')
            ->assertSeeHtml('style="width: 23%"')
            ->assertSee('Hạn mức hiện tại 30 thư/ngày, đặt lại lúc 00:00.');
    });
});
