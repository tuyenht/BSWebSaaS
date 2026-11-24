// The editors' shared language bar (<x-bs-admin::lang-bar>, board 07 and 09), Alpine.data 'bsLangBar',
// started by the shared lazy loader (admin-lazy.js). The pills and the panel's rows are plain links
// (wire:navigate), so they work before this part starts and a version with unsaved changes asks first
// through the page's shared unsaved-changes dialog. This part:
//  - fits the pills to the bar: when they do not all fit, the state chip goes first, then the last
//    pills (never the source nor the open version), counted on «+N»; when even those two do not fit
//    the bar draws the selector instead ([data-squeeze]). A bar of 700px or less draws the selector in
//    place of the pills (the stylesheet decides), and «+N» beside it counts the other versions (board
//    07; the stylesheet hides it again at 420px and less). Measured again when the bar's width or the
//    member's text size changes, and after each redraw of the page;
//  - opens the panel from the selector or «+N» (Enter, Space or ↓; the focus goes to the search box
//    when there is one, else to the open version), moves through its rows with ↑ ↓ Home End, filters
//    them by name or code without accents, and closes on Escape (the focus back on its button), on a
//    click outside it or when the focus leaves it;
//  - after another version has been opened from the bar, puts the focus in the editor's first field.

const KEY = 'bs-lang-bar-open';
// How long the opening of another version may take before the focus is no longer moved.
const WAIT_MS = 20000;

