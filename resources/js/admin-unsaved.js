// The shared "Bạn có thay đổi chưa lưu" dialog (<x-bs-admin::unsaved-dialog>; design 01, the one
// blocking dialog for unsaved changes), Alpine.data 'bsUnsaved', started by the shared lazy loader
// (admin-lazy.js). A page that keeps its draft on the server joins by passing its change count; the
// dialog then stands between that draft and every way out of the page:
//  - closing the tab or reloading asks the browser's own question (guardLeave);
//  - a wire:navigate link asks this dialog. "Lưu và <đi>" runs the page's own save and goes on only
//    when the save left no field in error and nothing unsaved; otherwise the dialog closes, the
//    page shows its errors and the typed text stays. "Ở lại" (also Escape and the backdrop) keeps
//    the page and puts the focus back. "Bỏ thay đổi và <đi>" drops the draft through the page's
//    discard method, then goes;
//  - a link to another settings section says "sang mục khác", any other link "rời đi"; a link to
//    the section already open does nothing. Back and forward through the history pass.
// Text typed into a field that reaches the server only when the field is left (wire:model.blur)
// counts as a change until a round trip has carried it. Before asking, the field is left and the
// draft sent, so the dialog opens only when the server's count says something is unsaved.

import { guardLeave } from './admin-leave.js';

/** The wire:model attribute of a form field, or null. */
function modelOf(el) {
    if (!el || el.nodeType !== 1 || !/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) {
        return null;
    }
    const a = Array.from(el.attributes).find((x) => x.name.startsWith('wire:model'));

    return a ? a.name : null;
}

/**
 * One row when the three labels fit side by side, each on one line, with 12px before the main
 * button and at least 32px setting the destructive one apart; otherwise stacked. Null when there
 * is no width to measure yet (the dialog still hidden).
 */
export function layoutOf(avail, widths) {
    if (!(avail > 0)) {
        return null;
    }
    const sum = widths.reduce((a, w) => a + Math.ceil(w) + 1, 0);

    return avail >= sum + 12 * (widths.length - 2) + 32 ? 'row' : 'col';
}

// The order of the buttons in the DOM is the order on screen in each layout (no CSS order):
// row = Bỏ thay đổi · Ở lại · Lưu, stacked = Lưu → Ở lại → (a wider gap) → Bỏ thay đổi.
export const ORDER = { row: ['x', 's', 'p'], col: ['p', 's', 'x'] };

let ctx = null;

/** The words a button shows now (its x-show variant for this way out). */
function labelOf(b) {
    const parts = Array.from(b.querySelectorAll('[data-ud-t]')).filter((el) => el.style.display !== 'none');

    return (parts.length ? parts.map((el) => el.textContent).join(' ') : b.textContent).trim();
}

/** Measure the dialog's buttons and lay the foot out as one row or stacked. */
export function fitFoot(foot) {
    if (!foot || !foot.isConnected) {
        return;
    }
    const w = foot.ownerDocument.defaultView;
    const box = foot.closest('.sx-ud') || foot.parentElement;
    const cs = w.getComputedStyle(box);
    const avail = box.clientWidth - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0);
    const btn = { p: foot.querySelector('[data-ud="p"]'), s: foot.querySelector('[data-ud="s"]'), x: foot.querySelector('[data-ud="x"]') };
    if (!btn.p || !btn.s || !btn.x) {
        return;
    }
    if (!ctx) {
        const canvas = w.document.createElement('canvas');
        ctx = typeof canvas.getContext === 'function' ? canvas.getContext('2d') : null;
    }
    if (!ctx) {
        return;
    }
    const widths = ['p', 's', 'x'].map((k) => {
        const b = btn[k];
        const s = w.getComputedStyle(b);
        ctx.font = `${s.fontStyle} ${s.fontWeight} ${s.fontSize} ${s.fontFamily}`;
        let t = ctx.measureText(labelOf(b)).width;
        t += (parseFloat(s.paddingLeft) || 0) + (parseFloat(s.paddingRight) || 0) + (parseFloat(s.borderLeftWidth) || 0) + (parseFloat(s.borderRightWidth) || 0);
        const icon = b.querySelector('svg');
        if (icon) {
            t += icon.getBoundingClientRect().width + (parseFloat(s.columnGap) || 8);
        }

        return t;
    });
    const lay = layoutOf(avail, widths);
    if (!lay || foot.getAttribute('data-ud-lay') === lay) {
        return;
    }
    const active = w.document.activeElement;
    const keep = active && foot.contains(active) ? active : null;
    foot.setAttribute('data-ud-lay', lay);
    ORDER[lay].forEach((k) => foot.appendChild(btn[k]));
    if (keep) {
        keep.focus();
    }
}

