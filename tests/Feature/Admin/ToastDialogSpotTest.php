<?php

use Illuminate\Support\Facades\Blade;

/*
 * A toast never covers a dialog's buttons (on a phone a question is a sheet from the bottom whose «Ở lại»
 * sat right under the toast). Every message box tells resources/js/admin-toast-spot.js it is on the screen,
 * which lifts the toast above the dialog (--toast-over), sets it beside the dialog or puts it under, except
 * over a drawer, a working panel whose messages stay in sight. The placement itself is measured in the
 * browser (tests/js/admin-toast-spot.test.mjs for its rule).
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

it('marks the drawer as a working panel, whose messages stay in sight over it', function (): void {
    $html = Blade::render('<x-bs-admin::drawer title="Sửa" close="closePanel"><p>Nội dung</p></x-bs-admin::drawer>');

    expect($html)->toContain('data-bs-modal data-toast-keep');
});

it('loads the placement with the admin script on every screen', function (): void {
    $app = (string) file_get_contents(resource_path('js/app.js'));

    expect($app)->toContain("import './admin-toast-spot.js';");
});