/** Lower case, without Vietnamese accents (đ → d), for the panel's search. */
export function fold(s) {
    return String(s || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase();
}

/** Whether a language matches what was typed in the panel's search box (its name or its code). */
export function matches(lang, q) {
    const t = fold(q).trim();

    return t === '' || fold(lang.label).includes(t) || fold(lang.code).includes(t);
}

function storage() {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

/** Remember that the version at `href` was opened from the bar. */
export function remember(href, now = Date.now()) {
    const s = storage();
    if (!s) {
        return;
    }
    try {
        s.setItem(KEY, JSON.stringify({ to: new URL(href, window.location.href).href, at: now }));
    } catch {
        // storage full or blocked: the focus simply stays where the page puts it
    }
}

/** Whether this page is the version just opened from the bar (read once, then forgotten). */
export function arrived(here = window.location.href, now = Date.now()) {
    const s = storage();
    if (!s) {
        return false;
    }
    try {
        const raw = s.getItem(KEY);
        if (!raw) {
            return false;
        }
        s.removeItem(KEY);
        const rec = JSON.parse(raw);

        return !!rec && rec.to === new URL(here, window.location.href).href && now - Number(rec.at) <= WAIT_MS;
    } catch {
        return false;
    }
}

/** Whether a button is drawn (not hidden, not display:none through the bar's width rules). */
function drawn(el) {
    return !!el && el.isConnected && !el.hidden && window.getComputedStyle(el).display !== 'none';
}

export function langBar(cfg) {
    let ro = null;
    let offHook = null;
    let opener = null;
    let key = '';

    return {
        open: false,
        q: '',
        cut: [],
        over: 0,
        noteOpen: false,

        init() {
            const root = this.$root;
            // measured in the next frame: hiding pills inside the observer's own call reshapes the bar it watches
            let queued = false;
            ro = window.ResizeObserver
                ? new window.ResizeObserver(() => {
                      if (queued) {
                          return;
                      }
                      queued = true;
                      window.requestAnimationFrame(() => {
                          queued = false;
                          this.fit();
                      });
                  })
                : null;
            if (ro) {
                ro.observe(root);
            }
            this.fit(true);
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(() => this.fit(true));
            }
            // a redraw of the page puts the server's pills back: fit them again
            const id = this.$wire ? this.$wire.$id : null;
            const lw = window.Livewire;
            if (id && lw && typeof lw.hook === 'function') {
                offHook = lw.hook('commit', ({ component, succeed }) => {
                    if (component && component.id === id) {
                        succeed(() => window.requestAnimationFrame(() => this.fit(true)));
                    }
                });
            }
            if (cfg.focus && arrived()) {
                window.requestAnimationFrame(() => {
                    const f = document.getElementById(cfg.focus);
                    if (f && typeof f.focus === 'function') {
                        f.focus();
                    }
                });
            }
        },

        destroy() {
            if (ro) {
                ro.disconnect();
            }
            if (typeof offHook === 'function') {
                offHook();
            }
        },

        /** Hide the pills that do not fit (see the file comment); `force` measures even at the same width. */
        fit(force = false) {
            const root = this.$root;
            const pills = this.$refs.pills;
            const more = this.$refs.more;
            if (!pills || !root.isConnected) {
                return;
            }
            let boost = '';
            try {
                boost = window.getComputedStyle(root).getPropertyValue('--user-text-boost').trim();
            } catch {
                boost = '';
            }
            const now = root.clientWidth + '|' + boost;
            if (!force && now === key) {
                return;
            }
            key = now;
            const items = Array.from(pills.querySelectorAll('[data-lb-pill]'));
            root.removeAttribute('data-over');
            root.removeAttribute('data-squeeze');
            items.forEach((p) => {
                p.hidden = false;
            });
            const setMore = (n) => {
                if (more) {
                    more.hidden = n === 0;
                    const t = more.querySelector('span');
                    if (t) {
                        t.textContent = '+' + n;
                    }
                }
            };
            setMore(0);
            const cut = [];
            // a narrow bar: the selector names the open version, «+N» the others (more than ten: the selector alone)
            const narrow = !cfg.compact && window.getComputedStyle(pills).display === 'none';
            if (narrow) {
                this.cut = cut;
                this.over = Math.max(0, items.length - 1);
                setMore(this.over);

                return;
            }
            if (!cfg.compact) {
                const tight = () => pills.scrollWidth > pills.clientWidth + 1;
                if (tight()) {
                    root.setAttribute('data-over', '');
                }
                for (let i = items.length - 1; i >= 0 && tight(); i -= 1) {
                    if (items[i].hasAttribute('data-keep')) {
                        continue;
                    }
                    items[i].hidden = true;
                    cut.push(items[i].getAttribute('data-lb-pill'));
                    setMore(cut.length);
                }
                if (tight()) {
                    root.setAttribute('data-squeeze', '');
                    cut.length = 0;
                    items.forEach((p) => {
                        p.hidden = false;
                    });
                    setMore(0);
                }
            }
            this.cut = cut;
            this.over = cut.length;
        },

        moreLabel() {
            return String(cfg.more || '').replace('__N__', String(this.over));
        },

        hit(code) {
            const lang = (cfg.langs || []).find((l) => l.code === code);

            return !lang || matches(lang, this.q);
        },

        anyHit() {
            return (cfg.langs || []).some((l) => matches(l, this.q));
        },

        rows() {
            const menu = this.$refs.menu;

            return menu ? Array.from(menu.querySelectorAll('[role=menuitemradio]')).filter((r) => r.style.display !== 'none') : [];
        },

        // the row given the focus is the only one in the Tab order
        go(el) {
            if (!el) {
                return;
            }
            this.rows().forEach((r) => {
                r.tabIndex = r === el ? 0 : -1;
            });
            el.focus();
        },

        step(d, fromSearch = false) {
            const f = this.rows();
            if (!f.length) {
                return;
            }
            const i = fromSearch ? -1 : f.indexOf(document.activeElement);
            this.go(f[(i + d + f.length) % f.length]);
        },

        edge(last) {
            const f = this.rows();
            this.go(last ? f[f.length - 1] : f[0]);
        },

        toggle(btn) {
            if (this.open) {
                this.close(true);
            } else {
                this.show(btn);
            }
        },

        show(btn) {
            opener = btn || null;
            this.q = '';
            this.open = true;
            this.$nextTick(() => {
                this.place();
                if (this.$refs.q) {
                    this.$refs.q.focus();

                    return;
                }
                const f = this.rows();
                this.go(f.find((r) => r.getAttribute('aria-checked') === 'true') || f[0]);
            });
        },

        // the panel hangs under its button and never leaves the screen
        place() {
            const p = this.$refs.panel;
            if (!p) {
                return;
            }
            p.style.left = '';
            const r = p.getBoundingClientRect();
            const room = document.documentElement.clientWidth - 8;
            if (r.right > room) {
                p.style.left = Math.round(Math.min(0, room - r.right)) + 'px';
            }
        },

        close(back) {
            if (!this.open) {
                return;
            }
            this.open = false;
            const o = opener;
            opener = null;
            if (back && o && o.isConnected) {
                // the button that opened it, or the other one when the bar has changed shape meanwhile
                const shown = [o, this.$refs.sel, this.$refs.more].find((b) => drawn(b));
                if (shown) {
                    shown.focus();
                }
            }
        },

        // the focus left the selector, «+N» and the panel (Tab past its last part)
        leave(e) {
            const to = e.relatedTarget;
            if (this.open && to && !this.$refs.anchor.contains(to)) {
                this.close(false);
            }
        },

        // another version is opened from the bar: its editor takes the focus in its first field
        pick(el) {
            remember(el.href);
            this.close(false);
        },
    };
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.data('bsLangBar', langBar);
}
