<?php

use App\Modules\Admin\Livewire\Platform\Tenants\Detail;
use App\Modules\Admin\Livewire\Platform\Tenants\Index;
use App\Modules\Admin\Support\Customers\CustomerDirectory;
use App\Modules\Admin\Support\Customers\CustomerHistory;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Support\CustomerCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The platform's customer screens (design 03 "Khách hàng"): the list and one customer's page.
 * `tenants` is platform-wide (no RLS), read with no tenant context and only by staff who hold
 * platform.tenants.view. Customers are addressed by ulid, never by id. The list reads newest
 * first, page by page from the last row seen; the search ignores diacritics and covers the
 * customer's name and its owner's name and email, from three letters or digits in a row, and the
 * customer's code exactly. The member's page size and columns are their own, each saved on its
 * own without touching the rest of their preferences. A customer's page shows its code and its
 * history, newest first.
 */

beforeEach(function (): void {
    truncateRbac();
    // members and their audit rows from earlier tests (fixed emails below would collide)
    DB::connection('pgsql_admin')->statement('truncate users, audit_logs restart identity cascade');
    seedTenancy(); // customer T1 (id 1) with websites A (a.test) and B (b.test)
    DB::connection('pgsql_admin')->statement('delete from plans');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    app()->setLocale('vi'); // Livewire::test renders outside the locale middleware
    // the seeded ulid is not a real one, and the screens take only real ones from the browser
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['ulid' => (string) Str::ulid()]);
});

