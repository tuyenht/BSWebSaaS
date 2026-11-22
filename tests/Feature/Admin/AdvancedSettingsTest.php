<?php

use App\Modules\Admin\Livewire\AdvancedSettings;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Storefront\EdgeCache\PurgeSiteEdgeCache;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Authorize;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Cài đặt › «Bảo mật & Nâng cao», the maintenance mode (site_settings storefront/maintenance): one
 * switch, the owner's words per website language, an optional reopen time in the website's time
 * zone (stored in UTC). Every holder of settings.manage may open it; only the website's owner (or a
 * platform operator) may change it, checked on every action. Saving purges the website's pages from
 * the edge, records who saved it, and touches no other website.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed>|null */
function advStored(int $siteId = 1): ?array
{
    return app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()
        ->where('group_name', 'storefront')->where('key', 'maintenance')->first()?->value);
}

it('lets the owner turn maintenance on with words and a reopen time, stored in UTC', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 03:00:00', 'UTC'));
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(AdvancedSettings::class)
            ->assertSee('Đang tắt')
            ->assertSee('Khi bật, khách chỉ thấy trang báo bảo trì kèm lời nhắn của bạn.')
            ->call('toggleMaintenance')
            ->set('messages.vi', '  Chúng tôi đang cập nhật chuyên mục sản phẩm.  ')
            ->set('reopenDate', '2026-11-22')
            ->set('reopenTime', '15:30')
            ->assertHasNoErrors()
            ->assertSee('Khách sẽ thấy: «Dự kiến xong lúc 22/11/2026 15:30.»')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Đã lưu Bảo mật &amp; Nâng cao.', false)
            ->assertSee('Website đang bảo trì')
            ->assertSee('Khách đang chỉ thấy trang báo bảo trì kèm lời nhắn dưới đây.')
            ->assertSee('Cập nhật lần cuối')
            ->assertSet('reopenTime', '15:30');
    });

    // 15:30 in Asia/Ho_Chi_Minh is 08:30 UTC
    expect(advStored())->toBe(['on' => true, 'messages' => ['vi' => 'Chúng tôi đang cập nhật chuyên mục sản phẩm.'], 'reopens_at' => '2026-11-22T08:30:00Z'])
        ->and(advStored(2))->toBeNull()
        ->and(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'maintenance')->value('updated_by')))->toBe($owner->id);
});

it('purges the website’s pages from the edge when maintenance is switched, and records it', function (): void {
    edgeCacheRecorder();
    Bus::fake([PurgeSiteEdgeCache::class]);
    $owner = blockLibraryUser(1, 'owner');
    $auditBefore = (int) app(Tenancy::class)->enter(1, fn () => AuditLog::query()->max('id')); // the audit log outlives a test

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(AdvancedSettings::class)->call('toggleMaintenance')->call('save')->assertHasNoErrors());
    expect(Bus::dispatched(PurgeSiteEdgeCache::class)->map->siteId->values()->all())->toBe([1]);

    // the waiting purge starts (its one-per-site lock goes, as on the worker): the next switch queues another
    (new UniqueLock(Cache::driver()))->release(new PurgeSiteEdgeCache(1));
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(AdvancedSettings::class)->call('toggleMaintenance')->call('save')->assertHasNoErrors());
    expect(Bus::dispatched(PurgeSiteEdgeCache::class)->map->siteId->values()->all())->toBe([1, 1])
        ->and(advStored()['on'])->toBeFalse()
        ->and(app(Tenancy::class)->enter(1, fn () => AuditLog::query()->where('id', '>', $auditBefore)->orderBy('id')->pluck('action')->filter(fn (string $a): bool => str_starts_with($a, 'settings.maintenance.'))->values()->all()))
        ->toBe(['settings.maintenance.on', 'settings.maintenance.off']);
});

