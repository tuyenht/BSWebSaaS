// The "Nút liên hệ nổi" screen's own script (window.bsFloatingContact), called from its Alpine state:
// - pickIcon: a channel's own icon picture; its code (check, upload, hand-over) loads on first use
//   only (admin-floating-contact-icon.js), so other admin pages carry none of it;
// - reopenable: after a redraw of the preview frame, the dial or the "Thêm" sheet left open is opened
//   again only when the new frame's one still shows a channel (a sheet whose every cell fits on the
//   bar is empty).

/** Whether a popover of the redrawn preview (the dial or the "Thêm" sheet) has a channel to show. */
export function reopenable(el, view) {
    if (!el || !view) {
        return false;
    }

    return [...el.querySelectorAll('li')].some((li) => view.getComputedStyle(li).display !== 'none');
}

/**
 * The file input's change (see admin-floating-contact-icon.js, loaded here on first use). When that
 * code cannot be downloaded the page says so ("Chưa tải được ảnh lên…") instead of doing nothing.
 */
export async function pickIcon(event, wire, ...rest) {
    let pick;
    try {
        ({ pickIcon: pick } = await import('./admin-floating-contact-icon.js'));
    } catch {
        await wire.iconRefused('upload');
        return;
    }

    return pick(event, wire, ...rest);
}

if (typeof window !== 'undefined') {
    window.bsFloatingContact = { pickIcon, reopenable };
}
