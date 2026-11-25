<?php

use Illuminate\Support\Facades\Blade;

/**
 * Below 600px every drawer and dialog footer stacks its buttons full width with the main button on top,
 * drawn by column-reverse: the markup (and so the Tab order) keeps «Hủy» first. From 600px nothing moves.
 */
function footerOf(string $html): string
{
    expect(preg_match('/<div class="([^"]*border-t[^"]*)">\s*<button type="button">Hủy<\/button>/', $html, $m))->toBe(1);

    return $m[1];
}

dataset('footers', [
    'drawer' => ['<x-bs-admin::drawer title="Sửa" close="closePanel"><p>Nội dung</p><x-slot:footer><button type="button">Hủy</button><button type="button">Lưu</button></x-slot:footer></x-bs-admin::drawer>'],
    'modal (Livewire state)' => ['<x-bs-admin::modal model="open" title="Sửa"><p>Nội dung</p><x-slot:footer><button type="button">Hủy</button><button type="button">Lưu</button></x-slot:footer></x-bs-admin::modal>'],
    'modal (plain)' => ['<x-bs-admin::modal title="Sửa"><p>Nội dung</p><x-slot:footer><button type="button">Hủy</button><button type="button">Lưu</button></x-slot:footer></x-bs-admin::modal>'],
]);

it('stacks the footer below 600px with the main button on top and keeps «Hủy» first in the markup', function (string $blade): void {
    $html = Blade::render($blade);
    $classes = explode(' ', footerOf($html));

    expect($classes)->toContain('max-[599.98px]:flex-col-reverse')
        ->and($classes)->toContain('max-[599.98px]:*:w-full')
        ->and($classes)->toContain('justify-end')
        ->and(implode(' ', $classes))->not->toMatch('/(^| )(sm|md|lg|min-\[600px\]):flex-col/');

    expect(strpos($html, '>Hủy</button>'))->toBeLessThan(strpos($html, '>Lưu</button>'));
})->with('footers');

it('draws the footers the way the confirm dialog already does', function (): void {
    $confirm = (string) file_get_contents(base_path('app/Modules/Admin/resources/views/components/confirm-dialog.blade.php'));

    expect($confirm)->toContain('max-[599.98px]:flex-col-reverse max-[599.98px]:*:w-full');
});
