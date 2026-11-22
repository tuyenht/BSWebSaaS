<?php

use App\Modules\Admin\Livewire\AdvancedSettings;
use App\Modules\Admin\Support\AdminAddressLimit;
use App\Modules\Admin\Support\SupportSignIn;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Storefront\EdgeCache\PurgeSiteEdgeCache;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Authorize;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Cài đặt › «Bảo mật & Nâng cao», «Giới hạn nơi được vào trang quản trị» (site_settings
 * admin_access/ip_limit): while it is on, every door into the website's admin (its pages, its
 * sign-in pages, its Livewire updates) is open only from the listed addresses, for every member,
 * the owner included; a live support session is the one way past. The address is the request's own,
 * never a header the visitor writes. The storefront and other websites are untouched. The screen
 * never saves a list that would lock out the owner saving it.
 */

const IPL_UA = 'Mozilla/5.0 (support desk)';

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications, users, site_settings, platform_settings, audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    Notification::fake();
    Mail::fake();
});

/**
 * Limit site $site's admin to $addresses (on) or keep them switched off.
 *
 * @param  list<string>  $addresses
 */
function ipLimit(array $addresses, bool $on = true, int $site = 1): void
{
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => AdminAddressLimit::GROUP, 'key' => AdminAddressLimit::KEY],
        ['value' => ['on' => $on, 'entries' => array_map(fn (string $a): array => ['address' => $a, 'note' => ''], $addresses)]],
    ));
}

/** @return array<string, mixed>|null */
function ipStored(int $site = 1): ?array
{
    return app(Tenancy::class)->enter($site, fn () => SiteSetting::query()
        ->where('group_name', AdminAddressLimit::GROUP)->where('key', AdminAddressLimit::KEY)->first()?->value);
}

/** @return list<array{action: string, meta: mixed}> */
function ipAudits(): array
{
    return app(Tenancy::class)->enter(1, fn () => AuditLog::query()->orderBy('id')->get(['action', 'meta'])
        ->filter(fn (AuditLog $row): bool => str_starts_with((string) $row->action, 'settings.admin_ip.'))
        ->map(fn (AuditLog $row): array => ['action' => (string) $row->action, 'meta' => $row->meta])->values()->all());
}

/** A request from $ip (the address the app sees, as Caddy hands it over). */
function fromIp(mixed $test, string $ip): mixed
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip]);
}

// ── the doors ───────────────────────────────────────────────────────────────────────

it('lets listed IPv4, IPv6 and range addresses into the admin, and refuses every other on every door', function (): void {
    config(['admin.brand.name' => 'Nền tảng X']);
    ipLimit(['203.0.113.0/24', '2001:db8:1234:5678::/64', '198.51.100.7']);
    $owner = blockLibraryUser(1, 'owner');

    foreach (['203.0.113.24', '203.0.113.255', '198.51.100.7', '2001:db8:1234:5678::abcd', '::ffff:203.0.113.9'] as $ip) {
        fromIp($this, $ip)->actingAs($owner)->get('http://a.test/admin')->assertOk();
    }

    foreach (['198.51.100.8', '203.0.114.1', '2001:db8:1234:5679::1', '::ffff:198.51.100.8'] as $ip) {
        $page = fromIp($this, $ip)->actingAs($owner)->get('http://a.test/admin/settings/advanced')->assertForbidden()
            ->assertSee('Không vào được trang quản trị từ nơi này')
            ->assertSee('liên hệ bộ phận hỗ trợ của Nền tảng X')
            ->assertSee('data-te-page="ipblock"', false)
            ->assertDontSee('203.0.113')->assertDontSee('2001:db8')->assertDontSee('198.51.100.7');
        expect((string) $page->headers->get('Cache-Control'))->toContain('no-store');

        fromIp($this, $ip)->actingAs($owner)->withHeader('X-Livewire', '1')->post('http://a.test/livewire/update', [])->assertForbidden();
    }
});

