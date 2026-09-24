<?php

/*
 * The admin kit paints with ROLES, not raw brand colors, so each piece of ink stays readable in both
 * schemes (values + ratios: scripts/contrast-check.mjs). The brand navy is a fill under white text;
 * wherever it is ink — text, border, focus ring — the kit uses `accent`, which the dark scheme
 * lightens (navy on the dark surface reads 1.8:1).
 */

/**
 * Utilities (any variant prefix) that paint the brand navy as ink.
 *
 * @return list<string>
 */
function inkOfBrandNavy(string $html): array
{
    preg_match_all('/(?<![\w-])(?:[\w-]+:)*(?:text|border|ring|outline)-admin-primary(?:\/\d+)?(?![\w-])/', $html, $matches);

    return $matches[0];
}

it('never paints the brand navy as ink in a kit component', function (string $markup): void {
    $html = (string) $this->blade($markup);

    expect(inkOfBrandNavy($html))->toBe([]);
})->with([
    'solid button' => '<x-admin::button>Lưu</x-admin::button>',
    'soft button' => '<x-admin::button variant="soft">Lưu</x-admin::button>',
    'outline button' => '<x-admin::button variant="outline">Lưu</x-admin::button>',
    'ghost button' => '<x-admin::button variant="ghost">Lưu</x-admin::button>',
    'tabs' => '<x-admin::tabs :tabs="[\'a\' => \'Một\', \'b\' => \'Hai\']" />',
    'input' => '<x-admin::input name="email" label="Email" />',
    'soft badge' => '<x-admin::badge soft>Mới</x-admin::badge>',
    'alert' => '<x-admin::alert color="primary">Đã lưu.</x-admin::alert>',
]);

it('draws the brand navy ink through the accent role', function (): void {
    expect((string) $this->blade('<x-admin::button variant="outline">Lưu</x-admin::button>'))
        ->toContain('border-accent')
        ->toContain('text-accent')
        ->and((string) $this->blade('<x-admin::tabs :tabs="[\'a\' => \'Một\']" />'))->toContain('border-accent text-accent')
        ->and((string) $this->blade('<x-admin::input name="email" />'))->toContain('focus:border-accent');
});

it('keeps the brand navy as the fill under white text', function (): void {
    expect((string) $this->blade('<x-admin::button>Lưu</x-admin::button>'))->toContain('bg-admin-primary text-white');
});

it('paints secondary text a reader needs with the muted role, never the decorative faint one', function (string $markup): void {
    expect((string) $this->blade($markup))
        ->toContain('text-body-muted')
        ->not->toContain('text-body-faint');
})->with([
    'breadcrumb + subtitle' => '<x-admin::page-header title="Người dùng" subtitle="Quản lý thành viên."><x-slot:breadcrumbs>Bảng điều khiển / Người dùng</x-slot:breadcrumbs></x-admin::page-header>',
    'unselected tab' => '<x-admin::tabs :tabs="[\'a\' => \'Một\', \'b\' => \'Hai\']" />',
]);
