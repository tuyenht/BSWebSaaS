// The one drag rule of every admin tree (pages, categories, menus, product categories), ported from
// the design's tree-dnd.js. It only works out WHERE a drop would land and says it (the floating label
// by the pointer and the screen-reader line); the tree draws its own placeholder from the result.
//
// The rule:
//   · the middle half of a row (its name) → the row's FIRST child (a collapsed parent opens after 0.6 s);
//   · the bottom quarter of a row + the top quarter of the next = ONE gap; moving up and down inside
//     it changes nothing;
//   · inside a gap the level follows the pointer's x against where it entered the gap: by default the
//     level of the row above; each step to the left outdents one level, never below the level of the
//     row below (the last gap: down to the outer level); back to the right, never past the row above;
//   · the gap right under an open parent → that parent's first child.
//
// rows = the rows on screen, in order: [{ k, lv (1 = outer level), parent, fold (has collapsed children) }].
// A target: { mode: 'in'|'gap', rel: 'child'|'before'|'after', refK, parent, beforeK, afterK, lv, ph: {at, k},
//             bad, label, why, key }.

let label = null;
let active = null;

function fill(text, values) {
    return Object.entries(values).reduce((out, [key, value]) => out.split(':' + key).join(String(value)), text);
}

function ensureLabel() {
    if (label && document.body.contains(label)) {
        return;
    }
    label = document.createElement('div');
    label.className = 'tree-dl';
    label.setAttribute('aria-hidden', 'true');
    document.body.appendChild(label);
}

function hideLabel() {
    if (label) {
        label.removeAttribute('data-on');
        label.style.transform = '';
    }
}

function scroller(el) {
    while (el && el !== document.body && el !== document.documentElement) {
        const cs = getComputedStyle(el);
        if (/(auto|scroll)/.test(cs.overflowY) && el.scrollHeight > el.clientHeight) {
            return el;
        }
        el = el.parentElement;
    }
    return document.scrollingElement || document.documentElement;
}

// One document listener for every tree: the label follows the pointer, and the page (or the list's
// own scroll box) scrolls when the pointer nears its top or bottom edge.
document.addEventListener('dragover', (e) => {
    const c = active;
    if (!c) {
        return;
    }
    if (label && c.T) {
        label.setAttribute('data-on', '1');
        label.setAttribute('data-bad', c.T.bad ? '1' : '0');
        label.textContent = c.T.label;
        const vw = window.innerWidth;
        const w = label.offsetWidth || 240;
        let x = e.clientX + 16;
        if (x + w > vw - 8) {
            x = Math.max(8, e.clientX - w - 12);
        }
        label.style.transform = 'translate(' + Math.round(x) + 'px,' + Math.round(e.clientY + 18) + 'px)';
    }
    const sc = c.sc;
    if (!sc) {
        return;
    }
    const whole = sc === document.scrollingElement || sc === document.documentElement;
    const top = whole ? 0 : sc.getBoundingClientRect().top;
    const bot = whole ? window.innerHeight : sc.getBoundingClientRect().bottom;
    if (e.clientY < top + 64) {
        sc.scrollTop -= 14;
    } else if (e.clientY > bot - 64) {
        sc.scrollTop += 14;
    }
});

class TreeDnd {
    // o: { step() px per level, name(k), check(T, dragKey) → {ok:false,label,why}|null, say(text),
    //      onTarget(T), onExpand(k), texts: {start, child, before, after, self} }
    constructor(o) {
        this.o = o;
        this.T = null;
        this.k = null;
        this.gap = null;
    }

