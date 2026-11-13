<?php

use Illuminate\Support\Facades\File;

/*
 * An admin screen draws its dialogs, its messages and its buttons with the shared components
 * (<x-bs-admin::modal>, <x-bs-admin::confirm-dialog>, <x-bs-admin::toast>, <x-bs-admin::button>):
 * those carry the focus trap and the way back, the timing and the "Hoàn tác" offer, the sizes and
 * every state, and change in one place. A Livewire view that builds its own copy keeps the old
 * look and behaviour when the shared part moves on. This test counts, per view under
 * app/Modules/Admin/resources/views/livewire/, the hand-built ones:
 *  - dialogs: an element with role="dialog" / role="alertdialog" or aria-modal;
 *  - message boxes: an element with role="status" or aria-live (a plain live line counts too);
 *  - buttons: a raw <button> or <a> carrying one of the button component's fill colours.
 *
 * The views below already had some when this test was written. It is a ratchet: a count may only
 * go down. A view not listed may have none; a listed view may not gain one; and when a view loses
 * one, its line here must be lowered (or removed) in the same change, so it cannot creep back.
 */

/** View (relative to the livewire folder) => [dialogs, message boxes, buttons]. */
const ADMIN_HAND_BUILT_PARTS = [
    'entries/editor.blade.php' => [2, 0, 0],
    'entries/partials/workflow-status.blade.php' => [1, 0, 0],
    'floating-contact-settings.blade.php' => [0, 1, 0],
    'general-settings.blade.php' => [0, 1, 0],
    'login.blade.php' => [0, 1, 0],
    'menus/index.blade.php' => [0, 3, 0],
    // the top bar bell's panel: a non-modal popover under the bell (design 01 .ntf-pop), which
    // neither the modal (a dimmed, trapping dialog) nor the drawer (a side panel) draws
    'notification-bell.blade.php' => [1, 0, 0],
    'platform/audit/cleanup-dialog.blade.php' => [0, 2, 0],
    'platform/audit/index.blade.php' => [0, 1, 0],
    'platform/tenants/index.blade.php' => [0, 2, 0],
    'seo-settings.blade.php' => [0, 1, 0],
    'settings.blade.php' => [0, 0, 1],
    'settings/domains.blade.php' => [0, 2, 0],
    'workflow-settings.blade.php' => [4, 1, 0],
];

/** The fill colours of components/button.blade.php (its solid tones; the soft and outline tones fill with them on hover). */
const ADMIN_BUTTON_FILLS = '/(?<![\w-])bg-admin-(?:primary|secondary-solid|success-solid|info-solid|warning|danger-solid|dark|light)(?![\w\/-])/';

/**
 * [dialogs, message boxes, buttons] hand-built in one view. Blade comments are left out; a tag is
 * read whole even when an attribute value holds a ">" (an Alpine expression); a component tag
 * (<x-…>) is the shared part, not a copy of it.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function adminHandBuiltParts(string $source): array
{
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    preg_match_all('/<([a-zA-Z][\w:.-]*)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $source, $tags, PREG_SET_ORDER);

    $counts = [0, 0, 0];
    foreach ($tags as [, $name, $attributes]) {
        if (str_starts_with($name, 'x-')) {
            continue;   // a component tag: the shared part itself
        }
        if (preg_match('/\brole\s*=\s*["\'](?:alert)?dialog["\']|\baria-modal\b/', $attributes) === 1) {
            $counts[0]++;
        }
        if (preg_match('/\brole\s*=\s*["\']status["\']|\baria-live\b/', $attributes) === 1) {
            $counts[1]++;
        }
        if (in_array(strtolower($name), ['button', 'a'], true) && preg_match(ADMIN_BUTTON_FILLS, $attributes) === 1) {
            $counts[2]++;
        }
    }

    return $counts;
}

it('reads hand-built dialogs, message boxes and buttons, and only those', function (): void {
    expect(adminHandBuiltParts(<<<'BLADE'
        {{-- <div role="dialog"> in a comment --}}
        <div x-show="open && n > 1" role="dialog" aria-modal="true"></div>
        <div role="alertdialog"></div>
        <p role="status">Đang tải</p><span aria-live="polite"></span>
        <button type="button" class="rounded bg-admin-danger-solid px-3 text-white">Xóa</button>
        <a href="/" class="hover:bg-admin-primary text-accent">Mở</a>
        <span class="bg-admin-primary text-white">Nhãn</span>
        <button class="bg-admin-primary/10 bg-admin-primary-soft">Mềm</button>
        <x-bs-admin::modal role="dialog" />
        BLADE))->toBe([2, 2, 2]);
});

it('builds no more dialogs, message boxes or buttons by hand than the views already had', function (): void {
    $root = base_path('app/Modules/Admin/resources/views/livewire');
    $found = [];
    foreach (File::allFiles($root) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $counts = adminHandBuiltParts($file->getContents());
            if ($counts !== [0, 0, 0]) {
                $found[str_replace('\\', '/', $file->getRelativePathname())] = $counts;
            }
        }
    }
    $problems = [];
    foreach (array_keys($found + ADMIN_HAND_BUILT_PARTS) as $view) {
        $now = $found[$view] ?? [0, 0, 0];
        $allowed = ADMIN_HAND_BUILT_PARTS[$view] ?? [0, 0, 0];
        foreach (['dialogs', 'message boxes', 'buttons'] as $i => $what) {
            if ($now[$i] > $allowed[$i]) {
                $problems[] = "{$view}: {$now[$i]} hand-built {$what}, {$allowed[$i]} allowed — use the shared component";
            } elseif ($now[$i] < $allowed[$i]) {
                $problems[] = "{$view}: {$what} went down to {$now[$i]} — lower its line in ADMIN_HAND_BUILT_PARTS";
            }
        }
    }

    expect($problems)->toBe([]);
});