it('keeps each language’s words, and leaves out an empty one', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(AdvancedSettings::class)
            ->assertSee('Lời nhắn — Tiếng Việt')->assertSee('Lời nhắn — English')
            ->assertSee('Khách xem bản English thấy lời này. Để trống thì dùng câu chung bằng English.')
            ->set('messages.vi', '')
            ->set('messages.en', 'Back soon.')
            ->call('save')->assertHasNoErrors();
    });

    expect(advStored()['messages'])->toBe(['en' => 'Back soon.']);
});

it('asks for both a day and an hour, and refuses a time already past', function (array $sets, string $message): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 03:00:00', 'UTC'));
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, function () use ($owner, $sets, $message): void {
        $lw = Livewire::actingAs($owner)->test(AdvancedSettings::class);
        foreach ($sets as [$property, $value]) {
            $lw->set($property, $value);
        }
        $lw->assertHasErrors('reopen')->assertSee($message)
            ->call('save')->assertHasErrors('reopen')->assertSee('Chưa lưu được: hãy sửa các ô đang báo lỗi.');
    });

    expect(advStored())->toBeNull();
})->with([
    'an hour with no day' => [[['reopenTime', '15:30']], 'Chọn cả ngày, hoặc xóa giờ nếu không muốn ghi thời gian.'],
    'a day, then its hour cleared' => [[['reopenDate', '2026-11-23'], ['reopenTime', '15:30'], ['reopenTime', '']], 'Chọn cả giờ, hoặc xóa ngày nếu không muốn ghi thời gian.'],
    'a time already past' => [[['reopenDate', '2026-11-22'], ['reopenTime', '09:00']], 'Thời gian này đã qua. Chọn một lúc sau bây giờ, hoặc để trống.'],
]);

it('tells a day without its hour only once the hour is left or on save', function (): void {
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(AdvancedSettings::class)
            ->set('reopenDate', '2030-01-01')
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasErrors('reopen')
            ->assertSee('Chọn cả giờ, hoặc xóa ngày nếu không muốn ghi thời gian.');
    });
});

it('saves a reopen time that has passed since it was saved, unchanged', function (): void {
    $owner = blockLibraryUser(1, 'owner');
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'maintenance', 'value' => ['on' => true, 'messages' => [], 'reopens_at' => '2026-01-01T01:00:00Z']]));

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(AdvancedSettings::class)
            ->assertSet('reopenDate', '2026-01-01')->assertSet('reopenTime', '08:00')
            ->call('toggleMaintenance')
            ->call('save')
            ->assertHasNoErrors();
    });

    expect(advStored())->toBe(['on' => false, 'messages' => [], 'reopens_at' => '2026-01-01T01:00:00Z']);
});

it('refuses words longer than the limit', function (): void {
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(AdvancedSettings::class)
            ->set('messages.vi', str_repeat('a', 501))
            ->assertHasErrors('messages.vi')
            ->assertSee('Lời nhắn dài quá 500 ký tự. Hãy rút gọn lại.')
            ->call('save')->assertHasErrors('messages.vi');
    });

    expect(advStored())->toBeNull();
});

it('shows another holder of settings.manage the page «Chỉ xem», with no save bar', function (): void {
    $admin = blockLibraryUser(1, 'admin');

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(AdvancedSettings::class)
            ->assertSet('readOnly', true)
            ->assertSee('Chỉ xem')
            ->assertSee('Chỉ Chủ website đổi được mục này.')
            ->assertSee('nên chỉ xem được. Chỉ Chủ website đổi được chế độ bảo trì.')
            ->assertSeeHtml('disabled aria-disabled="true"')
            ->assertDontSeeHtml('data-set-savebar')
            ->assertDontSee('Ví dụ: Chúng tôi đang cập nhật');
    });
});

