// Branded confirm dialog to replace the native window.confirm (what wire:confirm uses). SweetAlert2
// is lazy-loaded on first use, so it costs nothing until a confirm actually fires.
//
// Blade usage (drop wire:confirm, keep wire:click's action on the Alpine handler):
//   <button x-on:click="$bsConfirm({ text: '…', danger: true, confirm: '…', cancel: '…' })
//           .then(ok => ok && $wire.someAction(arg))">…</button>
// Registered as the Alpine magic $bsConfirm and as window.bsConfirm.

// Remix "delete-bin" glyph — shown in the icon circle for trash actions (clearer than a generic "!").
const BIN_SVG =
    '<svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor" aria-hidden="true"><path d="M17 6H22V8H20V21C20 21.5523 19.5523 22 19 22H5C4.44772 22 4 21.5523 4 21V8H2V6H7V3C7 2.44772 7.44772 2 8 2H16C16.5523 2 17 2.44772 17 3V6ZM18 8H6V20H18V8ZM9 11H11V17H9V11ZM13 11H15V17H13V11ZM9 4V6H15V4H9Z"/></svg>';

// Remix "logout-box-r" glyph — the sign-out question, in a red circle like the menu item.
const LOGOUT_SVG =
    '<svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor" aria-hidden="true"><path d="M5 22C4.44772 22 4 21.5523 4 21V3C4 2.44772 4.44772 2 5 2H19C19.5523 2 20 2.44772 20 3V6H18V4H6V20H18V18H20V21C20 21.5523 19.5523 22 19 22H5ZM18 16V13H11V11H18V8L23 12L18 16Z"/></svg>';

// Remix "global" glyph — going live on the website («Duyệt đăng “…”?»), in a green circle.
const PUBLISH_SVG =
    '<svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor" aria-hidden="true"><path d="M12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22ZM9.71002 19.6674C8.74743 17.6259 8.15732 15.3742 8.02731 13H4.06189C4.458 16.1765 6.71639 18.7747 9.71002 19.6674ZM10.0307 13C10.1811 15.4388 10.8778 17.7297 12 19.752C13.1222 17.7297 13.8189 15.4388 13.9693 13H10.0307ZM19.9381 13H15.9727C15.8427 15.3742 15.2526 17.6259 14.29 19.6674C17.2836 18.7747 19.542 16.1765 19.9381 13ZM4.06189 11H8.02731C8.15732 8.62577 8.74743 6.37407 9.71002 4.33256C6.71639 5.22533 4.458 7.8235 4.06189 11ZM10.0307 11H13.9693C13.8189 8.56122 13.1222 6.27025 12 4.24799C10.8778 6.27025 10.1811 8.56122 10.0307 11ZM14.29 4.33256C15.2526 6.37407 15.8427 8.62577 15.9727 11H19.9381C19.542 7.8235 17.2836 5.22533 14.29 4.33256Z"/></svg>';

// SweetAlert2 paints its buttons with inline colours, so read the admin tokens at call time
// (app.css, the brand from its one table) instead of copying their hex: a palette change then
// reaches the dialog too. No brand fallback: an unset token leaves SweetAlert2's own colour.
function adminColor(token, fallback = undefined) {
    return getComputedStyle(document.documentElement).getPropertyValue(token).trim() || fallback;
}

/**
 * @param {object} opts
 * @param {'warning'|'trash'|'trash-danger'|'logout'|'publish'} [opts.icon] warning "!" (default), a trash bin
 *   in an amber circle (reversible soft-delete), a trash bin in a red circle (irreversible purge), the
 *   sign-out arrow in a red circle, or the globe in a green circle (a post goes live on the website).
 * @param {boolean} [opts.focusCancel] open focused on Cancel; by default only a destructive dialog does, so
 *   a question whose second Enter must not make the change (a shortcut opened it) asks for it.
 */
async function bsConfirm(opts = {}) {
    const { default: Swal } = await import('sweetalert2');
    const danger = opts.danger !== false; // default: destructive styling
    const isTrash = typeof opts.icon === 'string' && opts.icon.startsWith('trash');

    const result = await Swal.fire({
        title: opts.title ?? '',
        text: opts.text ?? '',
        // A trash bin instead of "!"; the circle stays amber (reversible) or turns red (permanent).
        icon: opts.icon === 'trash-danger' || opts.icon === 'logout' ? 'error' : (opts.icon === 'publish' ? 'success' : 'warning'),
        iconHtml: isTrash ? BIN_SVG : (opts.icon === 'logout' ? LOGOUT_SVG : (opts.icon === 'publish' ? PUBLISH_SVG : undefined)),
        iconColor: opts.icon === 'publish' ? adminColor('--color-success-text') : undefined,
        showCancelButton: true,
        confirmButtonText: opts.confirm ?? 'OK',
        cancelButtonText: opts.cancel ?? 'Cancel',
        confirmButtonColor: danger ? adminColor('--color-danger-base', '#f06548') : adminColor('--color-primary'),
        cancelButtonColor: adminColor('--color-text-faint', '#adb5bd'),
        reverseButtons: true, // Cancel on the left, primary action on the right (platform norm)
        focusCancel: opts.focusCancel ?? danger, // a destructive dialog opens focused on Cancel, not the delete button
    });

    return result.isConfirmed;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.magic('bsConfirm', () => bsConfirm);
});

window.bsConfirm = bsConfirm;
