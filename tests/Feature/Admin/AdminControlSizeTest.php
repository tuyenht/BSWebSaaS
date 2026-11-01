<?php

use Dom\HTMLDocument;

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

it('sizes the kit input by the shared field size (main control, 44 on touch)', function (): void {
    expect(classNames((string) $this->blade('<x-bs-admin::input name="email" label="Email" />')))
        ->toContain('field', 'aria-invalid:border-danger-text');
});

it('marks a field the server rejected, and only that field', function (): void {
    $this->withViewErrors(['email' => 'Email chưa đúng.']);

    expect((string) $this->blade('<x-bs-admin::input name="email" />'))->toContain('aria-invalid="true"')
        ->and((string) $this->blade('<x-bs-admin::input name="phone" />'))->not->toContain('aria-invalid="true"');
});

it('sizes a menu row 40, 44, then 48 as the text grows, and 48 on touch', function (): void {
    $heights = array_map(fn (float $boost): float => controlTokenPx('spacing-menu-row', $boost), [0, 0.125, 0.25, 0.375]);

    expect($heights)->toBe([40.0, 44.0, 48.0, 48.0])
        ->and((string) file_get_contents(resource_path('css/app.css')))->toContain('--spacing-menu-row-touch: 3rem;');
});

/**
 * The declarations of one selector's rule in a stylesheet, outside and inside a coarse-pointer block.
 *
 * @return array{mouse: string, touch: string}
 */
function cssRuleFor(string $file, string $selector): array
{
    $css = (string) file_get_contents(resource_path('css/'.$file));
    $quoted = preg_quote($selector, '/');
    preg_match('/^\s*'.$quoted.' \{([^}]*)\}/m', $css, $mouse);
    preg_match('/@media \(any-pointer: coarse\) \{\s*'.$quoted.' \{([^}]*)\}/', $css, $touch);

    return ['mouse' => $mouse[1] ?? '', 'touch' => $touch[1] ?? ''];
}

it('sizes the list row menu by the menu-row tokens', function (): void {
    $rule = cssRuleFor('admin-list.css', '.list-mi');

    expect($rule['mouse'])->toContain('min-height: var(--spacing-menu-row);')
        ->and($rule['touch'])->toContain('min-height: var(--spacing-menu-row-touch);');
});

it('gives the list title field 44 on touch', function (): void {
    $rule = cssRuleFor('admin-list.css', '.list-in');

    expect($rule['mouse'])->toContain('min-height: var(--spacing-control);')
        ->and($rule['touch'])->toContain('min-height: var(--spacing-hit);');
});

it('draws a pager link at the row-control size with a 44 touch hit area', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect(preg_match('/@utility pager-link \{(.*?)\n\}/s', $css, $m))->toBe(1)
        ->and($m[1])->toContain('min-width: var(--spacing-control-row);', 'min-height: var(--spacing-control-row);', 'padding-inline: 0.5rem;', '@apply any-pointer-coarse:hit-area;')
        ->and($m[1])->not->toContain('--spacing-hit', '--spacing-control);');
});

it('draws every kit pager link with the shared pager class', function (): void {
    $dom = HTMLDocument::createFromString((string) $this->blade('<x-bs-admin::pagination :current="3" :last="8" />'), LIBXML_NOERROR);
    $links = $dom->querySelectorAll('nav > a, nav > span[aria-current], nav > span[aria-hidden]');

    expect($links->length)->toBe(7);
    foreach ($links as $link) {
        expect(explode(' ', (string) $link->getAttribute('class')))->toContain('pager-link')->not->toContain('h-9', 'min-w-9');
    }
});

it('pages the posts list with the same pager class, not a copy of its sizes', function (): void {
    $view = (string) file_get_contents(app_path('Modules/Admin/resources/views/livewire/entries/index.blade.php'));

    expect(substr_count($view, "@class(['pager-link', "))->toBe(3)
        ->and($view)->not->toContain('$pgBase')
        ->and($view)->not->toContain('h-9 min-w-9');
});

