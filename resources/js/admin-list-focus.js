// Where the keyboard focus goes after a row's action on a shared list (window.bsListFocus), as design board 05
// does it: after a pick from a row's ⋮ (Sao chép đường dẫn, Nhân bản, Thùng rác, Xem…) back to that row's ⋮, or,
// when the row is gone (moved to the Trash), to «Hoàn tác» on the message; after a status change back to the
// row's status button (its ⋮ when there is none). The list redraws the row on the server's answer, so the place
// is found again once that answer is drawn. A dialog that is open keeps the focus, and so does anything the
// member put the focus on meanwhile. Rows carry data-rk (their key).

const WAIT_MS = 15000;
let pending = null;

const shown = (el) => !!el && el.isConnected && el.getClientRects().length > 0;

/**
 * The element to focus for a pending return, or null when the focus should stay where it is.
 * @param {Document} doc
 * @param {{ rk: string, kind: 'more'|'st' }} want
 * @param {(el: Element|null) => boolean} [isShown]
 * @returns {HTMLElement|null|false} the element; null: not yet (try again shortly); false: leave the focus alone
 */
export function backTarget(doc, want, isShown = shown) {
    // a dialog (or a confirm) open or still closing: wait for it; it gives the focus back itself
    if ([...doc.querySelectorAll('[aria-modal="true"], .swal2-container')].some(isShown)) {
        return null;
    }
    const a = doc.activeElement;
    if (a && a !== doc.body && isShown(a) && ! a.closest('[role=menu]')) {
        return false;
    }
    const row = doc.querySelector('[data-rk="' + String(want.rk).replace(/"/g, '') + '"]');
    const more = row?.querySelector('[data-guide=row-more]');
    if (want.kind === 'st') {
        const pill = row ? [...row.querySelectorAll('[data-guide=row-status] button')].find(isShown) : null;
        if (pill) {
            return pill;
        }
    }
    if (isShown(more)) {
        return more;
    }
    const undo = [...doc.querySelectorAll('[data-toast-undo]')].find(isShown);

    return undo ?? null;
}

function settle(tries = 0) {
    if (! pending || Date.now() - pending.at > WAIT_MS) {
        pending = null;
        return;
    }
    const t = backTarget(document, pending);
    if (t === false) {
        pending = null;
        return;
    }
    if (t) {
        pending = null;
        try { t.focus({ preventScroll: true }); } catch (_) { t.focus(); }
        return;
    }
    if (tries < 12) {
        setTimeout(() => settle(tries + 1), tries < 3 ? 0 : 60);
    } else {
        pending = null;
    }
}

/**
 * Ask for the focus to come back to row `rk` once the list has been drawn again (`now`: the answer is already
 * drawn, as after a dialog that waited for it, so look at once).
 */
function expect(rk, kind = 'more', now = false) {
    hook();
    pending = { rk, kind, at: Date.now() };
    if (now) {
        setTimeout(() => settle(), 0);
    }
}

document.addEventListener('click', (e) => {
    const t = e.target instanceof Element ? e.target : null;
    const row = t?.closest('[data-rk]');
    if (! row) {
        return;
    }
    if (t.closest('[role=menu]') && t.closest('.list-more')) {
        expect(row.dataset.rk, 'more');
    } else if (t.closest('[data-guide=row-status] .list-mi')) {
        expect(row.dataset.rk, 'st');
    }
}, true);

// once per page: after each answer of the server is drawn, a pending return is looked at
let hooked = false;
function hook() {
    if (hooked || typeof window.Livewire?.interceptMessage !== 'function') {
        return;
    }
    hooked = true;
    window.Livewire.interceptMessage(({ onFinish }) => onFinish(() => { if (pending) { setTimeout(() => settle(), 0); } }));
}
document.addEventListener('livewire:init', hook);

window.bsListFocus = { expect };
