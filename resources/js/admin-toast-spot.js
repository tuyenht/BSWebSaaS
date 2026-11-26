// Where a toast stands while a dialog is open (x-bs-admin::toast; window.bsToastSpot). A toast is drawn
// over every layer of the admin, so at its usual spot (the bottom centre) it can cover a dialog's own
// buttons — on a phone a question is a sheet from the bottom whose «Ở lại» sits exactly there, and a tap
// on it reached the toast. While a toast shows, it is placed clear of the dialog on top. It moves only
// when, at its usual spot, it really lies over one of the dialog's controls (a button, a field, a link
// that a tap there would reach, by more than a sliver); then, in this order:
//   1. just above the dialog's visible box (8px gap), when it fits below the top of the screen;
//   2. beside the box, centred in the free side, when that side is at least 280px wide (a side panel);
//   3. over a working panel (a drawer, [data-toast-keep]): just above the panel's footer buttons
//      ([data-toast-foot]) when it fits below the panel's header, else where it is — its timer runs as usual;
//   4. otherwise under the dialog's layer and its dim (the lower of the two, minus one), its timer held,
//      until the dialog closes.
// The visible box is the card a full-screen dialog layer holds (a dim with the card inside), not the layer.
// The message was read out when it came (the live region), so a toast under a dialog loses nothing.
// A toast watches only while it is on the screen (its x-init / destroy call watch / unwatch).

const GAP = 8;
const MIN_SIDE = 280;
const MIN_OVER = 4; // a sliver thinner than this (a rounding pixel at an edge) is not lying over a control
const DIALOG = '[aria-modal="true"], [data-bs-modal]';
const CONTROL = 'button, a[href], input:not([type="hidden"]), select, textarea, summary, label, [role="button"], [role="link"], [role="checkbox"], [role="radio"], [role="switch"], [role="tab"], [role="menuitem"], [role="option"], [contenteditable="true"], [tabindex]:not([tabindex="-1"])';

/** @typedef {{ top: number, bottom: number, left: number, right: number }} Rect */

const touches = (a, b) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;

/**
 * The spot for a toast whose box, at its usual spot, is `toast` (a rect), on a vw × vh screen, with the top
 * dialog's visible box `box` (null: none open). `hits`: the toast lies over one of the dialog's controls
 * (left out: whenever it touches the box). `keep`: the dialog is a working panel (a drawer), whose footer
 * `foot` and header `head` (rects, or null) bound where the toast may stand over it.
 * @param {{ vw: number, vh: number, toast: Rect, box: Rect|null, hits?: boolean, keep?: boolean, foot?: Rect|null, head?: Rect|null }} at
 * @returns {{ mode: 'def'|'under'|'keep' } | { mode: 'up', bottom: number } | { mode: 'side', left: number, width: number }}
 */
export function toastSpot({ vw, vh, toast, box, hits, keep = false, foot = null, head = null }) {
    if (!box || !(hits ?? touches(toast, box))) {
        return { mode: 'def' };
    }
    const h = toast.bottom - toast.top;
    if (box.top - GAP - h >= GAP) {
        return { mode: 'up', bottom: Math.ceil(vh - box.top + GAP) };
    }
    const before = box.left;
    const after = vw - box.right;
    const free = Math.max(before, after);
    if (free >= MIN_SIDE + 2 * GAP) {
        const width = Math.min(toast.right - toast.left, free - 2 * GAP);
        const centre = before >= after ? before / 2 : box.right + after / 2;

        return { mode: 'side', left: Math.round(centre), width: Math.floor(width) };
    }
    if (keep) {
        if (foot && touches(toast, foot) && foot.top - GAP - h >= Math.max(GAP, head ? head.bottom + GAP : GAP)) {
            return { mode: 'up', bottom: Math.ceil(vh - foot.top + GAP) };
        }

        return { mode: 'keep' };
    }

    return { mode: 'under' };
}

const shown = (el) => !!el && el.getClientRects().length > 0;
const rectOf = (el) => {
    const r = el.getBoundingClientRect();

    return { top: r.top, bottom: r.bottom, left: r.left, right: r.right };
};
const fills = (r, vw, vh) => r.right - r.left >= vw - 1 && r.bottom - r.top >= vh - 1;
const union = (a, b) => ({ top: Math.min(a.top, b.top), bottom: Math.max(a.bottom, b.bottom), left: Math.min(a.left, b.left), right: Math.max(a.right, b.right) });

