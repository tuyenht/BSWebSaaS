<?php

use Illuminate\Support\Facades\Blade;

/*
 * A toast never covers a dialog's buttons (on a phone a question is a sheet from the bottom whose «Ở lại»
 * sat right under the toast). Every message box tells resources/js/admin-toast-spot.js it is on the screen,
 * which lifts the toast above the dialog (--toast-over), sets it beside the dialog or puts it under, except
 * over a drawer, a working panel: there it stands above the footer's buttons, or stays in sight. The
 * placement itself is measured in the browser (tests/js/admin-toast-spot.test.mjs).
 */

it('lets every toast box tell the placement it is on the screen, in each of its forms', function (string $blade): void {
    $html = Blade::render($blade);

    expect($html)->toContain('data-toast-spot')
        ->and($html)->toContain('var(--toast-over,0px))]')
        ->and($html)->toContain('window.bsToastSpot?.watch(this.spot)')
        ->and($html)->toContain('window.bsToastSpot?.unwatch(this.spot)');
})->with([
    'success (timed)' => ['<x-bs-admin::toast message="Đã lưu" dismiss="closeToast" />'],
    'error (stays until closed)' => ['<x-bs-admin::toast message="Không lưu được" tone="error" dismiss="closeToast" />'],
    'raised over a bulk bar' => ['<x-bs-admin::toast message="Đã chuyển" dismiss="closeToast" raised />'],
    'kept in the browser' => ['<x-bs-admin::toast state="msg" dismiss="closeMsg" />'],
]);

it('keeps a timed toast\'s timer stopped while it lies under a dialog, whatever the pointer or the focus does', function (string $blade): void {
    $html = Blade::render($blade);

    // the placement starts it again (and only it) once the toast is back in sight
    expect($html)->toContain("release() { if (this.\$el.closest('[data-spot=under]')) { this.\$el._spotHeld = true; return }");
})->with([
    'success (timed)' => ['<x-bs-admin::toast message="Đã lưu" dismiss="closeToast" />'],
    'kept in the browser' => ['<x-bs-admin::toast state="msg" dismiss="closeMsg" />'],
]);

it('marks the drawer as a working panel and its footer as the buttons a toast stands clear of', function (): void {
    $html = Blade::render('<x-bs-admin::drawer title="Sửa" close="closePanel"><p>Nội dung</p><x-slot:footer><button type="button">Lưu</button></x-slot:footer></x-bs-admin::drawer>');

    expect($html)->toContain('data-bs-modal data-toast-keep')
        ->and(substr_count($html, 'data-toast-foot'))->toBe(1)
        ->and($html)->toMatch('/<div data-toast-foot [^>]*>\\s*<button type="button">Lưu<\\/button>/');
});

it('leaves the footer mark out of a drawer without a footer', function (): void {
    $html = Blade::render('<x-bs-admin::drawer title="Xem" close="closePanel"><p>Nội dung</p></x-bs-admin::drawer>');

    expect($html)->not->toContain('data-toast-foot');
});

it('loads the placement with the admin script on every screen', function (): void {
    $app = (string) file_get_contents(resource_path('js/app.js'));

    expect($app)->toContain("import './admin-toast-spot.js';");
});