it('closes the sign-in pages to a guest from elsewhere', function (string $path, int $open): void {
    ipLimit(['203.0.113.0/24']);

    fromIp($this, '198.51.100.8')->get('http://a.test'.$path)->assertForbidden()->assertSee('Không vào được trang quản trị từ nơi này');
    fromIp($this, '203.0.113.5')->get('http://a.test'.$path)->assertStatus($open);
})->with([
    'sign in' => ['/admin/login', 200],
    'forgotten password' => ['/admin/forgot-password', 200],
    'the language switch' => ['/admin/locale?set=en', 302],
]);

it('refuses the Livewire update a sign-in form posts, from elsewhere only', function (): void {
    ipLimit(['203.0.113.0/24']);

    fromIp($this, '198.51.100.8')->withHeader('X-Livewire', '1')->post('http://a.test/livewire/update', [])->assertForbidden();
    // past the limit, an empty update is simply not found
    fromIp($this, '203.0.113.5')->withHeader('X-Livewire', '1')->post('http://a.test/livewire/update', [])->assertNotFound();
});

it('reads the address the proxies hand over, never a header the visitor writes', function (array $headers, string $remote): void {
    ipLimit(['203.0.113.0/24']);
    $owner = blockLibraryUser(1, 'owner');

    $test = fromIp($this, $remote)->actingAs($owner);
    foreach ($headers as $name => $value) {
        $test = $test->withHeader($name, $value);
    }
    $test->get('http://a.test/admin')->assertForbidden();
    fromIp($this, $remote)->withHeaders($headers)->get('http://a.test/admin/login')->assertForbidden();
})->with([
    'X-Forwarded-For from a public address' => [['X-Forwarded-For' => '203.0.113.24'], '198.51.100.9'],
    'CF-Connecting-IP from a public address' => [['CF-Connecting-IP' => '203.0.113.24'], '198.51.100.9'],
    'X-Real-IP and Forwarded' => [['X-Real-IP' => '203.0.113.24', 'Forwarded' => 'for=203.0.113.24'], '198.51.100.9'],
    'CF-Connecting-IP through the private proxy' => [['CF-Connecting-IP' => '203.0.113.24'], '10.0.0.5'],
]);

it('leaves the storefront and other websites alone', function (): void {
    ipLimit(['203.0.113.0/24']);
    $otherOwner = blockLibraryUser(2, 'owner');

    fromIp($this, '198.51.100.8')->get('http://a.test/robots.txt')->assertOk();
    fromIp($this, '198.51.100.8')->get('http://b.test/admin/login')->assertOk();
    fromIp($this, '198.51.100.8')->actingAs($otherOwner)->get('http://b.test/admin')->assertOk();
});

it('takes effect on the next request once saved, and lets everyone in again once switched off, keeping the list', function (): void {
    $owner = blockLibraryUser(1, 'owner');
    fromIp($this, '198.51.100.8')->actingAs($owner)->get('http://a.test/admin')->assertOk();

    ipLimit(['203.0.113.0/24']);
    fromIp($this, '198.51.100.8')->actingAs($owner)->get('http://a.test/admin')->assertForbidden();

    ipLimit(['203.0.113.0/24'], on: false);
    fromIp($this, '198.51.100.8')->actingAs($owner)->get('http://a.test/admin')->assertOk();
    expect(ipStored()['entries'])->toEqual([['address' => '203.0.113.0/24', 'note' => '']]);
});

it('lets no member in when it is on with nothing listed', function (): void {
    ipLimit([]);
    $owner = blockLibraryUser(1, 'owner');

    fromIp($this, '203.0.113.5')->actingAs($owner)->get('http://a.test/admin')->assertForbidden();
});

it('refuses a platform account that is not in a support session', function (): void {
    ipLimit(['203.0.113.0/24']);
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));

    fromIp($this, '198.51.100.8')->actingAs($operator->fresh())->get('http://a.test/admin')->assertForbidden();
});

