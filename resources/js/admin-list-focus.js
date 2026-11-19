// Where the keyboard focus goes after a row's action on a shared list (window.bsListFocus), as design board 05
// does it: after a pick from a row's ⋮ (Sao chép đường dẫn, Nhân bản, Thùng rác, Xem…) back to that row's ⋮, or,
// when the row is gone (moved to the Trash), to «Hoàn tác» on the message; after a status change back to the
// row's status button (its ⋮ when there is none). The list redraws the row on the server's answer, so the place
// is found again once that answer is drawn. A dialog that is open keeps the focus, and so does anything the
// member put the focus on meanwhile. Rows carry data-rk (their key).

const WAIT_MS = 15000;
let pending = null;

// After a move to the Trash the rows under it move up, so the second click of a double click (or a quick
// repeat) would land on the «Thùng rác» of the row that took its place. As board 05 does, a trash button
// (data-trash: the row's button and its ⋮ item) ignores clicks while its move is on the way to the server
// and for TRASH_HOLD_MS after the list is drawn again.
export const TRASH_HOLD_MS = 600;
let trashSent = 0;   // when the click that waits for the server's answer was made (0: none waits)
let trashUntil = 0;  // until then a trash button ignores clicks

/** Whether a trash button ignores a click now. */
export function trashHeld(now = Date.now()) {
    if (trashSent && now - trashSent > WAIT_MS) {
        trashSent = 0;
    }
    return trashSent !== 0 || now < trashUntil;
}

/** The server's answer to a move to the Trash is drawn: the hold runs on for TRASH_HOLD_MS. */
export function trashDrawn(now = Date.now()) {
    if (trashSent) {
        trashSent = 0;
        trashUntil = now + TRASH_HOLD_MS;
    }
}

// Only the answer that carries the move starts the 600 ms: another answer finishing first (a search, a
// status change sent just before) leaves the hold on. A Livewire whose message does not list its calls
// counts every answer, as before.
export function carriesTrash(message) {
    const calls = typeof message?.getActions === 'function' ? message.getActions() : null;

    return calls === null || calls.some((a) => a?.name === 'trash');
}

// registered before the other listeners on the page, so a held click reaches nothing else (Livewire, the menu)
document.addEventListener('click', (e) => {
    const t = e.target instanceof Element ? e.target.closest('[data-trash]') : null;
    if (! t) {
        return;
    }
    if (trashHeld()) {
        e.preventDefault();
        e.stopImmediatePropagation();
        return;
    }
    hook();
    trashSent = Date.now();
}, true);

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
    window.Livewire.interceptMessage(({ message, onFinish }) => onFinish(() => {
        if (carriesTrash(message)) {
            trashDrawn();
        }
        if (pending) {
            setTimeout(() => settle(), 0);
        }
    }));
}
document.addEventListener('livewire:init', hook);

// A list menu's keys and its opening (<x-bs-admin::list.menu>, the ⋮ of <x-bs-admin::list.actions>): the
// arrows (wrapping), Home and End move between the items that show, and the menu opens on its first one.
// An item the row already shows as its own button (data-xm, e.g. «Xem trên website» on a page) is hidden
// in the menu; Alpine's $focus counts hidden items too, so a move onto one went nowhere, and the menu of
// such a row (every published page, in the page tree too) opened with the focus left outside it.
const ITEMS = '[role=menuitem],[role=menuitemradio],[role=menuitemcheckbox]';

/**
 * Focus an item of `menu`: 'first', 'last', or the 'next' / 'prev' one from the focused item, wrapping.
 * @returns {HTMLElement|null} the item focused, or null when the menu shows none
 */
export function menuMove(menu, to, isShown = shown) {
    const items = menu ? [...menu.querySelectorAll(ITEMS)].filter(isShown) : [];
    if (items.length === 0) {
        return null;
    }
    const at = items.findIndex((el) => el === document.activeElement || el.contains(document.activeElement));
    const step = to === 'prev' ? -1 : 1;
    const t = to === 'first' || (to === 'next' && at < 0) ? items[0]
        : to === 'last' || (to === 'prev' && at < 0) ? items[items.length - 1]
            : items[(at + step + items.length) % items.length];
    try { t.focus({ preventScroll: false }); } catch (_) { t.focus(); }

    return t;
}

window.bsListFocus = { expect };
window.bsMenu = { move: (menu, to) => menuMove(menu, to) };