/**
 * @param {{ save: string, discard: string, sections: string[] }} cfg the page's save and discard
 *   methods, and the paths of the settings sections ("sang mục khác" instead of "rời đi").
 */
export function unsavedDialog(cfg) {
    let off = null;
    let offHook = null;
    let ro = null;
    let page = null;
    let seq = 0;
    let typed = false;
    let typedField = null;
    let root = null;
    // Typed text the server has not seen yet is also shown on the dialog's root (data-typed), so the
    // page's CSS can treat the page as changed before the round trip (a settings save bar on a phone).
    const setTyped = (on) => {
        typed = on;
        if (root) {
            root.toggleAttribute('data-typed', on);
        }
    };
    let go = null;
    let opener = null;
    let asking = false;

    // A key typed into a wire:model field: changed until a round trip has carried it. A .blur
    // field only sends when it is left, so it stays changed while it holds the focus.
    const onInput = (e) => {
        const m = modelOf(e.target);
        if (!m) {
            return;
        }
        seq += 1;
        setTyped(true);
        if (/\.blur\b/.test(m)) {
            typedField = e.target;
        }
    };
    const onFieldLeft = (e) => {
        if (e.target === typedField) {
            typedField = null;
        }
    };
    let overflow = '';
    const lock = () => {
        overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    };
    const unlock = () => {
        document.body.style.overflow = overflow;
    };

    return {
        open: false,
        section: false,
        busy: false,

        init() {
            root = this.$root;
            page = this.$root.closest('[wire\\:id]') || document.body;
            page.addEventListener('input', onInput, true);
            page.addEventListener('focusout', onFieldLeft, true);
            const id = this.$wire ? this.$wire.$id : null;
            const lw = window.Livewire;
            if (lw && typeof lw.hook === 'function') {
                offHook = lw.hook('commit', ({ component, succeed }) => {
                    if (!component || component.id !== id) {
                        return;
                    }
                    const at = seq;
                    succeed(() => {
                        if (at === seq && typedField === null) {
                            setTyped(false);
                        }
                    });
                });
            }
            off = guardLeave({ dirty: () => this.dirty(), ask: (goOn, url) => this.ask(goOn, url) });
        },

        destroy() {
            if (off) {
                off();
            }
            if (typeof offHook === 'function') {
                offHook();
            }
            if (page) {
                page.removeEventListener('input', onInput, true);
                page.removeEventListener('focusout', onFieldLeft, true);
            }
            if (ro) {
                ro.disconnect();
            }
            if (this.open) {
                unlock();
            }
        },

        /** The page's count of unsaved changes, as the server last drew it. */
        count() {
            const el = this.$refs.count;

            return el ? Number(el.getAttribute('data-unsaved-count')) || 0 : 0;
        },

        dirty() {
            return this.count() > 0 || typed;
        },

        async ask(goOn, url) {
            if (this.open || asking) {
                return;
            }
            const target = url ? new URL(url, window.location.href) : null;
            if (target && target.pathname === window.location.pathname && target.search === window.location.search) {
                return; // the section already open: nothing to change
            }
            const from = document.activeElement;
            if (this.count() === 0) {
                // Only typed text the server has not seen: send it, then let the count decide. A
                // send that fails (no network, an expired session) asks instead: the text stays.
                asking = true;
                const sent = await this.flush();
                asking = false;
                if (sent && this.count() === 0) {
                    setTyped(false);
                    goOn();

                    return;
                }
            }
            opener = from && from !== document.body ? from : null;
            go = goOn;
            this.section = !!target && (cfg.sections || []).includes(target.pathname);
            this.open = true;
            lock();
            this.$nextTick(() => this.shown());
        },

        /** Leave the field being typed in (a .blur field sends then) and send the draft; false when the send failed. */
        async flush() {
            const a = document.activeElement;
            if (a && page && page.contains(a) && modelOf(a)) {
                a.blur();
            }
            try {
                await this.$wire.$commit();

                return true;
            } catch {
                return false;
            }
        },

        shown() {
            const foot = this.$refs.foot;
            if (!foot || !this.open) {
                return;
            }
            fitFoot(foot);
            if (!ro && window.ResizeObserver) {
                ro = new window.ResizeObserver(() => fitFoot(foot));
                ro.observe(foot.closest('.sx-ud') || foot);
            }
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(() => fitFoot(foot));
            }
            const stay = foot.querySelector('[data-ud="s"]');
            if (stay) {
                stay.focus();
            }
        },

        close(back) {
            const o = opener;
            this.open = false;
            go = null;
            opener = null;
            if (ro) {
                ro.disconnect();
                ro = null;
            }
            unlock();
            if (back && o && o.isConnected) {
                o.focus();
            }
        },

        stay() {
            if (!this.busy) {
                this.close(true);
            }
        },

        async saveGo() {
            if (this.busy) {
                return;
            }
            this.busy = true;
            let sent = true;
            try {
                await this.$wire[cfg.save]();
            } catch {
                sent = false;
            }
            this.busy = false;
            const goOn = go;
            const o = opener;
            if (sent && this.count() === 0 && !page.querySelector('[aria-invalid="true"]')) {
                setTyped(false);
                this.close(false);
                if (goOn) {
                    goOn();
                }

                return;
            }
            // Not saved: stay, the page shows its errors; take the focus to the first field in
            // error (the page may already have), the typed text untouched.
            this.close(false);
            window.requestAnimationFrame(() => {
                const a = document.activeElement;
                if (a && a.getAttribute && a.getAttribute('aria-invalid') === 'true') {
                    return;
                }
                const bad = Array.from(page.querySelectorAll('[aria-invalid="true"]:not([disabled])')).find((el) => el.getClientRects().length > 0);
                if (bad) {
                    bad.focus();
                } else if (o && o.isConnected) {
                    o.focus();
                }
            });
        },

        async discardGo() {
            if (this.busy) {
                return;
            }
            this.busy = true;
            try {
                await this.$wire[cfg.discard]();
            } catch {
                // leaving drops the draft all the same
            }
            this.busy = false;
            setTyped(false);
            const goOn = go;
            this.close(false);
            if (goOn) {
                goOn();
            }
        },

        /** Escape means "Ở lại"; Tab stays inside the dialog, wherever the focus fell. */
        key(e) {
            if (!this.open) {
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                this.stay();

                return;
            }
            if (e.key !== 'Tab') {
                return;
            }
            const box = this.$refs.box;
            const bs = Array.from(box.querySelectorAll('button:not([disabled])'));
            if (!bs.length) {
                return;
            }
            const first = bs[0];
            const last = bs[bs.length - 1];
            const a = document.activeElement;
            if (e.shiftKey && (a === first || a === box || !box.contains(a))) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && (a === last || !box.contains(a))) {
                e.preventDefault();
                first.focus();
            }
        },
    };
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.data('bsUnsaved', unsavedDialog);
}