it('refuses every change from a member who is not the owner', function (string $action, array $args): void {
    $admin = blockLibraryUser(1, 'admin');

    app(Tenancy::class)->enter(1, function () use ($admin, $action, $args): void {
        $lw = Livewire::actingAs($admin)->test(AdvancedSettings::class);
        if ($action === 'set') {
            $lw->set(...$args)->assertForbidden();
        } else {
            $lw->call($action, ...$args)->assertForbidden();
        }
    });

    expect(advStored())->toBeNull();
})->with([
    'toggleMaintenance' => ['toggleMaintenance', []],
    'save' => ['save', []],
    'resetChanges' => ['resetChanges', []],
    'a message' => ['set', ['messages.vi', 'x']],
    'the day' => ['set', ['reopenDate', '2030-01-01']],
    'the hour' => ['set', ['reopenTime', '10:00']],
]);

it('refuses the switch from the browser side too', function (string $property, mixed $value): void {
    $owner = blockLibraryUser(1, 'owner');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(AdvancedSettings::class)->set($property, $value));
})->with([
    'the switch' => ['maintenanceOn', true],
    'read-only' => ['readOnly', false],
    'the saved switch' => ['savedOn', true],
    'the saved time' => ['savedReopen', '2020-01-01 00:00'],
])->throws(CannotUpdateLockedPropertyException::class);

it('lets a platform operator change it', function (): void {
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($operator->fresh())->test(AdvancedSettings::class)
        ->assertSet('readOnly', false)->call('toggleMaintenance')->call('save')->assertHasNoErrors());

    expect(advStored()['on'])->toBeTrue();
});

it('refuses the page to a member without settings.manage, and the owner of another website', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $otherOwner = blockLibraryUser(2, 'owner');

    $this->actingAs($editor)->get('http://a.test/admin/settings/advanced')->assertForbidden();
    $this->actingAs($editor)->get('http://a.test/admin/settings/advanced/preview')->assertForbidden();
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($editor)->test(AdvancedSettings::class)->assertForbidden());
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($otherOwner)->test(AdvancedSettings::class)->assertForbidden());
});

it('opens over HTTP for the owner and the admin, and lists it in the menu for the owner only', function (): void {
    $owner = blockLibraryUser(1, 'owner');
    $admin = blockLibraryUser(1, 'admin');

    $this->actingAs($owner)->get('http://a.test/admin/settings/advanced')->assertOk()->assertSee('Chế độ bảo trì')->assertSee('Xem trang khách sẽ thấy');
    $this->actingAs($owner)->get('http://a.test/admin/settings/integrations')->assertOk()->assertSee('/admin/settings/advanced', false);
    $this->actingAs($admin)->get('http://a.test/admin/settings/advanced')->assertOk()->assertSee('Chỉ xem');
    $this->actingAs($admin)->get('http://a.test/admin/settings/integrations')->assertOk()->assertDontSee('/admin/settings/advanced', false);
});

it('shows the owner what visitors will see, in the language asked for, never cached', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteProfile::query()->create(['brand_name' => 'Ánh Kim Hà']));
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'maintenance', 'value' => ['on' => false, 'messages' => ['vi' => 'Đang nâng cấp kho hàng.'], 'reopens_at' => null]]));
    $owner = blockLibraryUser(1, 'owner');

    $response = $this->actingAs($owner)->get('http://a.test/admin/settings/advanced/preview')->assertOk();
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and((string) $response->getContent())->toContain('Website đang bảo trì')->toContain('Đang nâng cấp kho hàng.');

    $this->actingAs($owner)->get('http://a.test/admin/settings/advanced/preview?locale=xx')->assertNotFound();
});

it('puts settings.manage on every action, not only in boot()', function (string $method): void {
    $attrs = (new ReflectionMethod(AdvancedSettings::class, $method))->getAttributes(Authorize::class);
    expect($attrs)->toHaveCount(1)->and($attrs[0]->getArguments()[0])->toBe('settings.manage');
})->with(['toggleMaintenance', 'save', 'resetChanges', 'dismissToast']);