// ── support ─────────────────────────────────────────────────────────────────────────

it('lets platform support in from anywhere during a live support session only', function (): void {
    ipLimit(['203.0.113.0/24']);
    $owner = blockLibraryUser(1, 'owner');
    $staff = User::factory()->create(['name' => 'Staff support']);
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    $token = app(SupportSignIn::class)->issue($staff, Site::query()->findOrFail(1), $owner, 'Khách bị chặn khỏi trang quản trị', 15, IPL_UA);

    fromIp($this, '198.51.100.50')->withHeader('User-Agent', IPL_UA)->get("http://a.test/admin/support/enter?token={$token}")->assertStatus(303);
    fromIp($this, '198.51.100.50')->get('http://a.test/admin')->assertOk();
    fromIp($this, '198.51.100.50')->get('http://a.test/admin/settings/advanced')->assertOk()->assertSee('Bạn đang vào bằng Đăng nhập hỗ trợ');
    fromIp($this, '198.51.100.50')->withHeader('X-Livewire', '1')->post('http://a.test/livewire/update', [])->assertNotFound();
    $this->flushHeaders();

    // once its time is up the session ends, and the limit holds again
    $this->travel(16)->minutes();
    fromIp($this, '198.51.100.50')->withHeader('User-Agent', IPL_UA)->get('http://a.test/admin')->assertRedirectContains('/admin/login');
    fromIp($this, '198.51.100.50')->get('http://a.test/admin/login')->assertForbidden();
});

// ── the query budget ───────────────────────────────────────────────────────────────

it('adds no query to an admin request, limited or not', function (): void {
    $owner = blockLibraryUser(1, 'owner');
    $count = function () use ($owner): int {
        fromIp($this, '203.0.113.5')->actingAs($owner)->get('http://a.test/admin')->assertOk(); // warm
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        fromIp($this, '203.0.113.5')->actingAs($owner)->get('http://a.test/admin')->assertOk();

        return $n;
    };
    $free = $count();
    ipLimit(['203.0.113.0/24']);
    $limited = $count();

    expect($free)->toBeGreaterThan(0)->and($limited)->toBe($free);
});

// ── the screen ─────────────────────────────────────────────────────────────────────

it('starts an empty list with the address in use, saves it without purging the edge, and records it', function (): void {
    edgeCacheRecorder();
    Bus::fake([PurgeSiteEdgeCache::class]);
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(AdvancedSettings::class)
            ->assertSee('Giới hạn nơi được vào trang quản trị')
            ->assertSeeHtml('data-adv-ip-chip="off"')
            ->assertSee('Địa chỉ mạng bạn đang dùng:')
            ->assertDontSeeHtml('data-adv-ip-row')
            ->call('toggleIp')
            ->assertSet('ips.0.address', '127.0.0.1')->assertSet('ips.0.note', 'Máy bạn đang dùng')
            ->assertSee('Đã có trong danh sách')->assertSee('Có địa chỉ bạn đang dùng')
            ->assertSee('Mạng dùng IPv6 thường đổi địa chỉ')
            ->call('addIpRow')
            ->set('ips.1.address', ' 203.0.113.77/24 ')->set('ips.1.note', 'Văn phòng Đà Nẵng')
            ->call('save')->assertHasNoErrors()
            ->assertSet('ipSavedOn', true)->assertSeeHtml('data-adv-ip-chip="on"');
    });

    expect(ipStored())->toEqual(['on' => true, 'entries' => [
        ['address' => '127.0.0.1', 'note' => 'Máy bạn đang dùng'],
        ['address' => '203.0.113.0/24', 'note' => 'Văn phòng Đà Nẵng'],
    ]])->and(ipAudits())->toBe([['action' => 'settings.admin_ip.on', 'meta' => ['on' => true, 'count' => 2]]]);
    Bus::assertNotDispatched(PurgeSiteEdgeCache::class);
});

