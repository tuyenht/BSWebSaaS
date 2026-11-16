<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * The settings shell's unsaved-changes dialog: a section that keeps a draft on the server joins by
 * passing its change count (`:unsaved`), and only then does the shell render the dialog and its
 * leave guard. A section that passes nothing (one with nothing to save) leaves freely.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

afterEach(function (): void {
    Livewire::flushState();   // a full-page GET leaves Livewire's asset-injection flag set for the next file's pages
});

function udAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

it('renders the dialog on a section that passes its change count, named after that section', function (string $path, string $label): void {
    $html = $this->actingAs(udAdmin())->get('http://a.test'.$path)->assertOk()
        ->assertSee('data-bs-lazy="bsUnsaved"', false)
        ->assertSee('data-unsaved-count="0"', false)
        ->assertSee('role="dialog"', false)
        ->assertSee('Bạn có thay đổi chưa lưu')
        ->assertSee('Lưu và sang mục khác')
        ->assertSee('Lưu và rời đi')
        ->assertSee('Ở lại')
        ->assertSee('Bỏ thay đổi và sang mục khác')
        ->assertSee('Bỏ thay đổi và rời đi')
        ->assertSee('Mục “'.$label.'” có thay đổi chưa lưu.')
        ->getContent();

    // One dialog per page, its methods those of the page.
    expect(substr_count((string) $html, 'data-bs-lazy="bsUnsaved"'))->toBe(1);
})->with([
    'general' => ['/admin/settings/general', 'Thông tin chung'],
    'seo' => ['/admin/settings/seo', 'Google & mạng xã hội'],
    'website appearance' => ['/admin/settings/appearance', 'Giao diện website'],
    'floating contact' => ['/admin/settings/floating-contact', 'Nút liên hệ nổi'],
    'integrations' => ['/admin/settings/integrations', 'Tích hợp & Theo dõi'],
    'admin panel appearance' => ['/admin/settings/admin-ui', 'Giao diện trang quản trị'],
    'catalog' => ['/admin/settings/catalog', 'Sản phẩm'],
    'email' => ['/admin/settings/email', 'Email'],
    'quick sign-in' => ['/admin/settings/social', 'Đăng nhập nhanh'],
]);

it('leaves a section that passes no change count without the dialog', function (string $path): void {
    $this->actingAs(udAdmin())->get('http://a.test'.$path)->assertOk()
        ->assertDontSee('bsUnsaved', false)
        ->assertDontSee('data-unsaved-count', false)
        ->assertDontSee('Bạn có thay đổi chưa lưu');
})->with([
    'account' => ['/admin/account'],
]);