const watched = new Map(); // toast root → how many of its boxes are on the screen
const inToast = (el) => [...watched.keys()].some((root) => root.contains(el));
// the element a tap at (x, y) reaches, the toasts left out
const reached = (doc, x, y) => (doc.elementsFromPoint ? doc.elementsFromPoint(x, y) : [doc.elementFromPoint(x, y)]).find((e) => e && !inToast(e)) || null;

/** The open dialog drawn on top (the one its own centre reaches), or null. */
export function topDialog(doc = document) {
    const open = [...doc.querySelectorAll('[aria-modal="true"]')].filter(shown);
    if (open.length < 2) {
        return open[0] || null;
    }
    const onTop = open.filter((d) => {
        const r = d.getBoundingClientRect();
        const hit = reached(doc, r.left + r.width / 2, r.top + Math.min(r.height / 2, 24));

        return !!hit && d.contains(hit);
    });
    const list = onTop.length ? onTop : open;

    return list[list.length - 1];
}

/**
 * What a dialog draws on a vw × vh screen: its own box, or, when it is a full-screen layer (a dim with the
 * card inside, aria-modal on the layer), the union of what it holds, a full-screen dim left out.
 * @returns {{ rect: Rect, els: Element[] }}
 */
export function dialogCard(dlg, vw, vh, depth = 0) {
    const rect = rectOf(dlg);
    if (!fills(rect, vw, vh) || depth > 2) {
        return { rect, els: [dlg] };
    }
    let u = null;
    const els = [];
    for (const c of dlg.children) {
        if (!shown(c)) {
            continue;
        }
        const part = fills(rectOf(c), vw, vh) ? (c.children.length ? dialogCard(c, vw, vh, depth + 1) : null) : { rect: rectOf(c), els: [c] };
        if (part) {
            u = u ? union(u, part.rect) : part.rect;
            els.push(...part.els);
        }
    }

    return u ? { rect: u, els } : { rect, els: [dlg] };
}

/** Whether the toast's box `t` lies over a control of `dlg` that a tap there would reach. */
export function coversControl(doc, dlg, t) {
    for (const c of dlg.querySelectorAll(CONTROL)) {
        const r = c.getBoundingClientRect();
        const left = Math.max(t.left, r.left);
        const right = Math.min(t.right, r.right);
        const top = Math.max(t.top, r.top);
        const bottom = Math.min(t.bottom, r.bottom);
        if (right - left < MIN_OVER || bottom - top < MIN_OVER) {
            continue;
        }
        const x = (left + right) / 2;
        const y = (top + bottom) / 2;
        const hit = reached(doc, x, y);
        if (hit && (hit === c || c.contains(hit))) {
            return true;
        }
    }

    return false;
}

const zOf = (win, el) => {
    const cs = win.getComputedStyle(el);

    return cs.position !== 'static' && /^-?\d+$/.test(cs.zIndex) ? parseInt(cs.zIndex, 10) : null;
};

/**
 * The z-index a toast takes to go under `dlg`: one below the dialog's layer (its outermost positioned
 * ancestor with a z-index), or below a full-screen dim drawn beside that layer when the dim is lower.
 */
export function underZ(dlg, win, vw, vh) {
    let layer = null;
    let z = null;
    for (let el = dlg; el && el.parentElement; el = el.parentElement) {
        const own = zOf(win, el);
        if (own !== null) {
            layer = el;
            z = own;
        }
    }
    if (layer === null) {
        return 0;
    }
    for (const s of layer.parentElement.children) {
        if (s === layer || !shown(s) || inToast(s)) {
            continue;
        }
        const sz = zOf(win, s);
        if (sz !== null && sz < z && fills(rectOf(s), vw, vh)) {
            z = sz;
        }
    }

    return z - 1;
}

let mo = null;
let ro = null;
let observed = null;
let sized = [];
let frame = 0;

function reset(root) {
    root.style.removeProperty('--toast-over');
    root.style.removeProperty('left');
    root.style.removeProperty('width');
    root.style.removeProperty('z-index');
}

function hold(root, on) {
    root.querySelectorAll('[data-tone]').forEach((box) => {
        const d = box._x_dataStack && box._x_dataStack[0];
        if (d && typeof d.hold === 'function') {
            on ? d.hold() : d.release();
        }
    });
}

// the dialog's visible box changing size (a message shown inside it, a field growing) re-places the toast
function track(els) {
    if (!ro || (els.length === sized.length && els.every((el, i) => el === sized[i]))) {
        return;
    }
    ro.disconnect();
    sized = els;
    els.forEach((el) => ro.observe(el));
}

