<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/*
 * The admin UI components (<x-bs-admin::…>) are compiled by Blaze into plain function calls, so a
 * list page that draws hundreds of them does not pay Blade's component pipeline for each one.
 * These pin that the compiled output keeps what the screens rely on.
 */

it('compiles the admin components through Blaze', function (): void {
    $compiled = Blade::compileString('<x-bs-admin::icon name="x" />');

    expect($compiled)->toContain('$__blaze')
        ->and($compiled)->not->toContain('withAttributes');
});

it('passes an escaped Alpine binding through as a plain Alpine binding', function (): void {
    $html = Blade::render('<x-bs-admin::icon name="chevron-down" class="size-4" ::class="open && \'rotate-180\'" />');

    expect($html)->toContain(' :class="open && \'rotate-180\'"')
        ->and($html)->not->toContain('::class')
        ->and($html)->toContain('class="size-5 size-4"');
});

it('keeps bound props, merged classes and slots', function (): void {
    $html = Blade::render(
        '<x-bs-admin::button variant="ghost" :disabled="$off" class="extra">Lưu {{ $n }}</x-bs-admin::button>',
        ['off' => true, 'n' => 3],
    );

    expect($html)->toContain('Lưu 3')
        ->and($html)->toContain('extra')
        ->and($html)->toContain('disabled');
});

it('shows the validation message of the current render only', function (): void {
    $withError = (new ViewErrorBag)->put('default', new MessageBag(['email' => ['Email chưa đúng.']]));
    view()->share('errors', $withError);
    $first = Blade::render('<x-bs-admin::input name="email" label="Email" />');

    view()->share('errors', new ViewErrorBag);
    $second = Blade::render('<x-bs-admin::input name="email" label="Email" />');

    expect($first)->toContain('Email chưa đúng.')
        ->and($second)->not->toContain('Email chưa đúng.');
});

it('covers every admin component file', function (): void {
    $dir = base_path('app/Modules/Admin/resources/views/components');
    $files = collect(File::allFiles($dir))->filter(fn ($f): bool => str_ends_with($f->getFilename(), '.blade.php'));

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        $name = str_replace(['/', '.blade.php'], ['.', ''], $file->getRelativePathname());
        expect(Blade::compileString('<x-bs-admin::'.$name.' />'))->toContain('$__blaze');
    }
});