it('tells a row’s fault once it is left, and every fault on save', function (): void {
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $lw = Livewire::actingAs($owner)->test(AdvancedSettings::class)->call('toggleIp')->call('addIpRow');
        $id = $lw->get('ips.1.id');
        $lw->set('ips.1.address', '113.161.42')
            ->assertDontSee('chưa đúng dạng địa chỉ mạng')
            ->call('touchIp', $id)
            ->assertHasErrors('ip.'.$id)
            ->assertSee('«113.161.42» chưa đúng dạng địa chỉ mạng. Viết bốn nhóm số cách nhau bằng dấu chấm, mỗi nhóm từ 0 đến 255, ví dụ 203.0.113.24.')
            ->call('addIpRow')
            ->call('save')
            ->assertSee('Nhập địa chỉ mạng cho dòng 3, hoặc bấm Xóa.')
            ->set('ips.1.address', '203.0.113.0/40')->call('save')
            ->assertSee('«203.0.113.0/40» có phần sau dấu / chưa đúng.');
    });

    expect(ipStored())->toBeNull();
});

it('refuses an address that would let every address in, and says why', function (string $catchAll): void {
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(AdvancedSettings::class)
        ->call('toggleIp')->call('addIpRow')->set('ips.1.address', $catchAll)->call('save')
        ->assertSee('«'.$catchAll.'» là mọi địa chỉ trên internet, nên giới hạn sẽ không chặn ai.'));

    expect(ipStored())->toBeNull();
})->with(['0.0.0.0/0', '::/0', '::ffff:0.0.0.0/96']);

it('never saves a list that would lock out the owner saving it', function (): void {
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(AdvancedSettings::class)
            ->call('toggleIp')->set('ips.0.address', '198.51.100.0/24')
            ->assertDontSee('Chưa lưu: danh sách này sẽ chặn chính bạn.')
            ->assertSee('Thêm địa chỉ này')
            ->call('save')
            ->assertHasErrors('ip.lock')
            ->assertSee('Chưa lưu: danh sách này sẽ chặn chính bạn.')
            ->assertSee('Địa chỉ mạng bạn đang dùng (127.0.0.1) không nằm trong danh sách')
            ->assertSee('Thêm địa chỉ đang dùng')
            ->assertDontSee('Vẫn lưu')
            ->call('addMyIp')
            ->assertHasNoErrors()
            ->assertDontSee('Chưa lưu: danh sách này sẽ chặn chính bạn.')
            ->call('save')->assertHasNoErrors();
    });

    expect(array_column(ipStored()['entries'], 'address'))->toBe(['198.51.100.0/24', '127.0.0.1']);
});

it('checks the saver’s own address in each written form, IPv4, IPv6 and IPv4 written as IPv6', function (array $list, string $me, bool $covered): void {
    $addresses = array_map(fn (string $a): string => AdminAddressLimit::normalize($a)['address'] ?? '', $list);

    expect(AdminAddressLimit::covers($addresses, $me))->toBe($covered);
})->with([
    'IPv4 in its range' => [['203.0.113.0/24'], '203.0.113.9', true],
    'IPv4 outside' => [['203.0.113.0/24'], '203.0.114.9', false],
    'IPv6 in its range' => [['2001:db8:1234:5678::/64'], '2001:db8:1234:5678:aaaa::1', true],
    'IPv6 outside' => [['2001:db8:1234:5678::/64'], '2001:db8:1234:5679::1', false],
    'a mapped visitor against an IPv4 entry' => [['203.0.113.0/24'], '::ffff:203.0.113.9', true],
    'a mapped visitor outside the IPv4 entry' => [['203.0.113.0/24'], '::ffff:198.51.100.9', false],
    'an IPv4 visitor against a mapped entry' => [['::ffff:203.0.113.9'], '203.0.113.9', true],
    'an IPv4 visitor against a mapped range' => [['::ffff:203.0.113.0/120'], '203.0.113.200', true],
    'IPv4 never matches an IPv6 range' => [['2001:db8::/32'], '203.0.113.9', false],
]);

