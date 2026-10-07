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

// SweetAlert2 paints its buttons with inline colours, so read the admin tokens at call time
// (app.css) instead of copying their hex: a palette change then reaches the dialog too.
function adminColor(token, fallback) {
    return getComputedStyle(document.documentElement).getPropertyValue(token).trim() || fallback;
}

/**
 * @param {object} opts
 * @param {'warning'|'trash'|'trash-danger'} [opts.icon] warning "!" (default), a trash bin in an
 *   amber circle (reversible soft-delete), or a trash bin in a red circle (irreversible purge).
 */
async function bsConfirm(opts = {}) {
    const { default: Swal } = await import('sweetalert2');
    const danger = opts.danger !== false; // default: destructive styling
    const isTrash = typeof opts.icon === 'string' && opts.icon.startsWith('trash');

    const result = await Swal.fire({
        title: opts.title ?? '',
        text: opts.text ?? '',
        // A trash bin instead of "!"; the circle stays amber (reversible) or turns red (permanent).
        icon: opts.icon === 'trash-danger' ? 'error' : 'warning',
        iconHtml: isTrash ? BIN_SVG : undefined,
        showCancelButton: true,
        confirmButtonText: opts.confirm ?? 'OK',
        cancelButtonText: opts.cancel ?? 'Cancel',
        confirmButtonColor: adminColor(danger ? '--color-danger-base' : '--color-primary', danger ? '#f06548' : '#405189'),
        cancelButtonColor: adminColor('--color-text-faint', '#adb5bd'),
        reverseButtons: true, // Cancel on the left, primary action on the right (platform norm)
        focusCancel: danger, // a destructive dialog opens focused on Cancel, not the delete button
    });

    return result.isConfirmed;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.magic('bsConfirm', () => bsConfirm);
});

window.bsConfirm = bsConfirm;
