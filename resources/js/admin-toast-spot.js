// Where a toast stands while a dialog is open (x-bs-admin::toast; window.bsToastSpot). A toast is drawn
// over every layer of the admin, so at its usual spot (the bottom centre) it can cover a dialog's own
// buttons — on a phone a question is a sheet from the bottom whose «Ở lại» sits exactly there, and a tap
// on it reached the toast. While a toast shows, it is placed clear of the dialog on top, in this order:
//   1. its usual spot, when that does not touch the dialog;
//   2. just above the dialog (8px gap), when it fits below the top of the screen;
//   3. beside the dialog, centred in the free side, when that side is at least 280px wide (a side panel);
//   4. otherwise under the dialog's dimmed layer, its timer held, until the dialog closes — except over a
//      working panel (a drawer, [data-toast-keep]), where its own messages stay in sight at the usual spot.
// The message was read out when it came (the live region), so a toast under a dialog loses nothing.
// A toast watches only while it is on the screen (its x-init / destroy call watch / unwatch).

const GAP = 8;
const MIN_SIDE = 280;

/**
 * The spot for a toast whose box, at its usual spot, is `toast` (a rect), on a vw × vh screen, with the top
 * dialog's box `box` (null: none open). `keep`: the dialog is a working panel (a drawer).
 * @param {{ vw: number, vh: number, toast: Rect, box: Rect|null, keep?: boolean }} at
 * @returns {{ mode: 'def'|'under'|'keep' } | { mode: 'up', bottom: number } | { mode: 'side', left: number, width: number }}
 * @typedef {{ top: number, bottom: number, left: number, right: number }} Rect
 */
export function toastSpot({ vw, vh, toast, box, keep = false }) {
    if (!box) {
        return { mode: 'def' };
    }
    const touches = toast.left < box.right && box.left < toast.right && toast.top < box.bottom && box.top < toast.bottom;
    if (!touches) {
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

    return { mode: keep ? 'keep' : 'under' };
}

const shown = (el) => !!el && el.getClientRects().length > 0;

/** The open dialog drawn on top (the one its own centre reaches), or null. */
export function topDialog(doc = document) {
    const open = [...doc.querySelectorAll('[aria-modal="true"]')].filter(shown);
    if (open.length < 2) {
        return open[0] || null;
    }
    const onTop = open.filter((d) => {
        const r = d.getBoundingClientRect();
        const hit = doc.elementFromPoint(r.left + r.width / 2, r.top + Math.min(r.height / 2, 24));

        return !!hit && d.contains(hit);
    });

    return (onTop.length ? onTop : open)[(onTop.length ? onTop : open).length - 1];
}

const watched = new Map(); // toast root → how many of its boxes are on the screen
let mo = null;
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

function place(root) {
    const was = root.getAttribute('data-spot') || 'def';
    reset(root);
    const box = [...root.querySelectorAll('[data-tone]')].find(shown);
    const dlg = box ? topDialog(root.ownerDocument) : null;
    let spot = { mode: 'def' };
    if (dlg) {
        const t = box.getBoundingClientRect();
        const b = dlg.getBoundingClientRect();
        const win = root.ownerDocument.defaultView;
        spot = toastSpot({
            vw: win.innerWidth, vh: win.innerHeight,
            toast: { top: t.top, bottom: t.bottom, left: t.left, right: t.right },
            box: { top: b.top, bottom: b.bottom, left: b.left, right: b.right },
            keep: !!dlg.closest('[data-toast-keep]'),
        });
    }
    if (spot.mode === 'up') {
        root.style.setProperty('--toast-over', spot.bottom + 'px');
    } else if (spot.mode === 'side') {
        root.style.left = spot.left + 'px';
        root.style.width = spot.width + 'px';
    } else if (spot.mode === 'under') {
        root.style.zIndex = '55';
    }
    root.setAttribute('data-spot', spot.mode);
    // under a dialog its timer waits (a new message arriving meanwhile waits too); back on top it runs on
    if (spot.mode === 'under') {
        hold(root, true);
    } else if (was === 'under') {
        hold(root, false);
    }
}

function schedule() {
    if (frame) {
        return;
    }
    frame = requestAnimationFrame(() => {
        frame = 0;
        watched.forEach((_, root) => (root.isConnected ? place(root) : watched.delete(root)));
        if (!watched.size) {
            stop();
        }
    });
}

function stop() {
    mo?.disconnect();
    mo = null;
    window.removeEventListener('resize', schedule);
    document.removeEventListener('transitionend', schedule, true);
}

/** A toast box came on the screen inside `root` (the toast's fixed wrapper). */
export function watch(root) {
    if (!root) {
        return;
    }
    watched.set(root, (watched.get(root) || 0) + 1);
    if (!mo) {
        // a dialog opening or closing changes a style / class / hidden somewhere, or adds its nodes
        mo = new MutationObserver((records) => {
            // the toast's own changes (its spot, its text) do not move a dialog
            if (records.some((r) => ![...watched.keys()].some((root) => root.contains(r.target)))) {
                schedule();
            }
        });
        mo.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['style', 'class', 'hidden', 'aria-modal'] });
        window.addEventListener('resize', schedule);
        document.addEventListener('transitionend', schedule, true);
    }
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
}
