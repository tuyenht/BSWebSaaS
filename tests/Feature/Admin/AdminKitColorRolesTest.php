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

/*
 * Semantic colors: the raw hue (#0ab39c, #299cdb, #f7b84b, #f06548) carries white text at only
 * 1.8–3.2:1 and reads 1.6–2.9:1 as text on white. The kit puts white text on the `*-solid` fill,
 * draws the hue as ink with `*-text`, and gives the warning fill dark text.
 */
it('never paints a raw semantic hue as ink', function (string $markup): void {
    preg_match_all('/(?<![\w-])(?:[\w-]+:)*(?:text|border)-admin-(?:success|info|warning|danger)(?![\w-])/', (string) $this->blade($markup), $raw);

    expect($raw[0])->toBe([]);
})->with(function (): array {
    $cases = [];
    foreach (['success', 'info', 'warning', 'danger'] as $hue) {
        $cases["soft {$hue} button"] = "<x-admin::button variant=\"soft\" color=\"{$hue}\">Lưu</x-admin::button>";
        $cases["soft {$hue} badge"] = "<x-admin::badge soft color=\"{$hue}\">Mới</x-admin::badge>";
        $cases["{$hue} alert"] = "<x-admin::alert color=\"{$hue}\" dismissible>Đã lưu.</x-admin::alert>";
    }

    return $cases + [
        'outline success button' => '<x-admin::button variant="outline" color="success">Lưu</x-admin::button>',
        'outline danger button' => '<x-admin::button variant="outline" color="danger">Xoá</x-admin::button>',
        'ghost danger button' => '<x-admin::button variant="ghost" color="danger">Xoá</x-admin::button>',
    ];
});

it('puts white text only on a solid fill that carries it', function (string $hue): void {
    expect((string) $this->blade("<x-admin::button color=\"{$hue}\">Lưu</x-admin::button>"))->toContain("bg-admin-{$hue}-solid text-white")
        ->and((string) $this->blade("<x-admin::badge color=\"{$hue}\">Mới</x-admin::badge>"))->toContain("bg-admin-{$hue}-solid text-white");
})->with(['success', 'info', 'danger']);

it('gives the warning fill dark text', function (): void {
    foreach (['<x-admin::button color="warning">Gia hạn</x-admin::button>', '<x-admin::badge color="warning">Sắp hết hạn</x-admin::badge>'] as $markup) {
        expect((string) $this->blade($markup))
            ->toContain('bg-admin-warning text-admin-dark')
            ->not->toContain('text-white');
    }
});

it('edges a form field in the input-border role, not the decorative rule color', function (): void {
    expect((string) $this->blade('<x-admin::input name="email" label="Email" />'))
        ->toContain('border-border-input')
        ->not->toContain('border-border-strong');
});

it('draws the alert dismiss icon at full strength', function (): void {
    expect((string) $this->blade('<x-admin::alert color="danger" dismissible>Lỗi.</x-admin::alert>'))->not->toContain('opacity-70');
});

it('paints secondary text a reader needs with the muted role, never the decorative faint one', function (string $markup): void {
    expect((string) $this->blade($markup))
        ->toContain('text-body-muted')
        ->not->toContain('text-body-faint');
})->with([
    'breadcrumb + subtitle' => '<x-admin::page-header title="Người dùng" subtitle="Quản lý thành viên."><x-slot:breadcrumbs>Bảng điều khiển / Người dùng</x-slot:breadcrumbs></x-admin::page-header>',
    'unselected tab' => '<x-admin::tabs :tabs="[\'a\' => \'Một\', \'b\' => \'Hai\']" />',
]);
