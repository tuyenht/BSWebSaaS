<?php

/*
 * The shared admin button draws the states the boards draw (admin-components.css `.sx-btn`): a
 * button kept focusable but marked aria-disabled looks off, and the bordered button beside a main
 * one is the neutral `.sx-btn` (field edge, card fill, body ink, 6px gap), never the secondary hue.
 */

it('fades a button marked aria-disabled and gives it the not-allowed cursor, as the boards draw it', function (string $markup): void {
    expect((string) $this->blade($markup))
        ->toContain('aria-disabled:opacity-60')
        ->toContain('aria-disabled:cursor-not-allowed')
        // `.sx-btn[aria-disabled="true"]{filter:none}`: a solid button that is off does not darken on hover
        ->toContain('aria-disabled:filter-none');
})->with([
    'solid' => '<x-bs-admin::button aria-disabled="true">Lưu</x-bs-admin::button>',
    'outline' => '<x-bs-admin::button variant="outline" color="secondary" aria-disabled="true">Mục con</x-bs-admin::button>',
    'soft row button' => '<x-bs-admin::button size="sm" variant="soft" aria-disabled="true">Sửa</x-bs-admin::button>',
    'link' => '<x-bs-admin::button href="/admin" aria-disabled="true">Mở</x-bs-admin::button>',
]);

it('keeps the faded look of aria-disabled clickable, so a tap can still say why it is off', function (): void {
    expect((string) $this->blade('<x-bs-admin::button aria-disabled="true">Lưu</x-bs-admin::button>'))
        ->toContain('aria-disabled:opacity-60')
        ->not->toContain('aria-disabled:pointer-events-none')
        // a truly disabled button stays out of reach, and a toggle shows its on state as before
        ->toContain('disabled:opacity-60 disabled:pointer-events-none')
        ->toContain('aria-pressed:bg-primary-soft');
});

it('draws an outline secondary button as the neutral bordered button of the boards', function (): void {
    $html = (string) $this->blade('<x-bs-admin::button variant="outline" color="secondary">Tải lại</x-bs-admin::button>');

    expect($html)->toContain('border border-border-input bg-surface text-text hover:bg-surface-hover hover:text-text-strong')
        ->not->toContain('admin-secondary-text')
        ->not->toContain('admin-secondary-solid')
        // `.sx-btn` sets its icon 6px from its words and no label alignment of its own, as the neutral one
        ->toContain('gap-1.5')
        ->not->toContain('gap-2')
        ->not->toContain('text-center');
    expect($html)->toBe((string) $this->blade('<x-bs-admin::button variant="outline" color="neutral">Tải lại</x-bs-admin::button>'));

    // only the secondary hue changes: the other outline colours keep their own edge and ink
    foreach (['primary' => 'border border-accent text-accent', 'success' => 'border border-admin-success-text text-admin-success-text', 'danger' => 'border border-admin-danger-text text-admin-danger-text'] as $color => $edge) {
        expect((string) $this->blade("<x-bs-admin::button variant=\"outline\" color=\"{$color}\">Lưu</x-bs-admin::button>"))->toContain($edge);
    }
});
