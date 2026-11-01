<?php

/*
 * Every admin text field and select takes its height and text size from one shared utility,
 * `field` in app.css (the kit input uses it too): 40px at the default text size, 44px from the next
 * size up, 44px on touch, the text at the user's body size. `field-row` draws a field on a list row
 * at the row-control size and keeps the 44px touch height. A field sized by hand (py-2 text-sm,
 * min-h-9, text-[13px]…) misses the touch height and ignores the user's text size.
 */

const ADMIN_FIELD_VIEWS = 'Modules/Admin/resources/views';

/** Input types that are not text fields: they have their own size rules. */
const ADMIN_NON_TEXT_INPUTS = ['checkbox', 'radio', 'file', 'hidden', 'color', 'range', 'submit', 'button', 'image', 'reset'];

/**
 * Size tokens that only the shared rules may set. A field is never sized by anything else.
 */
const ADMIN_FIELD_SIZE_TOKEN = '/^(?:[a-z0-9-]+:)*(?:(?:p|py|pt|pb|h|min-h|max-h|size)-|text-(?:xs|sm|base|lg|xl|caption|small|body|lead|\[[\d.]+(?:px|rem)\])$)/';

/**
 * Each <input>/<select> opening tag in the admin views, quote-aware (an attribute may hold "=>").
 *
 * @return list<array{where: string, tag: string}>
 */
function adminFieldTags(): array
{
    $tags = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(ADMIN_FIELD_VIEWS), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.blade.php')) {
            continue;
        }
        $source = (string) file_get_contents((string) $file);
        preg_match_all('/<(?:input|select)\s/', $source, $starts, PREG_OFFSET_CAPTURE);

        foreach ($starts[0] as [$open, $offset]) {
            $quote = null;
            for ($i = $offset + strlen($open); $i < strlen($source); $i++) {
                $char = $source[$i];
                if ($quote !== null) {
                    $quote = $char === $quote ? null : $quote;
                } elseif (substr($source, $i, 2) === '{{') {
                    $i = (int) strpos($source, '}}', $i) + 1; // Blade echo: its PHP may hold "->" or "=>"
                } elseif ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '>' && ! in_array($source[$i - 1], ['-', '='], true)) {
                    break; // the tag's end, not a PHP "->" / "=>" in a Blade directive
                }
            }
            $tag = substr($source, $offset, $i - $offset + 1);
            if (preg_match('/\stype="([^"]*)"/', $tag, $type) === 1 && preg_match('/\b(?:'.implode('|', ADMIN_NON_TEXT_INPUTS).')\b/', $type[1]) === 1) {
                continue;
            }
            $where = str_replace(app_path(ADMIN_FIELD_VIEWS).'/', '', (string) $file).':'.(substr_count($source, "\n", 0, $offset) + 1);
            $tags[] = ['where' => $where, 'tag' => $tag];
        }
    }

    return $tags;
}

/**
 * @return list<string>
 */
function adminFieldClasses(string $tag): array
{
    return preg_match('/\sclass="([^"]*)"/', $tag, $m) === 1 ? (preg_split('/\s+/', trim($m[1])) ?: []) : [];
}

it('declares the field size once: the main control height, 44 on touch, the user body text', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect(preg_match('/@utility field \{(.*?)\n\}/s', $css, $field))->toBe(1)
        ->and($field[1])->toContain('min-height: var(--field-height, var(--spacing-control));', 'font-size: var(--text-body);', '@apply any-pointer-coarse:min-h-hit;')
        ->and(preg_match('/@utility field-row \{(.*?)\n\}/s', $css, $row))->toBe(1)
        ->and(trim($row[1]))->toBe('--field-height: var(--spacing-control-row);');
});

it('sizes every admin text field and select by the shared field utility, never by hand', function (): void {
    $tags = adminFieldTags();
    // the scan must see the fields it guards (about forty today)
    expect(count($tags))->toBeGreaterThan(30);

    foreach ($tags as ['where' => $where, 'tag' => $tag]) {
        if (str_contains($tag, '$attributes')) {
            continue; // the kit input: its classes are merged in PHP, checked by rendering below
        }
        $classes = adminFieldClasses($tag);
        $sizes = array_values(array_filter($classes, fn (string $c): bool => preg_match(ADMIN_FIELD_SIZE_TOKEN, $c) === 1));

        if (in_array('field', $classes, true)) {
            expect($sizes)->toBe([], "{$where}: a `field` sized again by hand");

            continue;
        }

        // Fields sized by another shared rule: the list title field (admin-list.css), the sign-in
        // pages' field (auth.css), the name-cell target, and a bare field inside a sized box.
        $shared = in_array('list-in', $classes, true) || in_array('auth-input', $classes, true)
            || in_array('min-h-tap-cell', $classes, true)
            || (in_array('border-0', $classes, true) && in_array('bg-transparent', $classes, true));
        expect($shared)->toBeTrue("{$where}: a field without the shared `field` size");
        expect(array_diff($sizes, ['min-h-control', 'any-pointer-coarse:min-h-hit', 'text-body', 'min-h-tap-cell', 'p-0']))
            ->toBe([], "{$where}: a field sized by hand");
    }
});

it('sizes the kit input by the shared field utility', function (): void {
    $classes = explode(' ', (string) preg_replace('/.*<input[^>]*class="([^"]*)".*/s', '$1', (string) $this->blade('<x-bs-admin::input name="email" label="Email" />')));

    expect($classes)->toContain('field')
        ->not->toContain('min-h-control', 'py-1.5', 'text-body', 'text-sm');
});