function khStaff(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function khCustomer(string $name, array $attributes = []): string
{
    $ulid = (string) Str::ulid();
    DB::connection('pgsql_admin')->table('tenants')->insert($attributes + [
        'ulid' => $ulid, 'name' => $name, 'status' => 'active', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $ulid;
}

function khPlan(string $key, string $name, bool $active = true): int
{
    return (int) DB::connection('pgsql_admin')->table('plans')->insertGetId([
        'key' => $key, 'name' => $name, 'is_active' => $active, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function khHost(): string
{
    return 'http://'.config('tenancy.platform_host');
}

/**
 * Each needle appears in $html, each after the one before.
 *
 * @param  list<string>  $needles
 */
function khInOrder(string $html, array $needles): void
{
    $at = 0;
    foreach ($needles as $needle) {
        $found = mb_strpos($html, $needle, $at);
        expect($found)->not->toBeFalse("“{$needle}” missing or out of order");
        $at = (int) $found + mb_strlen($needle);
    }
}

function khNo(string $ulid): int
{
    return (int) DB::connection('pgsql_admin')->table('tenants')->where('ulid', $ulid)->value('customer_no');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function khHistory(int $tenantId, ?int $from, ?int $to, string $reason, ?int $actor, string $at, array $attributes = []): void
{
    DB::connection('pgsql_admin')->table('tenant_history')->insert($attributes + [
        'tenant_id' => $tenantId, 'kind' => 'plan_changed', 'from_plan_id' => $from, 'to_plan_id' => $to,
        'reason' => $reason, 'actor_user_id' => $actor, 'created_at' => $at,
    ]);
}

it('lists customers with their owner, plan, websites and join date, linked by ulid only', function (): void {
    $owner = User::factory()->create(['name' => 'Nguyễn Văn Hùng', 'email' => 'hung@anvinh.test']);
    $plan = khPlan('pro', 'Chuyên nghiệp');
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['name' => 'Cơ khí An Vinh', 'owner_user_id' => $owner->id, 'plan_id' => $plan]);
    $ulid = (string) DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->value('ulid');

    $this->actingAs(khStaff('super_admin'))->get(khHost().'/tenants')
        ->assertOk()
        ->assertSee('Cơ khí An Vinh')
        ->assertSee('Nguyễn Văn Hùng')
        ->assertSee('hung@<wbr>anvinh.<wbr>test', false)
        ->assertSee('Chuyên nghiệp')
        ->assertSee('2 website')
        ->assertSee(route('platform.tenants.show', $ulid))
        ->assertDontSee('/tenants/1"', false)
        // a super admin can do everything here: no note about a limited group
        ->assertDontSee(__('admin.customers_role_strip_support'))
        ->assertDontSee(__('admin.customers_role_strip_billing'));
});

it('finds customers without diacritics by name, owner name or owner email', function (): void {
    $owner = User::factory()->create(['name' => 'Nguyễn Thị Lan', 'email' => 'Lan.Tran@Example.test']);
    $byName = khCustomer('Nha khoa Tâm Đức');
    $byOwner = khCustomer('Spa Hoa Sen', ['owner_user_id' => $owner->id]);
    $other = khCustomer('Cửa hàng Bình An');
    $page = Livewire::actingAs(khStaff('support'))->test(Index::class);

    $found = fn (string $q): array => $page->set('search', $q)->viewData('rows')->pluck('ulid')->map(fn ($u): string => trim((string) $u))->all();

    expect($found('tam duc'))->toBe([$byName])
        ->and($found('NGUYEN'))->toBe([$byOwner])
        ->and($found('lan.tran@'))->toBe([$byOwner])
        ->and($found('binh an'))->toBe([$other])
        ->and($found('khong co ai'))->toBe([]);
    $page->assertSee(__('admin.customers_nomatch_title'));
});

it('finds a customer by its code, exactly, whatever the case or padding, with or without the prefix', function (): void {
    $wanted = khCustomer('Nha khoa Tâm Đức');
    $other = khCustomer('Spa Hoa Sen');
    $no = khNo($wanted);
    $page = Livewire::actingAs(khStaff('support'))->test(Index::class);
    $found = fn (string $q): array => $page->set('search', $q)->viewData('rows')->pluck('ulid')->map(fn ($u): string => trim((string) $u))->all();

    expect($found(CustomerCode::format($no)))->toBe([$wanted])
        ->and($found(mb_strtolower(CustomerCode::format($no))))->toBe([$wanted])
        ->and($found(config('customers.code_prefix').$no))->toBe([$wanted])
        ->and($found(CustomerCode::format(khNo($other))))->toBe([$other])
        // the number alone, padded or not
        ->and($found(str_pad((string) $no, 6, '0', STR_PAD_LEFT)))->toBe([$wanted])
        ->and($found((string) $no))->toBe([$wanted])
        // too many digits for a number, or 0: no code, and no name has them
        ->and($found('9999999999999999999'))->toBe([])
        ->and($found('0'))->toBe([])
        // a number no customer has
        ->and($found(CustomerCode::format($no + 1000)))->toBe([]);
    $page->assertSee(__('admin.customers_filter_search_chip', ['q' => CustomerCode::format($no + 1000)]));
});

it('finds one or two letters at the start of a word of the customer\'s or the owner\'s name', function (): void {
    $owner = User::factory()->create(['name' => 'Hà Thị Mai', 'email' => 'an@example.test']);
    $anVinh = khCustomer('Cơ khí An Vinh');
    $anPhat = khCustomer('An Phát');
    $haNoi = khCustomer('Hà Nội Steel');
    $byOwner = khCustomer('Spa Hoa Sen', ['owner_user_id' => $owner->id]);
    khCustomer('Thanh Bình'); // "an" inside a word only
    $page = Livewire::actingAs(khStaff('support'))->test(Index::class);
    $found = function (string $q) use ($page): array {
        $rows = $page->set('search', $q)->viewData('rows')->pluck('ulid')->map(fn ($u): string => trim((string) $u))->all();
        sort($rows);

        return $rows;
    };
    $sorted = function (string ...$ulids): array {
        sort($ulids);

        return $ulids;
    };

    // the owner's email is no word: "an@…" does not count for "an"
    expect($found('an'))->toBe($sorted($anVinh, $anPhat))
        ->and($found(' AN '))->toBe($sorted($anVinh, $anPhat))
        ->and($found('ha'))->toBe($sorted($haNoi, $byOwner))
        ->and($found('hà'))->toBe($sorted($haNoi, $byOwner))
        ->and($found('h'))->toBe($sorted($haNoi, $byOwner))
        // each part starts a word, in any order
        ->and($found('a v'))->toBe([$anVinh])
        ->and($found('v a'))->toBe([$anVinh])
        ->and($found('ă'))->toBe($sorted($anVinh, $anPhat))
        ->and($found('xy'))->toBe([]);
    $page->set('search', 'an')
        ->assertSee(__('admin.customers_filter_search_chip', ['q' => 'an']))
        ->assertDontSee(__('admin.customers_search_no_letters'));
    $page->set('search', 'xy')->assertSee(__('admin.customers_nomatch_title'));
});

it('runs no search for text with no letter or digit, and says what to type', function (): void {
    khCustomer('An Phát');
    $page = Livewire::actingAs(khStaff('support'))->test(Index::class);

    foreach (['-', ' % ', '@.', '__'] as $nothing) {
        $page->set('search', $nothing);
        expect($page->viewData('rows'))->toHaveCount(2)
            ->and($page->viewData('baseCounts'))->toBeNull();
        $page->assertSee(__('admin.customers_search_no_letters'))
            ->assertDontSee(__('admin.customers_nomatch_title'));
    }

    // text that is not UTF-8 never reaches the database
    expect(app(CustomerDirectory::class)->page("\xff\xfe", '', [], 20, '', '')['rows'])->toHaveCount(2);
});

it('reads newest first, page by page, with no customer repeated or skipped', function (): void {
    $at = now()->subDay();
    $made = [];
    for ($i = 0; $i < 25; $i++) {
        // the same moment for all: the id breaks the tie
        $made[] = khCustomer('Khách '.$i, ['created_at' => $at, 'updated_at' => $at]);
    }
    $expected = DB::connection('pgsql_admin')->table('tenants')->orderByDesc('created_at')->orderByDesc('id')->pluck('ulid')
        ->map(fn ($u): string => trim((string) $u))->all();

    $page = Livewire::actingAs(khStaff('support'))->test(Index::class)->set('per', 10);
    $seen = [];
    $first = $page->viewData('rows')->pluck('ulid')->map(fn ($u): string => trim((string) $u))->all();
    $seen = [...$seen, ...$first];
    while (($older = $page->viewData('older')) !== null) {
        $page->call('older', $older);
        $seen = [...$seen, ...$page->viewData('rows')->pluck('ulid')->map(fn ($u): string => trim((string) $u))->all()];
    }

    expect($seen)->toBe($expected)->and($seen)->toHaveCount(26);

    // and back: the page before the second is the first again
    $page->set('after', '')->set('before', '');
    $page->call('older', $page->viewData('older'));
    $page->call('newer', $page->viewData('newer'));
    expect($page->viewData('rows')->pluck('ulid')->map(fn ($u): string => trim((string) $u))->all())->toBe($first);

    // a cursor from the browser that is not a ulid is dropped, never used as an id
    $page->set('after', '3')->assertSet('after', '');
});

it('pages past a customer with no join date, which sorts as the oldest', function (): void {
    $undated = khCustomer('Không ngày', ['created_at' => null, 'updated_at' => null]);
    for ($i = 0; $i < 3; $i++) {
        khCustomer('Khách '.$i, ['created_at' => now()->subMinutes($i)]);
    }
    $page = Livewire::actingAs(khStaff('support'))->test(Index::class)->set('per', 10);
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['created_at' => null]);
    $ulids = fn (): array => $page->viewData('rows')->pluck('ulid')->map(fn ($u): string => trim((string) $u))->all();

    $page->call('planChanged'); // read again
    $all = $ulids();
    expect($all)->toHaveCount(5)->and($all[3])->toBe($undated);

    // a page whose last row has no date still has an older page after it
    $page->call('older', $all[3]);
    expect($ulids())->toBe([$all[4]]);
});

it('counts the status groups and filters by plan, a plan no longer sold included', function (): void {
    $pro = khPlan('pro', 'Chuyên nghiệp');
    $old = khPlan('old', 'Cũ', false);
    $a = khCustomer('An Phát', ['plan_id' => $pro]);
    khCustomer('Bảo Minh', ['plan_id' => $old, 'status' => 'suspended']);
    $page = Livewire::actingAs(khStaff('support'))->test(Index::class);

    expect($page->viewData('counts'))->toBe(['all' => 3, 'active' => 2, 'suspended' => 1]);

    $page->set('plans', ['pro']);
    expect($page->viewData('rows')->pluck('ulid')->map(fn ($u): string => trim((string) $u))->all())->toBe([$a])
        ->and($page->viewData('counts'))->toBe(['all' => 1, 'active' => 1, 'suspended' => 0]);
    $page->assertSee(__('admin.customers_filter_summary_of', ['n' => 1, 'm' => 3]));

    // with no search, the plan's counts and the "trong M" totals come from one GROUP BY
    $groupBys = 0;
    DB::listen(function ($query) use (&$groupBys): void {
        $groupBys += str_contains($query->sql, 'group by "tenants"."status"') ? 1 : 0;
    });
    $page->call('$refresh');
    expect($groupBys)->toBe(1)
        ->and($page->viewData('baseCounts'))->toBe(['all' => 3, 'active' => 2, 'suspended' => 1]);

    $page->set('plans', ['old'])->set('tab', 'suspended');
    expect($page->viewData('rows')->pluck('name')->all())->toBe(['Bảo Minh']);
    $page->assertSee(__('admin.customers_plan_legacy', ['plan' => 'Cũ']));

    // unknown values from the browser fall back to safe ones
    $page->set('tab', 'deleted')->assertSet('tab', 'all')->set('plans', ['nope'])->assertSet('plans', ['nope']);
    expect($page->viewData('rows'))->toHaveCount(3);
});

it('keeps the member\'s page size and columns, each written without touching their other choices', function (): void {
    $user = khStaff('support');
    $user->forceFill(['preferences' => ['theme' => ['scheme' => 'dark'], 'lists' => ['other.list' => ['per' => 50]]]])->save();

    Livewire::actingAs($user)->test(Index::class)->set('per', 50)->set('cols', ['joined', 'plan', 'nope']);

    $prefs = $user->fresh()->preferences;
    expect($prefs['theme'])->toBe(['scheme' => 'dark'])
        ->and($prefs['lists']['other.list'])->toBe(['per' => 50])
        ->and($prefs['lists'][Index::PREFS]['per'])->toBe(50)
        ->and($prefs['lists'][Index::PREFS]['cols'])->toBe(['plan', 'joined']);

    // the next visit starts from them; a hidden column is not drawn
    Livewire::actingAs($user->fresh())->test(Index::class)
        ->assertSet('per', 50)->assertSet('cols', ['plan', 'joined'])
        ->assertDontSeeHtml('list-c-f2')
        ->assertSeeHtml('list-c-f3')
        ->assertSee(__('admin.customers_columns_hidden', ['n' => 1]));
});

it('shows one customer: owner, plan values, websites with their main domain', function (): void {
    $owner = User::factory()->create(['name' => 'Trần Minh', 'email' => 'minh@example.test']);
    $plan = khPlan('pro', 'Chuyên nghiệp');
    DB::connection('pgsql_admin')->table('plan_features')->insert(['plan_id' => $plan, 'feature_key' => 'locales.max', 'value' => '3']);
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['owner_user_id' => $owner->id, 'plan_id' => $plan]);
    $ulid = trim((string) DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->value('ulid'));

    $this->actingAs(khStaff('super_admin'))->get(khHost().'/tenants/'.$ulid)
        ->assertOk()
        ->assertSee('Trần Minh')
        ->assertSee('mailto:minh@example.test', false)
        ->assertSee(__('admin.customers_plan_tag', ['plan' => 'Chuyên nghiệp']))
        ->assertSeeInOrder(['Số ngôn ngữ tối đa', '3'])
        ->assertSee(__('admin.customers_sites_usage', ['used' => 2, 'max' => 1]))
        ->assertSee('a.test')
        ->assertSee(route('platform.sites.show', trim((string) DB::connection('pgsql_admin')->table('sites')->where('id', 1)->value('ulid'))))
        ->assertSeeInOrder([__('admin.customers_info_code'), CustomerCode::format(khNo($ulid))]);
});

it('shows the customer\'s history newest first, who made each change, and older rows on request', function (): void {
    $staff = khStaff('billing');
    $hien = User::factory()->create(['name' => 'Trần Thu Hà']);
    $basic = khPlan('basic', 'Cơ bản');
    $pro = khPlan('pro', 'Chuyên nghiệp');
    $ulid = trim((string) DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->value('ulid'));

    $page = Livewire::actingAs($staff)->test(Detail::class, ['ulid' => $ulid]);
    $page->assertSee(__('admin.customers_history_title'))->assertSee(__('admin.customers_history_empty'))
        ->assertDontSee(__('admin.customers_history_older'));

    khHistory(1, null, $basic, 'Bắt đầu', null, '2025-03-12 01:45:00+00');
    khHistory(1, $basic, $pro, 'Khách mở thêm website phụ tùng', $hien->id, '2025-06-01 03:30:00+00');
    // another customer's history never shows here
    $other = khCustomer('Khác');
    khHistory((int) DB::connection('pgsql_admin')->table('tenants')->where('ulid', $other)->value('id'), null, $pro, 'Không thuộc khách này', null, '2025-07-01 00:00:00+00');

    $page->call('planChanged')->assertDontSee(__('admin.customers_history_empty'));
    khInOrder($page->html(), [
        __('admin.customers_history_with_reason', ['what' => __('admin.customers_history_plan_changed', ['from' => 'Cơ bản', 'to' => 'Chuyên nghiệp']), 'reason' => 'Khách mở thêm website phụ tùng']),
        'Trần Thu Hà',
        __('admin.customers_history_with_reason', ['what' => __('admin.customers_history_plan_changed', ['from' => __('admin.customers_no_plan'), 'to' => 'Cơ bản']), 'reason' => 'Bắt đầu']),
        __('admin.customers_history_system'),
    ]);
    $page->assertDontSee('Không thuộc khách này')
        ->assertDontSee(__('admin.customers_history_older'));

    // more than a page: the newest ten, then the rest below them; the same moment for many (the id breaks the tie)
    for ($i = 1; $i <= 11; $i++) {
        khHistory(1, $pro, $basic, 'Lần '.$i, null, '2026-01-01 00:00:00+00');
    }
    $page->call('planChanged')->assertSee(__('admin.customers_history_older'))
        ->assertSee('Lần 11')->assertSee('Lần 2')->assertDontSeeHtml('Lần 1<')
        ->assertDontSee('Khách mở thêm website phụ tùng');
    $cursor = $page->viewData('historyOlder');
    expect($cursor)->toBeString()->not->toContain('|');

    $page->call('olderHistory', $cursor)->assertDontSee(__('admin.customers_history_older'));
    khInOrder($page->html(), ['Lần 11', 'Lần 2', 'Lần 1<', 'Khách mở thêm website phụ tùng', 'Bắt đầu']);

    // a cursor the browser made up reads as the first page
    $page->set('historyUntil', 'nope')->assertSee('Lần 2')->assertDontSee('Bắt đầu');
    $page->call('olderHistory', base64_encode('2026-01-01|1'))->assertSet('historyUntil', '')->assertDontSee('Bắt đầu');

    // a real cursor of another customer's history reads as the first page here
    $otherId = (int) DB::connection('pgsql_admin')->table('tenants')->where('ulid', $other)->value('id');
    for ($i = 1; $i <= 11; $i++) {
        khHistory($otherId, $pro, $basic, 'Khác '.$i, null, '2026-02-01 00:00:00+00');
    }
    $foreign = Livewire::actingAs($staff)->test(Detail::class, ['ulid' => $other])->viewData('historyOlder');
    expect($foreign)->toBeString();
    $page->set('historyUntil', $foreign)->assertSee('Lần 2')->assertDontSee('Bắt đầu')->assertDontSee('Khác 1');
    $page->set('historyUntil', '')->call('olderHistory', $foreign)->assertSet('historyUntil', '');
});

it('reads at most a fixed number of history rows in one render, however far "older" went', function (): void {
    $pro = khPlan('pro', 'Chuyên nghiệp');
    $total = CustomerHistory::MAX_ROWS + 25;
    DB::connection('pgsql_admin')->statement(
        "insert into tenant_history (tenant_id, kind, to_plan_id, reason, created_at)
         select 1, 'plan_changed', ?, 'Lần ' || i, timestamptz '2026-01-01 00:00:00+00' + make_interval(mins => i)
         from generate_series(1, ?) as i",
        [$pro, $total],
    );
    $history = app(CustomerHistory::class);

    $first = $history->read(1, '');
    expect($first['rows'])->toHaveCount(CustomerHistory::PAGE)->and($first['more'])->toBeTrue()
        ->and($first['rows'][0]['reason'])->toBe('Lần '.$total);

    // "older" all the way down: each step moves the anchor, never past the oldest row
    $cursor = (string) $first['oldest'];
    for ($step = 0; $step < intdiv($total, CustomerHistory::PAGE) + 1; $step++) {
        $cursor = $history->older(1, $cursor);
    }
    $last = $history->read(1, $cursor);
    expect($last['rows'])->toHaveCount(CustomerHistory::MAX_ROWS)
        ->and($last['more'])->toBeFalse()
        ->and($last['rows'][CustomerHistory::MAX_ROWS - 1]['reason'])->toBe('Lần 1')
        ->and($last['rows'][0]['reason'])->toBe('Lần '.CustomerHistory::MAX_ROWS);
});

it('says why a customer is suspended, and shows no line when no reason was given', function (): void {
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['status' => 'suspended', 'settings' => json_encode(['suspension_reason' => 'Quá hạn thanh toán'])]);
    $ulid = trim((string) DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->value('ulid'));
    $silent = khCustomer('Không lý do', ['status' => 'suspended']);
    $staff = khStaff('support');

    $this->actingAs($staff)->get(khHost().'/tenants/'.$ulid)->assertOk()
        ->assertSee(__('admin.customers_suspended_why', ['why' => 'Quá hạn thanh toán']));
    $this->actingAs($staff)->get(khHost().'/tenants/'.$silent)->assertOk()
        ->assertDontSee('Đang tạm ngưng:');
});

it('answers an unknown customer address with 404 and a way back', function (): void {
    $this->actingAs(khStaff('support'))->get(khHost().'/tenants/'.Str::ulid())
        ->assertNotFound()
        ->assertSee(__('admin.customers_notfound_title'))
        ->assertSee(route('platform.tenants.index'));

    $this->actingAs(khStaff('support'))->get(khHost().'/tenants/1')->assertNotFound();
});

it('locks plan changes for support, with the reason, and keeps website pages from billing', function (): void {
    $ulid = trim((string) DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->value('ulid'));
    $siteLink = route('platform.sites.show', trim((string) DB::connection('pgsql_admin')->table('sites')->where('id', 1)->value('ulid')));

    $support = khStaff('support');
    $this->actingAs($support)->get(khHost().'/tenants')->assertOk()
        ->assertSee(__('admin.customers_role_strip_support'))
        ->assertSee(__('admin.customers_plan_locked', ['role' => __('admin.customers_role_support')]))
        ->assertSeeHtml('list-mi-locked');
    $this->actingAs($support)->get(khHost().'/tenants/'.$ulid)->assertOk()
        ->assertSee('aria-disabled="true"', false)
        ->assertSee($siteLink);

    $billing = khStaff('billing');
    $this->actingAs($billing)->get(khHost().'/tenants')->assertOk()
        ->assertSee(__('admin.customers_role_strip_billing'))
        ->assertDontSeeHtml('list-mi-locked')
        ->assertSee(__('admin.customers_action_change_plan'));
    $this->actingAs($billing)->get(khHost().'/tenants/'.$ulid)->assertOk()
        ->assertDontSee($siteLink)
        ->assertSee(__('admin.customers_sites_locked'));
});

it('forbids anyone without platform.tenants.view, on the page and on every update', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'admin', ['users.manage', 'entries.view']));
    $ulid = trim((string) DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->value('ulid'));

    $this->actingAs($user)->get(khHost().'/tenants')->assertForbidden();
    $this->actingAs($user)->get(khHost().'/tenants/'.$ulid)->assertForbidden();
    Livewire::actingAs($user)->test(Index::class)->assertForbidden();
    Livewire::actingAs($user)->test(Detail::class, ['ulid' => $ulid])->assertForbidden();
});

