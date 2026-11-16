// The one save bar of a settings section (<x-bs-admin::save-bar>, rendered by the settings shell;
// design 01, the bar stuck to the bottom: "N thay đổi chưa lưu · N lỗi · Xem lỗi đầu tiên · Bỏ thay
// đổi · Lưu"), Alpine.data 'bsSaveBar', started by the shared lazy loader (admin-lazy.js).
//  - The page's count of unsaved changes and of fields in error come from the server, redrawn by
//    Livewire on a hidden element of the bar (x-ref "state"); typed text the server has not seen yet
//    comes from the shared unsaved-changes dialog ('bs-unsaved-typed'), so the bar and the leave
//    guard read one and the same flag.
//  - Nothing changed: both buttons are off and the status says so ("Chưa có thay đổi", or "Đã lưu
//    HH:MM" once saved on this visit). "Lưu" stays focusable (aria-disabled) and does nothing.
//  - A section that checks its fields as they are left (live) sends "Lưu" to the first field in
//    error while any is left; the others let the server check on save.
//  - "Lưu" shows it is busy at once and takes no second press until the round trip is over.
//    "Đã lưu HH:MM" follows only a save the page says it made (its save method answers true).
//  - Enter in a one-line field of a section's form is "Lưu" too, with the same checks (a form
//    with several fields and no submit button would otherwise ignore it): the bar's keydown
//    listener in components/save-bar.blade.php calls go().
//  - The bar's height (--sb-h on <html>) keeps a focused field at the bottom of the page in view.

const two = (n) => (n < 10 ? '0' : '') + n;

/** "HH:MM" of a date, as the bar says when the section was saved. */
export function clock(d) {
    return two(d.getHours()) + ':' + two(d.getMinutes());
}

/**
 * @param {{ save: string, discard: string, live: boolean, firstError: string|null,
 *   changes: string, clean: string, savedAt: string, errs: string }} cfg the page's save and
 *   discard methods, whether its errors are checked as fields are left, the event that opens the
 *   group of the first error (null: focus it), and the bar's sentences (":count" / ":time" filled in).
 */
export function saveBar(cfg) {
    let mo = null;
    let ro = null;
    let off = null;

    return {
        count: 0,
        invalid: 0,
        typed: false,
        busy: '',
        savedAt: '',

        init() {
            this.read();
            const state = this.$refs.state;
            if (state && window.MutationObserver) {
                mo = new window.MutationObserver(() => this.read());
                mo.observe(state, { attributes: true });
            }
            this.typed = !!document.querySelector('[data-unsaved-guard][data-typed]');
            off = new window.AbortController();
            window.addEventListener('bs-unsaved-typed', (e) => {
                this.typed = !!(e.detail && e.detail.on);
            }, off);
            if (window.ResizeObserver) {
                ro = new window.ResizeObserver(() => this.fit());
                ro.observe(this.$root);
            }
            this.fit();
        },

        destroy() {
            if (mo) {
                mo.disconnect();
            }
            if (ro) {
                ro.disconnect();
            }
            if (off) {
                off.abort();
            }
            document.documentElement.style.removeProperty('--sb-h');
        },

        /** The server's counts, as Livewire last drew them. */
        read() {
            const el = this.$refs.state;
            this.count = el ? Number(el.getAttribute('data-changes')) || 0 : 0;
            this.invalid = el ? Number(el.getAttribute('data-invalid')) || 0 : 0;
        },

        fit() {
            const h = this.$root.getClientRects().length ? Math.ceil(this.$root.getBoundingClientRect().height) : 0;
            const root = document.documentElement.style;
            if (h > 0) {
                root.setProperty('--sb-h', h + 'px');
            } else {
                root.removeProperty('--sb-h');
            }
        },

        get dirty() {
            return this.count > 0 || this.typed;
        },

        get blocked() {
            return !this.dirty || (cfg.live && this.invalid > 0);
        },

        get changesText() {
            return cfg.changes.replace(':count', String(Math.max(this.count, 1)));
        },

        get cleanText() {
            return this.savedAt ? cfg.savedAt.replace(':time', this.savedAt) : cfg.clean;
        },

        get errsText() {
            return cfg.errs.replace(':count', String(this.invalid));
        },

        async go() {
            if (this.busy || !this.dirty) {
                return;
            }
            if (cfg.live && this.invalid > 0) {
                this.firstError();

                return;
            }
            this.busy = 'save';
            // The page's save answers true once it wrote; false (nothing differed, refused) or a lost
            // request leaves the status as it was.
            const saved = await this.$wire[cfg.save]().then((r) => r === true, () => false);
            this.busy = '';
            this.read();
            if (saved && this.count === 0 && this.invalid === 0) {
                this.savedAt = clock(new Date());
            }
        },

        async discard() {
            if (this.busy || !this.dirty) {
                return;
            }
            // "Bỏ thay đổi" turns itself off, which would drop the keyboard focus to the page's start:
            // a member who pressed it from the bar keeps the focus in the bar, on "Lưu".
            const inBar = this.$root.contains(document.activeElement);
            this.busy = 'discard';
            try {
                await this.$wire[cfg.discard]();
            } catch {
                // the fields keep what was typed; the bar stays as it was
            }
            this.busy = '';
            this.read();
            const save = this.$root.querySelector('[data-sb-save]');
            if (inBar && save && !this.$root.contains(document.activeElement)) {
                save.focus();
            }
        },

        /** Open the group of the first field in error (the page's own event), then focus that field. */
        firstError() {
            const el = this.$refs.state;
            if (cfg.firstError) {
                let detail = {};
                try {
                    detail = JSON.parse((el && el.getAttribute('data-first-error')) || '{}');
                } catch {
                    detail = {};
                }
                window.dispatchEvent(new window.CustomEvent(cfg.firstError, { detail }));

                return;
            }
            const shell = this.$root.closest('[data-set-shell]') || document;
            const bad = Array.from(shell.querySelectorAll('[aria-invalid="true"]:not([disabled])')).find((f) => f.getClientRects().length > 0);
            if (bad) {
                bad.focus();
            }
        },
    };
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.data('bsSaveBar', saveBar);
}