it('sizes the kit menu rows by the menu-row tokens at the user text size', function (string $markup, string $selector): void {
    $dom = HTMLDocument::createFromString((string) $this->blade($markup, kitMenuData()), LIBXML_NOERROR);
    $rows = $dom->querySelectorAll($selector);

    expect($rows->length)->toBeGreaterThan(0);
    foreach ($rows as $row) {
        expect(explode(' ', (string) $row->getAttribute('class')))
            ->toContain('min-h-menu-row', 'any-pointer-coarse:min-h-menu-row-touch', 'text-body')
            ->not->toContain('min-h-control', 'min-h-hit', 'any-pointer-coarse:min-h-hit', 'any-pointer-coarse:min-h-12', 'text-sm');
    }
})->with([
    'sort menu' => ['<x-bs-admin::sort-menu :options="$sort" active="new" set="setSort" />', '[role=menu] [role=menuitemradio]'],
    'group row overflow menu' => ['<x-bs-admin::group-row :groups="$groups" active="all" model="group" label="Nhóm" />', '[role=menu] [role=menuitemradio]'],
    'filter options' => ['<x-bs-admin::filter-options :section="$section" name="f" />', 'label'],
]);

/**
 * @return array<string, mixed>
 */
function kitMenuData(): array
{
    return [
        'sort' => [['key' => 'new', 'label' => 'Mới nhất'], ['key' => 'name', 'label' => 'Tên A → Z']],
        'groups' => [['key' => 'all', 'label' => 'Tất cả'], ['key' => 'trash', 'label' => 'Thùng rác', 'trash' => true]],
        'section' => ['key' => 'status', 'model' => 'status', 'options' => [['value' => '', 'label' => 'Tất cả'], ['value' => 'draft', 'label' => 'Nháp']]],
    ];
}

it('makes every row of the kit dropdown a menu row at the user text size', function (): void {
    $dom = HTMLDocument::createFromString((string) $this->blade('<x-bs-admin::dropdown><x-slot:trigger>Mở</x-slot:trigger><a href="#">Sửa</a></x-bs-admin::dropdown>'), LIBXML_NOERROR);
    $panel = explode(' ', (string) $dom->querySelector('div[x-show="open"]')?->getAttribute('class'));

    expect($panel)->toContain('*:flex', '*:items-center', '*:min-h-menu-row', 'any-pointer-coarse:*:min-h-menu-row-touch', 'text-body')
        ->not->toContain('text-sm');
});

it('sizes the kit tabs as main controls at the user text size, 44 on touch', function (): void {
    $dom = HTMLDocument::createFromString((string) $this->blade('<x-bs-admin::tabs :tabs="[\'a\' => \'Thông tin\', \'b\' => \'SEO\']" />'), LIBXML_NOERROR);
    $tabs = $dom->querySelectorAll('[role=tab]');

    expect($tabs->length)->toBe(2);
    foreach ($tabs as $tab) {
        expect(explode(' ', (string) $tab->getAttribute('class')))
            ->toContain('min-h-control', 'any-pointer-coarse:min-h-hit', 'text-body')
            ->not->toContain('py-2', 'text-sm');
    }
});

it('gives the workflow close buttons the main-control size and its switches a 44 hit area', function (string $view, int $closes, int $switches): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/resources/views/'.$view));
    // up to the class attribute: later attributes may hold an arrow function's ">"
    preg_match_all('/<button\b[^>]*?class="[^"]*"[^>]*?(?:aria-label="[^"]*"|>)/', $source, $buttons);
    $seen = ['close' => 0, 'switch' => 0];

    foreach ($buttons[0] as $button) {
        expect($button)->not->toMatch('/class="[^"]*\bsize-9\b/');
        if (str_contains($button, "aria-label=\"{{ __('admin.close') }}\"")) {
            $seen['close']++;
            expect($button)->toMatch('/class="[^"]*\bhit-area\b[^"]*\bsize-control\b(?!-)/');
        }
        if (str_contains($button, 'role="switch"')) {
            $seen['switch']++;
            expect($button)->toMatch('/class="[^"]*\bhit-area h-6 w-11\b/');
        }
    }

    expect($seen)->toBe(['close' => $closes, 'switch' => $switches]);
})->with([
    'workflow settings' => ['livewire/workflow-settings.blade.php', 1, 2],
    'workflow status' => ['livewire/entries/partials/workflow-status.blade.php', 1, 0],
]);