it('reads the list and a customer in a fixed number of queries, however many there are', function (): void {
    $staff = khStaff('billing');
    $plan = khPlan('pro', 'Chuyên nghiệp');
    $owner = User::factory()->create();
    $listQueries = function () use ($staff): int {
        $this->actingAs($staff)->get(khHost().'/tenants')->assertOk(); // warm up: grants, settings

        return countQueries(fn () => $this->actingAs($staff)->get(khHost().'/tenants')->assertOk());
    };
    $ulid = trim((string) DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->value('ulid'));
    $detailQueries = function () use ($staff, $ulid): int {
        $this->actingAs($staff)->get(khHost().'/tenants/'.$ulid)->assertOk();

        return countQueries(fn () => $this->actingAs($staff)->get(khHost().'/tenants/'.$ulid)->assertOk());
    };

    $fewList = $listQueries();
    $fewDetail = $detailQueries();
    for ($i = 0; $i < 15; $i++) {
        khCustomer('Khách '.$i, ['plan_id' => $plan, 'owner_user_id' => $owner->id]);
    }
    $admin = DB::connection('pgsql_admin');
    for ($i = 0; $i < 4; $i++) {
        $site = (int) $admin->table('sites')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'S'.$i, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $admin->table('domains')->insert(['site_id' => $site, 'hostname' => "s{$i}.test", 'is_primary' => true, 'kind' => 'custom', 'created_at' => now(), 'updated_at' => now()]);
    }

    for ($i = 0; $i < 15; $i++) {
        khHistory(1, null, $plan, 'Lần '.$i, $owner->id, now()->subMinutes($i)->toIso8601String());
    }

    expect($listQueries())->toBe($fewList)
        ->and($detailQueries())->toBe($fewDetail)
        ->and($fewList)->toBeLessThanOrEqual(30)
        ->and($fewDetail)->toBeLessThanOrEqual(30);
});