it('does not hold a support session to the lock-out check, and offers it no address of its own', function (): void {
    $owner = blockLibraryUser(1, 'owner');
    $staff = User::factory()->create(['name' => 'Staff support']);
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    $token = app(SupportSignIn::class)->issue($staff, Site::query()->findOrFail(1), $owner, 'Khách nhờ giới hạn trang quản trị', 15, IPL_UA);
    fromIp($this, '198.51.100.50')->withHeader('User-Agent', IPL_UA)->get("http://a.test/admin/support/enter?token={$token}")->assertStatus(303);

    $page = (string) fromIp($this, '198.51.100.50')->get('http://a.test/admin/settings/advanced')->assertOk()->getContent();
    expect(preg_match('/wire:snapshot="([^"]*advanced-settings[^"]*)"/', $page, $m))->toBe(1);
    $update = function (string $snapshot, array $updates, array $calls): array {
        $response = fromIp($this, '198.51.100.50')->withHeader('X-Livewire', '1')->postJson('http://a.test/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => $updates,
            'calls' => array_map(fn (string $method): array => ['method' => $method, 'params' => [], 'metadata' => []], $calls),
        ]]])->assertOk();

        return $response->json('components.0');
    };
    // switched on, the list starts empty (never the support desk's address) and the box says why
    $first = $update(html_entity_decode($m[1]), [], ['toggleIp', 'addIpRow']);
    expect($first['effects']['html'])->toContain('Bạn đang vào bằng Đăng nhập hỗ trợ')->not->toContain('data-adv-ip-add-me')
        ->and(json_decode($first['snapshot'], true)['data']['ips'][0])->toHaveCount(1);
    $saved = $update($first['snapshot'], ['ips.0.address' => '203.0.113.0/24'], ['save']);
    expect($saved['effects']['html'])->not->toContain('Chưa lưu: danh sách này sẽ chặn chính bạn.');

    expect(ipStored()['entries'])->toEqual([['address' => '203.0.113.0/24', 'note' => '']]);
    // the support session itself still gets in from anywhere
    fromIp($this, '198.51.100.50')->get('http://a.test/admin')->assertOk();
});

it('asks for an address when the list is on and empty', function (): void {
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(AdvancedSettings::class)
        ->call('toggleIp')->call('removeIpRow', 1)->call('save')
        ->assertHasErrors('ip.list')
        ->assertSee('Thêm ít nhất một địa chỉ mạng, hoặc tắt giới hạn này.'));

    expect(ipStored())->toBeNull();
});

it('holds at most fifty addresses', function (): void {
    $owner = blockLibraryUser(1, 'owner');
    $rows = array_map(fn (int $i): array => ['id' => $i, 'address' => '203.0.113.'.$i, 'note' => ''], range(1, 60));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(AdvancedSettings::class)
        ->call('toggleIp')
        ->set('ips', $rows)
        ->assertCount('ips', AdminAddressLimit::MAX_ENTRIES)
        ->call('addIpRow')
        ->assertCount('ips', AdminAddressLimit::MAX_ENTRIES)
        ->assertSee('Danh sách có tối đa 50 địa chỉ.')
        ->call('addMyIp')
        ->assertCount('ips', AdminAddressLimit::MAX_ENTRIES)
        ->set('ips.49.address', '127.0.0.1')
        ->call('save')->assertHasNoErrors());

    expect(ipStored()['entries'])->toHaveCount(AdminAddressLimit::MAX_ENTRIES);
});

