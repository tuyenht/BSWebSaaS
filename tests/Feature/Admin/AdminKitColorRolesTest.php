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
    'solid button' => '<x-bs-admin::button>Lưu</x-bs-admin::button>',
    'soft button' => '<x-bs-admin::button variant="soft">Lưu</x-bs-admin::button>',
    'outline button' => '<x-bs-admin::button variant="outline">Lưu</x-bs-admin::button>',
    'ghost button' => '<x-bs-admin::button variant="ghost">Lưu</x-bs-admin::button>',
    'tabs' => '<x-bs-admin::tabs :tabs="[\'a\' => \'Một\', \'b\' => \'Hai\']" />',
    'input' => '<x-bs-admin::input name="email" label="Email" />',
    'soft badge' => '<x-bs-admin::badge soft>Mới</x-bs-admin::badge>',
    'alert' => '<x-bs-admin::alert color="primary">Đã lưu.</x-bs-admin::alert>',
]);

it('draws the brand navy ink through the accent role', function (): void {
    expect((string) $this->blade('<x-bs-admin::button variant="outline">Lưu</x-bs-admin::button>'))
        ->toContain('border-accent')
        ->toContain('text-accent')
        ->and((string) $this->blade('<x-bs-admin::tabs :tabs="[\'a\' => \'Một\']" />'))->toContain('border-accent text-accent')
        ->and((string) $this->blade('<x-bs-admin::input name="email" />'))->toContain('focus:border-accent');
});

it('keeps the brand navy as the fill under white text', function (): void {
    expect((string) $this->blade('<x-bs-admin::button>Lưu</x-bs-admin::button>'))->toContain('bg-admin-primary text-white');
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
        $cases["soft {$hue} button"] = "<x-bs-admin::button variant=\"soft\" color=\"{$hue}\">Lưu</x-bs-admin::button>";
        $cases["soft {$hue} badge"] = "<x-bs-admin::badge soft color=\"{$hue}\">Mới</x-bs-admin::badge>";
        $cases["{$hue} alert"] = "<x-bs-admin::alert color=\"{$hue}\" dismissible>Đã lưu.</x-bs-admin::alert>";
    }

    return $cases + [
        'outline success button' => '<x-bs-admin::button variant="outline" color="success">Lưu</x-bs-admin::button>',
        'outline danger button' => '<x-bs-admin::button variant="outline" color="danger">Xoá</x-bs-admin::button>',
        'ghost danger button' => '<x-bs-admin::button variant="ghost" color="danger">Xoá</x-bs-admin::button>',
    ];
});

it('puts white text only on a solid fill that carries it', function (string $hue): void {
    expect((string) $this->blade("<x-bs-admin::button color=\"{$hue}\">Lưu</x-bs-admin::button>"))->toContain("bg-admin-{$hue}-solid text-white")
        ->and((string) $this->blade("<x-bs-admin::badge color=\"{$hue}\">Mới</x-bs-admin::badge>"))->toContain("bg-admin-{$hue}-solid text-white");
})->with(['success', 'info', 'danger']);

it('gives the warning fill dark text', function (): void {
    foreach (['<x-bs-admin::button color="warning">Gia hạn</x-bs-admin::button>', '<x-bs-admin::badge color="warning">Sắp hết hạn</x-bs-admin::badge>'] as $markup) {
        expect((string) $this->blade($markup))
            ->toContain('bg-admin-warning text-admin-dark')
            ->not->toContain('text-white');
    }
});

it('edges a form field in the input-border role, not the decorative rule color', function (): void {
    expect((string) $this->blade('<x-bs-admin::input name="email" label="Email" />'))
        ->toContain('border-border-input')
        ->not->toContain('border-border-strong');
});

it('draws the alert dismiss icon at full strength', function (): void {
    expect((string) $this->blade('<x-bs-admin::alert color="danger" dismissible>Lỗi.</x-bs-admin::alert>'))->not->toContain('opacity-70');
});

/*
 * Keyboard focus is one shared ring (app.css :focus-visible, tokens in admin-chrome.css). A kit
 * component that switched the outline off or drew its own faint ring (the input's was 1.4:1) would
 * hide where the keyboard is.
 */
it('leaves keyboard focus to the shared focus ring', function (string $markup): void {
    expect((string) $this->blade($markup))
        ->not->toContain('outline-none')
        ->not->toMatch('/(?<![\w-])focus(?:-visible)?:ring/');
})->with([
    'solid button' => '<x-bs-admin::button>Lưu</x-bs-admin::button>',
    'soft success button' => '<x-bs-admin::button variant="soft" color="success">Lưu</x-bs-admin::button>',
    'outline danger button' => '<x-bs-admin::button variant="outline" color="danger">Xoá</x-bs-admin::button>',
    'light button' => '<x-bs-admin::button color="light">Huỷ</x-bs-admin::button>',
    'tabs' => '<x-bs-admin::tabs :tabs="[\'a\' => \'Một\', \'b\' => \'Hai\']" />',
    'input' => '<x-bs-admin::input name="email" label="Email" />',
    'dismissible alert' => '<x-bs-admin::alert color="warning" dismissible>Sắp hết hạn.</x-bs-admin::alert>',
    'modal' => '<x-bs-admin::modal title="Xác nhận xoá"><p>Chắc chưa?</p></x-bs-admin::modal>',
]);

it('draws the modal close icon in the muted role, not the placeholder gray', function (): void {
    preg_match('/<button type="button" @click="hide\(\)"[^>]*class="([^"]*)"/', (string) $this->blade('<x-bs-admin::modal title="Xác nhận xoá"><p>Chắc chưa?</p></x-bs-admin::modal>'), $close);

    expect($close[1] ?? '')->toContain('text-body-muted')->not->toContain('text-placeholder');
});

it('paints secondary text a reader needs with the muted role, never the decorative faint one', function (string $markup): void {
    expect((string) $this->blade($markup))
        ->toContain('text-body-muted')
        ->not->toContain('text-body-faint');
})->with([
    'breadcrumb + subtitle' => '<x-bs-admin::page-header title="Người dùng" subtitle="Quản lý thành viên."><x-slot:breadcrumbs>Bảng điều khiển / Người dùng</x-slot:breadcrumbs></x-bs-admin::page-header>',
    'unselected tab' => '<x-bs-admin::tabs :tabs="[\'a\' => \'Một\', \'b\' => \'Hai\']" />',
]);
