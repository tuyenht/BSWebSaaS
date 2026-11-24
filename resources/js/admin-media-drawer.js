// The media library's file detail drawer (partials/media-library-drawer.blade.php, design board 12),
// Alpine.data 'bsMediaDrawer', started by the shared lazy loader (admin-lazy.js). The server draws
// one description field and one title field per website language; this part chooses which language
// shows (the chips, «+N» and its list with a search from six languages), and reads the fields as
// they are typed in: each chip's dot (has a description / none yet / the source still missing), the
// line under the chips, the «n/188» counter, the «x/y bản có mô tả» progress, and how many fields
// differ from what is stored (data-orig), told to the footer as the window event 'mdrw-dirty'.
// After «Lưu» the server's 'media-detail-saved' names the first language in error, which then
// shows with its field focused. «Sao chép» copies the file address; the toast is the page's.

/**
 * @param {{ source: string, names: Record<string, string>, few: boolean, w: Record<string, string> }} cfg
 *   the source language, the website languages' names by code (source first), whether every chip
 *   shows (three languages or fewer), and the words the part writes.
 */
export function mediaDrawer(cfg) {
    let copyTimer = null;

    return {
        lang: cfg.source,
        source: cfg.source,
        names: cfg.names,
        w: cfg.w,
        few: cfg.few,
        open: false,
        q: '',
        filled: {},
        count: 0,
        copied: false,

        init() {
            this.scan();
            setTimeout(() => this.scan(), 0); // after Livewire has filled the wire:model fields
        },

        destroy() {
            clearTimeout(copyTimer);
        },

        field(c) {
            return this.$root.querySelector('textarea[data-lang="' + String(c).replace(/[^a-zA-Z0-9-]/g, '') + '"]');
        },

        scan() {
            const changed = Array.from(this.$root.querySelectorAll('[data-orig]')).filter((e) => e.value.trim() !== e.dataset.orig).length;
            const st = {};
            this.$root.querySelectorAll('textarea[data-lang]').forEach((e) => {
                st[e.dataset.lang] = e.value.trim() !== '';
            });
            this.filled = st;
            const cur = this.field(this.lang);
            this.count = cur ? cur.value.length : 0;
            window.dispatchEvent(new window.CustomEvent('mdrw-dirty', { detail: changed }));
        },

        st(c) {
            if (this.filled[c]) {
                return 'full';
            }

            return c === this.source ? 'miss' : 'empty';
        },

        /** The shared language dot's kind (.lb-dot): green, warning (the source missing) or dashed. */
        dot(c) {
            return { full: 'full', miss: 'partial', empty: 'empty' }[this.st(c)];
        },

        tip(c) {
            return this.names[c] + ' — ' + this.w[this.st(c)] + (c === this.source ? ' · ' + this.w.tipSrc : '');
        },

        sub(c) {
            return this.w[this.st(c)] + (c === this.source ? ' · ' + this.w.src : '');
        },

        /** With more than three languages only the source and the open one have a chip; «+N» lists the rest. */
        shown(c) {
            return this.few || c === this.source || c === this.lang;
        },

        more() {
            return Object.keys(this.names).filter((c) => !this.shown(c)).length;
        },

        match(c) {
            const q = this.q.trim().toLowerCase();

            return !q || this.names[c].toLowerCase().includes(q) || c.toLowerCase().includes(q);
        },

        anyMatch() {
            return Object.keys(this.names).some((c) => this.match(c));
        },

        progress() {
            const all = Object.keys(this.names);

            return this.w.progress.replace(':x', String(all.filter((c) => this.filled[c]).length)).replace(':y', String(all.length));
        },

        tone() {
            if (this.lang === this.source) {
                return 'src';
            }

            return this.filled[this.lang] ? 'full' : 'warn';
        },

        line() {
            if (this.lang === this.source) {
                return this.w.lineSource;
            }

            return (this.filled[this.lang] ? this.w.lineFull : this.w.lineEmpty).split(':name').join(this.names[this.lang]);
        },

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => {
                    const first = this.$refs.pop && this.$refs.pop.querySelector('input, button');
                    if (first) {
                        first.focus();
                    }
                });
            }
        },

        /** Esc inside the chips closes their list first; the drawer closes on the next one. */
        esc(e) {
            if (!this.open) {
                return;
            }
            e.stopPropagation();
            e.preventDefault();
            this.open = false;
            if (this.$refs.more) {
                this.$refs.more.focus();
            }
        },

        pick(c, focus) {
            this.lang = c;
            this.open = false;
            this.q = '';
            this.$nextTick(() => {
                this.scan();
                const f = focus ? this.field(c) : null;
                if (f) {
                    f.focus();
                }
            });
        },

        /** The server's answer to «Lưu» (after its redraw): recount, and show the language in error. */
        saved(e) {
            setTimeout(() => {
                this.scan();
                const l = e && e.detail ? e.detail.lang : '';
                if (l && this.names[l]) {
                    this.pick(l, true);
                }
            }, 0);
        },

        copy() {
            const input = this.$refs.url;
            const done = (ok) => {
                if (ok) {
                    this.copied = true;
                    clearTimeout(copyTimer);
                    copyTimer = setTimeout(() => {
                        this.copied = false;
                    }, 2000);
                } else if (input) {
                    input.select(); // the address stays chosen, ready for Ctrl+C
                }
                this.$wire.copiedUrl(ok);
            };
            try {
                window.navigator.clipboard.writeText(input ? input.value : '').then(() => done(true), () => done(false));
            } catch {
                done(false);
            }
        },
    };
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.data('bsMediaDrawer', mediaDrawer);
}
