<?php

use Illuminate\Support\Facades\File;

/*
 * The admin's overlays in one order: the page's own menus and the drawer (a side panel over the
 * page) under every dialog, the toast over everything. A dialog opened from the drawer — the media
 * picker from "Chọn ảnh bìa", a confirm question — must show over the drawer, not behind it; so
 * every full-screen dialog layer of the admin views sits above the drawer's layer.
 */

/** The z-index of a Tailwind class list's `z-N`, or null. */
function adminLayerOf(string $classes): ?int
{
    return preg_match('/(?<![\w-])z-(\d+)(?![\w\/-])/', $classes, $m) === 1 ? (int) $m[1] : null;
}

it('draws every full-screen dialog above the drawer', function (): void {
    $root = base_path('app/Modules/Admin/resources/views');
    $drawer = (string) file_get_contents($root.'/components/drawer.blade.php');
    preg_match('/x-ref="panel"[^>]*class="([^"]*)"/s', $drawer, $panel);
    $drawerLayer = adminLayerOf($panel[1] ?? '');
    expect($drawerLayer)->not->toBeNull();

    // The shared dialogs' full-screen wrappers, and any hand-built dialog that covers the screen.
    $shared = ['components/modal.blade.php', 'components/confirm-dialog.blade.php'];
    $found = [];
    foreach (File::allFiles($root) as $file) {
        $rel = str_replace($root.'/', '', $file->getPathname());
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
        preg_match_all('/<[a-zA-Z][\w:.-]*\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $source, $tags);
        foreach ($tags[1] as $attrs) {
            if (preg_match('/\bclass="([^"]*)"/', $attrs, $c) !== 1 || ! preg_match('/(?<![\w-])fixed\b/', $c[1]) || ! str_contains($c[1], 'inset-0')) {
                continue;
            }
            $dialog = preg_match('/role="(?:dialog|alertdialog)"|aria-modal=/', $attrs) === 1;
            if (! $dialog && ! (in_array($rel, $shared, true) && str_contains($c[1], 'justify-center'))) {
                continue;
            }
            $found[] = $rel;
            expect(adminLayerOf($c[1]))->toBeGreaterThan($drawerLayer, $rel.' draws a dialog under the drawer');
        }
    }

    expect($found)->toContain('components/modal.blade.php', 'components/confirm-dialog.blade.php', 'partials/media-picker-modal.blade.php');
});
