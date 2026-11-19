// The page tree of the Trang list (design 05, Alpine.data 'bsPageMove'): dragging a page row by its
// grip (the one drag rule of every admin tree, admin-tree-dnd.js) and the «Chuyển đến…» dialog. The
// server draws the rows — in website order each page row carries data-pk (its ulid), data-pp (its
// parent's), data-lv (1 = top) and data-name, inside the list marked data-page-tree — and makes every
// move (App\Modules\Admin\Livewire\Entries\Index: movePage, movePageTo, moveTargets), judging each
// again; this part only works out where a drop lands and says why a place is refused before the
// drop. Names are only ever shown as text.
import { createTreeDnd, fill, heightOf } from './admin-tree-dnd.js';

const MAX_LV = 3;
const fold = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();

function pageMove(t) {
    let dnd = null;
    let off = () => {};

    const tree = () => document.querySelector('[data-page-tree]');
    const rowEls = () => Array.from(tree()?.querySelectorAll('[data-pk]') ?? []);
    const rowOf = (k) => rowEls().find((el) => el.dataset.pk === k) ?? null;
    const rows = () => rowEls().map((el) => ({ k: el.dataset.pk, parent: el.dataset.pp || null, lv: Number(el.dataset.lv) || 1, fold: false }));
    const mark = (attr, k, v) => {
        rowEls().forEach((el) => {
            if (k !== null && el.dataset.pk === k) {
                el.setAttribute(attr, v);
            } else {
                el.removeAttribute(attr);
            }
        });
    };
    const depthWhy = (h) => (h <= 1 ? t.depthPage : fill(h === 2 ? t.depthL1 : t.depthTop, { n: h }));

    return {
        mv: null,

        init() {
            dnd = createTreeDnd({
                step: () => 20,
                name: (k) => rowOf(k)?.dataset.name ?? '',
                say: (text) => {
                    if (this.$refs.live) {
                        this.$refs.live.textContent = text;
                    }
                },
                check: (T, k) => {
                    const all = rows();
                    if (!all.some((r) => r.k === k)) {
                        return { ok: false, label: t.gone, why: t.gone };
                    }
                    const parent = T.parent ? all.find((r) => r.k === T.parent) : null;
                    if (T.parent && rowOf(T.parent)?.dataset.home === '1') {
                        return { ok: false, label: t.home, why: t.home };
                    }
                    const h = heightOf(all, k);
                    if ((parent ? parent.lv : 0) + h > MAX_LV) {
                        const why = depthWhy(h);
                        return { ok: false, label: why, why };
                    }
                    return null;
                },
                onTarget: (T) => mark('data-drop', T && T.mode === 'in' ? T.refK : null, T && T.bad ? 'bad' : 'in'),
                onExpand: () => {},
                place: (T) => {
                    const el = rowOf(T.ph.k);
                    if (!el) {
                        return null;
                    }
                    const r = el.getBoundingClientRect();
                    const x = r.left + 48 + (T.lv - 1) * 20;
                    return { x, y: T.ph.at === 'before' ? r.top : r.bottom, w: Math.max(40, r.right - x - 8) };
                },
                texts: t.dnd,
            });

            const start = (e) => {
                const grip = e.target.closest?.('[data-page-tree] [data-grip]');
                const row = grip?.closest('[data-pk]');
                if (!row) {
                    return;
                }
                if (grip.dataset.off === '1') {
                    e.preventDefault();
                    return;
                }
                dnd.start(e, row.dataset.pk, row);
                try {
                    e.dataTransfer.setDragImage(row, 16, 16);
                } catch {
                    // the grip itself is the picture then
                }
                row.setAttribute('data-drag', '1');
            };
            const over = (e) => {
                if (dnd.k == null) {
                    return;
                }
                const row = e.target.closest?.('[data-page-tree] [data-pk]');
                if (row) {
                    dnd.over(e, row, row.dataset.pk, rows());
                }
            };
            const clear = () => {
                mark('data-drop', null);
                mark('data-drag', null);
            };
            const drop = (e) => {
                if (dnd.k == null) {
                    return;
                }
                e.preventDefault();
                const k = dnd.k;
                const T = dnd.end();
                dnd.paint();
                clear();
                if (!T) {
                    return;
                }
                if (T.bad) {
                    this.$bsToast({ text: fill(t.kept, { why: T.why || T.label }), icon: t.lock, stay: true, close: t.close });
                    return;
                }
                const ref = T.rel === 'before' ? T.beforeK ?? T.refK : T.rel === 'after' ? T.afterK ?? T.refK : null;
                this.$wire.movePage(k, T.parent || null, T.rel, ref).then(() => this.flash(k));
            };
            const end = () => {
                if (dnd.k != null) {
                    dnd.end();
                    dnd.paint();
                }
                clear();
            };
            document.addEventListener('dragstart', start);
            document.addEventListener('dragover', over);
            document.addEventListener('drop', drop);
            document.addEventListener('dragend', end);
            off = () => {
                document.removeEventListener('dragstart', start);
                document.removeEventListener('dragover', over);
                document.removeEventListener('drop', drop);
                document.removeEventListener('dragend', end);
            };
        },

        destroy() {
            off();
        },

        flash(k) {
            const el = rowOf(k);
            if (el) {
                el.setAttribute('data-flash', '1');
                setTimeout(() => el.removeAttribute('data-flash'), 1000);
            }
        },

        // ---- «Chuyển đến…» ----------------------------------------------------------------------
        open(d) {
            this.mv = { ulid: d.ulid, name: d.name, q: '', opts: null, nested: false, to: undefined, opener: document.activeElement };
            this.$nextTick(() => document.getElementById('entries-mv-q')?.focus());
            this.$wire.moveTargets(d.ulid).then((r) => {
                if (!this.mv || this.mv.ulid !== d.ulid) {
                    return;
                }
                if (!r) {
                    this.close();
                    return;
                }
                this.mv.nested = !!r.nested;
                this.mv.opts = r.opts;
            });
        },
        close() {
            const o = this.mv?.opener;
            this.mv = null;
            if (o && o.isConnected) {
                o.focus();
            }
        },
        get shown() {
            const m = this.mv;
            if (!m || !m.opts) {
                return [];
            }
            const q = fold(m.q.trim());
            return q ? m.opts.filter((o) => fold(o.name).includes(q) || (o.path && fold(o.path).includes(q))) : m.opts;
        },
        get hint() {
            return this.mv?.nested ? t.hint : t.hintFlat;
        },
        get chosen() {
            const m = this.mv;
            return m && m.to !== undefined ? m.opts?.find((o) => o.k === m.to) ?? null : null;
        },
        get btn() {
            const o = this.chosen;
            return !o ? t.pick : o.k === null ? t.goTop : fill(t.goIn, { name: o.name });
        },
        pick(o) {
            if (this.mv && !o.here && !o.why) {
                this.mv.to = o.k;
            }
        },
        go() {
            const m = this.mv;
            const o = this.chosen;
            if (!m || !o) {
                this.$bsToast({ text: t.pickFirst, icon: t.info });
                return;
            }
            const ulid = m.ulid;
            this.mv = null;
            this.$wire.movePageTo(ulid, o.k).then(() => {
                this.flash(ulid);
                rowOf(ulid)?.querySelector('.list-name')?.focus();
            });
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsPageMove', pageMove);
});