    start(e, k, rowEl) {
        ensureLabel();
        this.k = k;
        this.T = null;
        this.gap = null;
        active = this;
        this.sc = scroller(rowEl ? rowEl.parentElement : null);
        try {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(k));
        } catch {
            // some browsers refuse setData on a synthetic event; the drag still works
        }
        this.o.say(fill(this.o.texts.start, { name: this.o.name(k) }));
    }

    end() {
        clearTimeout(this.expandTimer);
        this.expandKey = null;
        const T = this.T;
        this.T = null;
        this.k = null;
        this.gap = null;
        if (active === this) {
            active = null;
        }
        hideLabel();
        return T;
    }

    // Leave out the row being dragged and everything under it.
    clean(rows) {
        const out = [];
        const sub = {};
        const i = rows.findIndex((r) => r.k === this.k);
        if (i >= 0) {
            const lv = rows[i].lv;
            sub[rows[i].k] = 1;
            for (let j = i + 1; j < rows.length && rows[j].lv > lv; j++) {
                sub[rows[j].k] = 1;
            }
        }
        rows.forEach((r) => {
            if (!sub[r.k]) {
                out.push(r);
            }
        });
        return { R: out, sub };
    }

    over(e, rowEl, k, rows) {
        if (this.k == null) {
            return null;
        }
        e.preventDefault();
        try {
            e.dataTransfer.dropEffect = 'move';
        } catch {
            // read-only in some browsers
        }
        if (k === this.k) {
            return this.T;
        }
        const C = this.clean(rows);
        const R = C.R;
        const i = R.findIndex((r) => r.k === k);
        if (C.sub[k]) {
            return this.emit(this.mk({ mode: 'in', rel: 'child', refK: k, parent: k, lv: 0, ph: { at: 'after', k }, self: true }));
        }
        if (i < 0) {
            return this.T;
        }
        const rc = rowEl.getBoundingClientRect();
        const y = (e.clientY - rc.top) / Math.max(1, rc.height);
        if (y >= 0.25 && y <= 0.75) {
            const row = R[i];
            this.gap = null;
            this.dwell(row);
            return this.emit(this.mk({ mode: 'in', rel: 'child', refK: row.k, parent: row.k, lv: row.lv + 1, ph: { at: 'after', k: row.k } }));
        }
        this.dwell(null);
        return this.emit(this.gapT(R, y < 0.25 ? i - 1 : i, e.clientX));
    }

    // Over the placeholder itself: stay in the gap it stands for, the level still following x.
    overPh(e, rows) {
        if (this.k == null) {
            return null;
        }
        e.preventDefault();
        try {
            e.dataTransfer.dropEffect = 'move';
        } catch {
            // read-only in some browsers
        }
        if (!this.gap) {
            return this.T;
        }
        const R = this.clean(rows).R;
        let g = R.findIndex((r) => r.k === this.gap.u);
        if (this.gap.u == null) {
            g = -1;
        } else if (g < 0) {
            return this.T;
        }
        return this.emit(this.gapT(R, g, e.clientX));
    }

    dwell(row) {
        if (row && row.fold) {
            if (this.expandKey !== row.k) {
                this.expandKey = row.k;
                clearTimeout(this.expandTimer);
                this.expandTimer = setTimeout(() => this.o.onExpand(row.k), 600);
            }
        } else if (this.expandKey != null) {
            this.expandKey = null;
            clearTimeout(this.expandTimer);
        }
    }

    gapT(R, g, x) {
        const up = g >= 0 ? R[g] : null;
        const lo = R[g + 1] || null;
        const id = (up ? up.k : '') + '|' + (lo ? lo.k : '');
        if (!this.gap || this.gap.id !== id) {
            this.gap = { id, x0: x, u: up ? up.k : null };
        }
        if (!up) {
            return this.mk({ mode: 'gap', rel: 'before', refK: lo.k, parent: lo.parent || null, beforeK: lo.k, lv: lo.lv, ph: { at: 'before', k: lo.k } });
        }
        if (lo && lo.lv > up.lv) {
            return this.mk({ mode: 'gap', rel: 'child', refK: up.k, parent: up.k, beforeK: lo.k, lv: lo.lv, ph: { at: 'after', k: up.k } });
        }
        const lowL = lo ? lo.lv : 1;
        const hiL = up.lv;
        const d = Math.max(0, Math.floor((this.gap.x0 - x) / this.o.step()));
        const L = Math.max(lowL, Math.min(hiL, hiL - d));
        let A = up;
        for (let j = g; j >= 0; j--) {
            if (R[j].lv <= L) {
                A = R[j];
                break;
            }
        }
        return this.mk({ mode: 'gap', rel: 'after', refK: A.k, parent: A.parent || null, afterK: A.k, lv: L, ph: { at: 'after', k: up.k } });
    }

    mk(T) {
        const t = this.o.texts;
        if (T.self) {
            T.bad = true;
            T.label = t.self;
            T.why = t.self + '.';
        } else {
            const c = this.o.check(T, this.k);
            if (c && c.ok === false) {
                T.bad = true;
                T.label = c.label;
                T.why = c.why || c.label;
            } else {
                const values = { name: this.o.name(T.refK), level: T.lv };
                T.label = fill(T.rel === 'child' ? t.child : T.rel === 'before' ? t.before : t.after, values);
            }
        }
        T.key = [T.mode, T.rel, T.refK, T.lv, T.ph.at, T.ph.k, T.bad ? 1 : 0].join('|');
        return T;
    }

    emit(T) {
        if (!T) {
            return this.T;
        }
        const changed = !this.T || this.T.key !== T.key;
        this.T = T;
        if (changed) {
            this.o.say(T.label);
            this.o.onTarget(T);
        }
        return T;
    }
}

export function createTreeDnd(o) {
    return new TreeDnd(o);
}

export { fill };