it('keeps the valid addresses when switched off, and records it', function (): void {
    ipLimit(['127.0.0.1', '203.0.113.0/24']);
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(AdvancedSettings::class)
        ->assertSeeHtml('data-adv-ip-chip="on"')->assertSee('Đang giới hạn')
        ->call('addIpRow')->set('ips.2.address', 'not an address')
        ->call('toggleIp')->assertDontSeeHtml('data-adv-ip-row')
        ->call('save')->assertHasNoErrors()
        ->assertSeeHtml('data-adv-ip-chip="off"'));

    expect(ipStored())->toEqual(['on' => false, 'entries' => [['address' => '127.0.0.1', 'note' => ''], ['address' => '203.0.113.0/24', 'note' => '']]])
        ->and(ipAudits())->toBe([['action' => 'settings.admin_ip.off', 'meta' => ['on' => false, 'count' => 2]]]);
});

it('records a changed list as a change, and leaves the other parts of the page alone', function (): void {
    ipLimit(['127.0.0.1']);
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(AdvancedSettings::class)
        ->call('addIpRow')->set('ips.1.address', '2001:DB8:1234:5678:0:0:0:1/64')->call('save')->assertHasNoErrors());

    expect(ipStored()['entries'][1]['address'])->toBe('2001:db8:1234:5678::/64')
        ->and(ipAudits())->toBe([['action' => 'settings.admin_ip.changed', 'meta' => ['on' => true, 'count' => 2]]])
        ->and(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('group_name', 'storefront')->count()))->toBe(0);
});

it('shows another holder of settings.manage the list read-only', function (): void {
    ipLimit(['127.0.0.1', '203.0.113.0/24']);
    $admin = blockLibraryUser(1, 'admin');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(AdvancedSettings::class)
        ->assertSet('readOnly', true)
        ->assertSee('Chỉ Chủ website đổi được chế độ bảo trì, nơi được vào trang quản trị và mã nhúng thêm.')
        ->assertSeeHtml('data-adv-ip-row="2"')
        ->assertDontSeeHtml('data-adv-ip-remove')->assertDontSeeHtml('data-adv-ip-add-row')->assertDontSeeHtml('data-adv-ip-add-me')
        ->assertSee('Đã có trong danh sách'));
});

it('refuses every address-list change from a member who is not the owner', function (string $action, array $args): void {
    ipLimit(['127.0.0.1']);
    $admin = blockLibraryUser(1, 'admin');

    app(Tenancy::class)->enter(1, function () use ($admin, $action, $args): void {
        $lw = Livewire::actingAs($admin)->test(AdvancedSettings::class);
        $action === 'set' ? $lw->set(...$args)->assertForbidden() : $lw->call($action, ...$args)->assertForbidden();
    });

    expect(ipStored()['entries'])->toEqual([['address' => '127.0.0.1', 'note' => '']]);
})->with([
    'toggleIp' => ['toggleIp', []],
    'addIpRow' => ['addIpRow', []],
    'addMyIp' => ['addMyIp', []],
    'removeIpRow' => ['removeIpRow', [1]],
    'touchIp' => ['touchIp', [1]],
    'an address' => ['set', ['ips.0.address', '0.0.0.0/1']],
    'a note' => ['set', ['ips.0.note', 'x']],
    'the list' => ['set', ['ips', []]],
]);

it('refuses the address-list state from the browser side', function (string $property, mixed $value): void {
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(AdvancedSettings::class)->set($property, $value));
})->with([
    'the switch' => ['ipOn', true],
    'the saved switch' => ['ipSavedOn', true],
    'the tried flag' => ['ipTried', true],
    'the left rows' => ['ipTouched', [1]],
    'the row counter' => ['ipSeq', 0],
])->throws(CannotUpdateLockedPropertyException::class);

it('puts settings.manage on every address-list action', function (string $method): void {
    $attrs = (new ReflectionMethod(AdvancedSettings::class, $method))->getAttributes(Authorize::class);
    expect($attrs)->toHaveCount(1)->and($attrs[0]->getArguments()[0])->toBe('settings.manage');
})->with(['toggleIp', 'addIpRow', 'addMyIp', 'removeIpRow', 'touchIp']);
