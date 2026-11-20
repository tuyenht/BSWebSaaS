<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
        ->and($html)->toMatch('/<button[^>]* disabled[ >=]/');

    $enabled = Blade::render('<x-bs-admin::button :disabled="$off">Lưu</x-bs-admin::button>', ['off' => false]);

    expect($enabled)->not->toMatch('/<button[^>]* disabled[ >=]/');
});

it('hands a value down to a nested component through @aware, and only inside its parent', function (): void {
    $inside = Blade::render(
        '<x-bs-admin::tree label="Cây" move="mv"><x-bs-admin::tree.name>Gốc</x-bs-admin::tree.name></x-bs-admin::tree>',
    );
    $outside = Blade::render('<x-bs-admin::tree.name>Gốc</x-bs-admin::tree.name>');

    expect($inside)->toContain('<button type="button" data-tree-name')
        ->and($outside)->toContain('<span class="tree-nm">Gốc</span>')
        ->and($outside)->not->toContain('data-tree-name');
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

it('compiles the body of every admin component into valid PHP', function (): void {
    $dir = base_path('app/Modules/Admin/resources/views/components');
    $files = collect(File::allFiles($dir))->filter(fn ($f): bool => str_ends_with($f->getFilename(), '.blade.php'));
    $compiler = app('blade.compiler');

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        $name = str_replace(['/', '.blade.php'], ['.', ''], $file->getRelativePathname());
        expect(Blade::compileString('<x-bs-admin::'.$name.' />'))->toContain('$__blaze');

        // The body is compiled on first use, the way a page would reach it: into one function.
        $compiler->compile($file->getPathname());
        $compiled = $compiler->getCompiledPath($file->getPathname());
        $lint = Process::run([PHP_BINARY, '-l', $compiled]);

        expect(File::get($compiled))->toMatch('/function _[0-9a-f]+\\(/', $name)
            ->and($lint->successful())->toBeTrue($name.': '.$lint->output());
    }
});
