<?php

use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\RoleLabel;

it('names every built-in role in plain words, never by its technical name', function (string $locale): void {
    app()->setLocale($locale);
    $names = [...array_keys(PermissionRegistry::platformRoles()), ...array_keys(PermissionRegistry::tenantRoleTemplates('corporate'))];

    foreach ($names as $name) {
        $label = RoleLabel::forName($name, true);
        expect($label)->not->toBe($name)->not->toContain('_');
    }
})->with(['vi', 'en', 'ja', 'zh']);

it('shows the platform roles by the names the platform role picker uses', function (): void {
    app()->setLocale('vi');

    expect(RoleLabel::forName('super_admin', true))->toBe('Quản trị nền tảng')
        ->and(RoleLabel::forName('support', true))->toBe('Nhân viên hỗ trợ')
        ->and(RoleLabel::forName('billing', true))->toBe('Nhân viên thanh toán');
});

it('keeps a custom role under the name its site gave it', function (): void {
    app()->setLocale('vi');

    expect(RoleLabel::forName('Kế toán bếp', false))->toBe('Kế toán bếp')
        ->and(RoleLabel::forName('editor', false))->toBe('editor');
});
