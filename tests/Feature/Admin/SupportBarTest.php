<?php

use App\Modules\Admin\Support\SupportSignIn;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * The bar on top of a website's admin while platform staff are signed in as one of its members:
 * it names the member, the customer and the member's role, counts down to the session's end and
 * offers "Quay lại quản trị" — and it is never there outside a support session.
 */

const BAR_UA = 'Mozilla/5.0 (support bar)';

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications, users, site_settings, platform_settings, audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Notification::fake();
    Mail::fake();
});

function barMember(int $site, string $role, string $name): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$role];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];
    $user = User::factory()->create(['name' => $name]);

    app(Tenancy::class)->enter($site, function () use ($role, $tpl, $perms, $user): void {
        $r = Role::findOrCreate($role, PermissionRegistry::GUARD);
        $r->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $r->syncPermissions($perms);
        $user->assignRole($r);
    });
    app(TeamContext::class)->forget();

    return $user;
}

function barStaff(): User
{
    $user = User::factory()->create(['name' => 'Lê Hoàng Phúc']);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();

    return $user;
}

/** Enter site A (host a.test) as $member through a real support link. */
function barEnter(mixed $test, User $member, int $minutes = 30): void
{
    $token = app(SupportSignIn::class)->issue(barStaff(), Site::query()->findOrFail(1), $member, 'Khách nhờ sửa trình đơn', $minutes, BAR_UA);
    $test->withHeader('User-Agent', BAR_UA)->get("http://a.test/admin/support/enter?token={$token}")->assertStatus(303);
}

it('shows the member, the customer, the role, the time left and the way back during a support session', function (): void {
    $this->freezeSecond();
    $editor = barMember(1, 'editor', 'Nguyễn Ngọc Anh');
    barEnter($this, $editor);

    $html = $this->withHeader('User-Agent', BAR_UA)->get('http://a.test/admin')->assertOk()->getContent();

    expect($html)->toContain('data-support-bar="1"')
        ->and($html)->toContain(e(__('admin.support.bar_as', ['member' => 'Nguyễn Ngọc Anh', 'customer' => 'T1', 'role' => __('rbac.role.editor')])))
        ->and($html)->toContain(e(__('admin.support.bar_left', ['clock' => '30:00'])))
        ->and($html)->toContain('action="'.route('admin.support.leave').'"')
        ->and($html)->toContain(e(__('admin.support.bar_back')));
});

it('never shows the bar to a member signed in on their own', function (): void {
    $editor = barMember(1, 'editor', 'Nguyễn Ngọc Anh');

    $html = $this->actingAs($editor)->get('http://a.test/admin')->assertOk()->getContent();

    expect($html)->not->toContain('data-support-bar="1"');
});

it('drops the bar with the session once its time is up', function (): void {
    $editor = barMember(1, 'editor', 'Nguyễn Ngọc Anh');
    barEnter($this, $editor, 15);

    $this->travel(16)->minutes();

    $this->withHeader('User-Agent', BAR_UA)->get('http://a.test/admin')->assertRedirect(route('admin.login'));
    $html = $this->withHeader('User-Agent', BAR_UA)->get('http://a.test/admin/login')->getContent();

    expect($html)->not->toContain('data-support-bar="1"');
});

it('names the session of site A only, never on site B', function (): void {
    $editor = barMember(1, 'editor', 'Nguyễn Ngọc Anh');
    barMember(2, 'editor', 'Người của B');
    barEnter($this, $editor);

    $html = $this->withHeader('User-Agent', BAR_UA)->get('http://b.test/admin')->getContent();

    expect($html)->not->toContain('data-support-bar="1"')
        ->and($html)->not->toContain('Nguyễn Ngọc Anh');
});
