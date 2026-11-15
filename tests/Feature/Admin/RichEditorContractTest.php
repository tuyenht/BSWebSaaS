<?php

use App\Modules\Shared\Support\RichTextSanitizer;

/*
 * Rich-editor CONTRACT — pins the invariants that once let toolbar buttons silently die:
 * (1) every tool's Jodit plugin is actually imported — the bare `jodit` esm entry ships only
 *     the core plugins, and a missing import still RENDERS the bar's button but no-ops on click;
 *     and every button the frame draws is a tool the script knows;
 * (2) buttons that need companion plugins to be usable (table cell menu, image resize) have them;
 * (3) the source view never loads ACE/js-beautify from a CDN (white-label);
 * (4) everything the editor's own tools WRITE survives the server-side sanitizer — a button
 *     whose output is stripped on save is as dead as a missing plugin.
 */

/** Plugins bundled by the bare `jodit` esm entry (esm/plugins/index.js, jodit 4.12.x). */
const JODIT_CORE_PLUGINS = [
    'about', 'backspace', 'delete', 'bold', 'color', 'enter', 'font', 'format-block',
    'hotkeys', 'iframe', 'image', 'inline-popup', 'link', 'ordered-list', 'placeholder',
    'powered-by-jodit', 'redo-undo', 'size', 'stat', 'table', 'wrap-nodes', 'dtd',
];

/** Toolbar button → the Jodit plugin that registers its control + commands. */
const JODIT_BUTTON_PLUGIN = [
    'paragraph' => 'format-block',
    'bold' => 'bold', 'italic' => 'bold', 'underline' => 'bold', 'strikethrough' => 'bold',
    'superscript' => 'bold', 'subscript' => 'bold',
    'font' => 'font', 'fontsize' => 'font', 'brush' => 'color',
    'ul' => 'ordered-list', 'ol' => 'ordered-list',
    'left' => 'justify', 'center' => 'justify', 'right' => 'justify', 'justify' => 'justify',
    'outdent' => 'indent', 'indent' => 'indent',
    'table' => 'table', 'eraser' => 'clean-html',
    'undo' => 'redo-undo', 'redo' => 'redo-undo',
    'source' => 'source',
];

/** Tools the frame does itself (its own dialogs, the Livewire bridge, inserted HTML), not plugin-backed. */
const JODIT_CUSTOM_CONTROLS = ['link', 'unlink', 'libraryImage', 'embedVideo', 'hr', 'symbols', 'fullsize'];

/** The three lists of the bar: the button opens a list, its items run the tool's command. */
const RICH_MENUS = ['para' => 'paragraph', 'size' => 'fontsize', 'color' => 'brush'];

/** Plugins a button needs to be USABLE, not merely clickable. */
const JODIT_COMPANION_PLUGINS = [
    'table' => ['select-cells', 'resize-cells', 'table-keyboard-navigation'],
    'libraryImage' => ['resizer', 'image-properties'],
];

function richEditorSource(): string
{
    return (string) file_get_contents(resource_path('js/admin-rich-editor.js'));
}

/** @return list<string> */
function richEditorButtons(string $source, string $const): array
{
    preg_match('/const '.$const.' = \[(.*?)\];/s', $source, $m);

    expect($m)->not->toBeEmpty();
    preg_match_all("/'([^']+)'/", $m[1], $names);

    return array_values(array_filter($names[1], fn (string $n): bool => $n !== '|'));
}

/** @return list<string> */
function richEditorImportedPlugins(string $source): array
{
    preg_match_all("#import 'jodit/esm/plugins/([a-z-]+)/#", $source, $m);

    return $m[1];
}