function place(root) {
    const doc = root.ownerDocument;
    const win = doc.defaultView;
    const was = root.getAttribute('data-spot') || 'def';
    reset(root);
    const box = [...root.querySelectorAll('[data-tone]')].find(shown);
    let dlg = box ? topDialog(doc) : null;
    if (dlg && dlg.contains(root)) {
        dlg = null; // a toast inside the dialog is drawn with it
    }
    const vw = win.innerWidth;
    const vh = win.innerHeight;
    let spot = { mode: 'def' };
    if (dlg) {
        const t = rectOf(box);
        const card = dialogCard(dlg, vw, vh);
        track(card.els);
        const keep = !!dlg.closest('[data-toast-keep]');
        const foot = keep ? dlg.querySelector('[data-toast-foot]') : null;
        const head = keep ? [...dlg.children].find(shown) : null;
        spot = toastSpot({
            vw, vh, toast: t, box: card.rect, keep,
            hits: touches(t, card.rect) && coversControl(doc, dlg, t),
            foot: shown(foot) ? rectOf(foot) : null,
            head: head && head !== foot ? rectOf(head) : null,
        });
    } else {
        track([]);
    }
    if (spot.mode === 'up') {
        root.style.setProperty('--toast-over', spot.bottom + 'px');
    } else if (spot.mode === 'side') {
        root.style.left = spot.left + 'px';
        root.style.width = spot.width + 'px';
    } else if (spot.mode === 'under') {
        const z = Math.max(0, underZ(dlg, win, vw, vh));
        const own = zOf(win, root);
        if (own === null || z < own) {
            root.style.zIndex = String(z);
        }
    }
    root.setAttribute('data-spot', spot.mode);
    // under a dialog its timer waits (a new message arriving meanwhile waits too); back on top it runs on
    if (spot.mode === 'under') {
        hold(root, true);
    } else if (was === 'under') {
        hold(root, false);
    }
}

// a node that is a dialog or holds one (its opening, closing or moving can change where the toast stands)
const holdsDialog = (n) => n.nodeType === 1 && (n.matches(DIALOG) || !!n.querySelector(DIALOG));

function changed(records) {
    for (const r of records) {
        if (inToast(r.target)) {
            continue; // the toast's own changes (its spot, its text) do not move a dialog
        }
        if (r.type === 'childList' ? [...r.addedNodes, ...r.removedNodes].some(holdsDialog) : r.attributeName === 'aria-modal' || holdsDialog(r.target)) {
            schedule();

            return;
        }
    }
}

function start() {
    observed = document.body;
    mo = new MutationObserver(changed);
    mo.observe(observed, { subtree: true, childList: true, attributes: true, attributeFilter: ['style', 'class', 'hidden', 'open', 'aria-modal'] });
    ro = typeof ResizeObserver === 'function' ? new ResizeObserver(() => schedule()) : null;
    sized = [];
    window.addEventListener('resize', schedule);
    document.addEventListener('transitionend', schedule, true);
}

function stop() {
    mo?.disconnect();
    ro?.disconnect();
    mo = null;
    ro = null;
    observed = null;
    sized = [];
    window.removeEventListener('resize', schedule);
    document.removeEventListener('transitionend', schedule, true);
}

// watching the body that is on the page now (wire:navigate swaps it)
function ensure() {
    if (watched.size && (!mo || observed !== document.body)) {
        stop();
        start();
    }
}

// one placement per frame, however many changes came in it
function schedule() {
    if (frame) {
        return;
    }
    frame = requestAnimationFrame(() => {
        frame = 0;
        [...watched.keys()].forEach((root) => root.isConnected || watched.delete(root));
        if (!watched.size) {
            stop();

            return;
        }
        ensure();
        watched.forEach((_, root) => place(root));
    });
}

/** A toast box came on the screen inside `root` (the toast's fixed wrapper). */
export function watch(root) {
    if (!root) {
        return;
    }
    watched.set(root, (watched.get(root) || 0) + 1);
    ensure();
    schedule();
}

/** A toast box left the screen. */
export function unwatch(root) {
    const n = (watched.get(root) || 0) - 1;
    if (n > 0) {
        watched.set(root, n);

        return;
    }
    watched.delete(root);
    if (root) {
        reset(root);
        root.removeAttribute('data-spot');
    }
    schedule();
}

if (typeof window !== 'undefined') {
    window.bsToastSpot = { watch, unwatch };
    // leaving the page: let go of its body; the next page's toasts (or the next frame) watch the new one
    document.addEventListener('livewire:navigating', stop);
    document.addEventListener('livewire:navigated', () => watched.size && schedule());
}
