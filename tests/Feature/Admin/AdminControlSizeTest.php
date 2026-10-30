<?php

/*
 * Every button and field takes its height from the control-size tokens, declared once in app.css
 * (docs/notes/design-tokens.md §2.5) under the same names the design canvas uses. A main control is
 * 40px at the default text size and 44px from the next size up; a row button is 36 / 40 / 44px; the
 * touch area never drops below 44px.
 */

/**
 * Pixel height of a control-size token at a text boost (rem), read from its app.css declaration.
 */
function controlTokenPx(string $token, float $boostRem): float
{
    $css = (string) file_get_contents(resource_path('css/app.css'));
    $pattern = '/--'.preg_quote($token, '/').':\s*calc\(([\d.]+)rem \+ min\(([\d.]+)rem, var\(--user-text-boost\) \* (\d+)\)\);/';
    expect(preg_match($pattern, $css, $m))->toBe(1, "--{$token} is not declared as base + min(cap, boost × k)");

    return ((float) $m[1] + min((float) $m[2], $boostRem * (int) $m[3])) * 16;
}

it('sizes a main control 40 at the default text size and 44 from the next size up', function (): void {
    $heights = array_map(fn (float $boost): float => controlTokenPx('spacing-control', $boost), [0, 0.125, 0.25, 0.375]);

    expect($heights)->toBe([40.0, 44.0, 44.0, 44.0]);
});

it('sizes a row button 36, 40, then 44 as the text grows', function (): void {
    $heights = array_map(fn (float $boost): float => controlTokenPx('spacing-control-row', $boost), [0, 0.125, 0.25, 0.375]);

    expect($heights)->toBe([36.0, 40.0, 44.0, 44.0]);
});

/**
 * Whole class names in a rendered fragment.
 *
 * @return list<string>
 */
function classNames(string $html): array
{
    preg_match_all('/class="([^"]*)"/', $html, $m);

    return array_values(array_filter(preg_split('/\s+/', implode(' ', $m[1])) ?: []));
}

it('keeps a 44px touch area through a transparent extension', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('--spacing-hit: 2.75rem;')
        ->and($css)->toMatch('/@utility hit-area \{\s*position: relative;\s*&::after \{[^}]*inset: min\(0px, calc\(\(100% - var\(--spacing-hit\)\) \/ 2\)\);/');
});

it('leaves the size roles the kit uses free of a colour token of the same name', function (string $role): void {
    // Tailwind gives `text-<role>` to a colour token when both exist: the label then takes that
    // colour and loses its size. (`heading` still has its old colour alias; nothing sizes by it yet.)
    expect((string) file_get_contents(resource_path('css/app.css')))->not->toMatch("/--color-{$role}:/");
})->with(['caption', 'small', 'body', 'lead', 'title']);

it('sizes the kit button by the tokens, never by padding alone', function (string $markup, string $height, string $text): void {
    $classes = classNames((string) $this->blade($markup));

    expect($classes)->toContain($height, 'hit-area', $text)
        ->not->toContain('py-2', 'py-2.5', 'whitespace-nowrap', 'text-sm', 'text-xs');
})->with([
    'main' => ['<x-bs-admin::button>Lưu</x-bs-admin::button>', 'min-h-control', 'text-body'],
    'row' => ['<x-bs-admin::button size="sm">Sửa</x-bs-admin::button>', 'min-h-control-row', 'text-caption'],
    'link' => ['<x-bs-admin::button href="/admin">Mở</x-bs-admin::button>', 'min-h-control', 'text-body'],
    'icon' => ['<x-bs-admin::button icon aria-label="Tìm">⌕</x-bs-admin::button>', 'size-control', 'text-body'],
    'row icon' => ['<x-bs-admin::button icon size="sm" aria-label="Xoá">×</x-bs-admin::button>', 'size-control-row', 'text-caption'],
]);

it('sizes the kit input by the main control token, 44 on touch', function (): void {
    expect(classNames((string) $this->blade('<x-bs-admin::input name="email" label="Email" />')))
        ->toContain('min-h-control', 'pointer-coarse:min-h-hit', 'text-body', 'aria-invalid:border-danger-text');
});

it('marks a field the server rejected, and only that field', function (): void {
    $this->withViewErrors(['email' => 'Email chưa đúng.']);

    expect((string) $this->blade('<x-bs-admin::input name="email" />'))->toContain('aria-invalid="true"')
        ->and((string) $this->blade('<x-bs-admin::input name="phone" />'))->not->toContain('aria-invalid="true"');
});