it('imports the Jodit plugin behind every toolbar button (dead-button guard)', function (): void {
    $src = richEditorSource();
    $available = [...JODIT_CORE_PLUGINS, ...richEditorImportedPlugins($src)];

    $dead = [];
    foreach (['FULL_BUTTONS'] as $const) {
        foreach (richEditorButtons($src, $const) as $button) {
            if (in_array($button, JODIT_CUSTOM_CONTROLS, true)) {
                continue;
            }
            $plugin = JODIT_BUTTON_PLUGIN[$button] ?? null;
            if ($plugin === null) {
                $dead[] = "{$button} → UNMAPPED (add it to JODIT_BUTTON_PLUGIN)";
            } elseif (! in_array($plugin, $available, true)) {
                $dead[] = "{$button} → import 'jodit/esm/plugins/{$plugin}/{$plugin}.js' is missing";
            }
        }
    }

    expect($dead)->toBe([]);
});

it('imports the companion plugins that make table and image tooling usable', function (): void {
    $src = richEditorSource();
    $offered = richEditorButtons($src, 'FULL_BUTTONS');
    $imported = richEditorImportedPlugins($src);

    $missing = [];
    foreach (JODIT_COMPANION_PLUGINS as $button => $companions) {
        if (! in_array($button, $offered, true)) {
            continue;
        }
        foreach ($companions as $plugin) {
            if (! in_array($plugin, $imported, true)) {
                $missing[] = "{$button} needs {$plugin}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('backs every custom tool with its own handler', function (): void {
    $src = richEditorSource();
    preg_match('/const TOOLS = \{(.*?)\n    \};/s', $src, $m);

    expect($m)->not->toBeEmpty();
    foreach (JODIT_CUSTOM_CONTROLS as $control) {
        expect($m[1])->toMatch('/^\s+'.$control.'[:,]/m');
    }
});

it('draws only tools the script knows, and offers every one of them', function (): void {
    $blade = (string) file_get_contents(base_path('app/Modules/Admin/resources/views/components/rich-editor.blade.php'));
    $tools = richEditorButtons(richEditorSource(), 'FULL_BUTTONS');

    preg_match_all("/\\\$tool\('([A-Za-z]+)'/", $blade, $cmds);
    preg_match_all('/data-rte-menu="([a-z]+)"/', $blade, $menus);
    $drawn = [...$cmds[1], ...array_map(fn (string $m): string => RICH_MENUS[$m] ?? "menu:{$m}", $menus[1])];

    expect(array_values(array_diff($drawn, $tools)))->toBe([])
        ->and(array_values(array_diff($tools, $drawn)))->toBe([]);
});

it('keeps the source view CDN-free (white-label: no ACE / js-beautify from cdnjs)', function (): void {
    $src = richEditorSource();

    expect($src)->toContain("sourceEditor: 'area'")
        ->toContain('beautifyHTML: false');
});

it('keeps everything the editor tools write through the sanitizer (no silent format loss)', function (): void {
    // Exactly what the newly-enabled tools emit: indent → margin-left (padding-left in cells),
    // hr → <hr>, column/table drag-resize → width/height styles, image resize → width/height attrs.
    $html = '<p style="margin-left:20px">indent</p>'
        .'<hr>'
        .'<table style="width:100%"><tbody><tr>'
        .'<td style="padding-left:10px;width:33%;height:40px">cell</td>'
        .'</tr></tbody></table>'
        .'<img src="https://files.baoson.net/x.png" alt="jig" width="800" height="600">';

    $out = app(RichTextSanitizer::class)->full($html);

    expect($out)->toContain('margin-left')
        ->toContain('<hr')
        ->toContain('<table style=')
        ->toContain('width:100%')
        ->toContain('padding-left')
        ->toContain('width:33%')
        ->toContain('height:40px')
        ->toContain('width="800"')
        ->toContain('height="600"');
});

it('still strips what the allowlist widening must NOT open (script, positioning, foreign iframes)', function (): void {
    $out = app(RichTextSanitizer::class)->full(
        '<script>alert(1)</script><p style="position:absolute;margin-left:5000px">x</p>'
        .'<iframe src="https://evil.example.com/x"></iframe>'
    );

    expect($out)->not->toContain('<script')
        ->not->toContain('position')
        ->not->toContain('evil.example.com')
        ->toContain('margin-left'); // the inert prop survives right next to the stripped one
});