it('sizes the image picker and theme panel buttons by the shared control tokens', function (string $view, string $marker, string $size): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/resources/views/'.$view));
    // up to the class attribute: earlier attributes may hold "->" (wire:click="open({{ $m->id }})")
    preg_match_all('/<button\b.*?class="[^"]*"/s', $source, $buttons);
    $matched = array_values(array_filter($buttons[0], fn (string $button): bool => str_contains($button, $marker)));

    expect($matched)->not->toBeEmpty();
    foreach ($matched as $button) {
        preg_match('/class="([^"]*)"$/', $button, $class);
        expect(explode(' ', $class[1]))->toContain(...explode(' ', $size))
            ->not->toContain('size-7', 'size-8');
    }
})->with([
    'picker close' => ['partials/media-picker-modal.blade.php', 'wire:click="closeImagePicker" title=', 'hit-area size-control'],
    'picker file details (list row)' => ['partials/media-picker-library-pane.blade.php', 'shrink-0 items-center justify-center', 'hit-area size-control-row'],
    'picker previous page' => ['partials/media-picker-library-pane.blade.php', 'wire:click="pickerPrev"', 'pager-link'],
    'picker next page' => ['partials/media-picker-library-pane.blade.php', 'wire:click="pickerNext"', 'pager-link'],
    'picker folder link edit' => ['partials/media-picker-library-pane.blade.php', 'wire:click="editPickerFolderSlug"', 'hit-area size-tap-cell'],
    'picker grid view' => ['partials/media-picker-library-pane.blade.php', "wire:click=\"setPickerView('grid')\"", 'hit-area size-control'],
    'picker list view' => ['partials/media-picker-library-pane.blade.php', "wire:click=\"setPickerView('list')\"", 'hit-area size-control'],
    'picker grid tile details' => ['partials/media-picker-library-pane.blade.php', 'absolute start-[3px] top-[3px]', 'size-control any-pointer-coarse:size-hit'],
    'theme panel close' => ['partials/customizer.blade.php', '@click="$store.adminTheme.open = false"', 'hit-area size-control'],
]);

it('leaves the workflow status buttons to the shared button size', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/resources/views/livewire/entries/partials/workflow-status.blade.php'));

    expect($source)->not->toContain('min-h-11');
});

it('sizes touch targets for any touch screen, touch laptops included', function (): void {
    $sources = [];
    foreach (glob(resource_path('css/*.css')) ?: [] as $css) {
        $sources[$css] = (string) file_get_contents($css);
    }
    foreach ([base_path('app/Modules'), resource_path('views')] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ((str_ends_with($file->getPathname(), '.blade.php') && str_contains($file->getPathname(), '/resources/views/')) || str_starts_with($file->getPathname(), resource_path('views'))) {
                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    $offenders = array_keys(array_filter($sources, fn (string $source): bool => preg_match('/(?<![-\w])pointer-coarse:|(?<![-\w])pointer:\s*coarse/', $source) === 1));

    // `pointer-coarse:` and `(pointer: coarse)` follow only the main pointer; a touch laptop's trackpad would keep the mouse sizes
    expect($offenders)->toBe([]);
});

it('draws the picker tile details badge inside a control-size button', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/resources/views/partials/media-picker-library-pane.blade.php'));
    preg_match('/<button\b[^>]*wire:click\.stop="openPickerDetail.*?<\/button>/s', $source, $button);

    expect($button)->not->toBeEmpty()
        ->and($button[0])->toContain('aria-label="{{ __(\'media.picker_detail\') }}"')
        ->toMatch('/<span class="[^"]*\bsize-\[calc\(1\.375rem\+var\(--user-text-boost\)\)\][^"]*\bbg-black\/30\b[^"]*">\s*<x-bs-admin::icon name="ri-information"/');
});

it('keeps the picker view toggles touch area outside a clipping box', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/resources/views/partials/media-picker-library-pane.blade.php'));
    preg_match('/<div class="([^"]*)">\s*<button[^>]*setPickerView\(\'grid\'\)/', $source, $group);

    expect($group)->not->toBeEmpty()
        ->and(explode(' ', $group[1]))->not->toContain('overflow-hidden');
});
