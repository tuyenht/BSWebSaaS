<?php

/*
 * The record-status pill shared by every list row: the screen chooses only the status, and the
 * pill owns its tone, glyph, height and padding, so no screen can make it look like an action
 * button. As the status-menu trigger it is a real button with a caret and the shared touch area.
 */

it('draws each known status in its own tone and glyph, with no visible border, 28 high', function (string $status, string $tone, string $glyph): void {
    $html = (string) $this->blade('<x-bs-admin::status-pill :status="$s">Nhãn</x-bs-admin::status-pill>', ['s' => $status]);

    expect($html)->toMatch('/^\s*<span data-s="'.$status.'"/')
        ->and($html)->toContain($tone)
        ->and($html)->toContain('min-h-7')
        ->and($html)->toContain('rounded-full')
        ->and($html)->toContain('text-small')
        ->and($html)->toContain('border border-transparent')
        ->and(substr_count($html, '<svg'))->toBe(1)
        ->and($html)->toContain('<span>Nhãn</span>');

    $icon = (string) $this->blade('<x-bs-admin::icon :name="$n" />', ['n' => $glyph]);
    preg_match('/ d="([^"]+)"/', $icon, $path);
    expect($html)->toContain($path[1]);
})->with([
    ['published', '[--c:var(--color-success-text)]', 'ri-global'],
    ['draft', '[--c:var(--color-text)]', 'ri-draft'],
    ['scheduled', '[--c:color-mix(in_oklab,var(--color-secondary-text)_80%,var(--color-text-strong))]', 'ri-calendar-schedule'],
    ['pending', '[--c:var(--color-warning-text)]', 'ri-inbox-unarchive'],
    ['private', '[--c:var(--color-primary-text)]', 'ri-lock-2'],
    ['archived', '[--c:var(--color-text-muted)]', 'ri-archive'],
]);

it('reads an unknown status as plain text with no glyph', function (): void {
    $html = (string) $this->blade('<x-bs-admin::status-pill status="whatever">Khác</x-bs-admin::status-pill>');

    expect($html)->toContain('data-s="whatever"')
        ->and($html)->toContain('[--c:var(--color-text)]')
        ->and($html)->not->toContain('<svg');
});

it('becomes a menu trigger button with a caret, a darker open tint and the shared touch area', function (): void {
    $html = (string) $this->blade('<x-bs-admin::status-pill button status="draft" x-on:click="open = ! open" aria-haspopup="true">Bản nháp</x-bs-admin::status-pill>');

    expect($html)->toMatch('/^\s*<button type="button" data-s="draft"/')
        ->and($html)->toMatch('/class="[^"]*\bhit-area\b/')
        ->and($html)->toContain('aria-expanded:bg-[color-mix(in_srgb,var(--c)_18%,var(--color-surface))]')
        ->and($html)->toContain('x-on:click="open = ! open"')
        ->and($html)->toContain('aria-haspopup="true"')
        ->and(substr_count($html, '<svg'))->toBe(2);
});

it('is the only status look on the post, page and product lists', function (string $view): void {
    $source = file_get_contents(base_path('app/Modules/Admin/resources/views/livewire/'.$view));

    expect($source)->toContain('<x-bs-admin::status-pill')
        ->and($source)->not->toContain('<x-bs-admin::badge');
})->with(['entries/index.blade.php', 'catalog/index.blade.php']);
