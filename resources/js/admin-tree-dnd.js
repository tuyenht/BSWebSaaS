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
let labelW = 240;
let active = null;

// One pass over the text, the longest placeholder first, so a value that itself holds ":level"
// (a record named "Bước :level") is never replaced again.
export function fill(text, values) {
    const keys = Object.keys(values).sort((a, b) => b.length - a.length);
    if (!keys.length) {
        return text;
    }
    const re = new RegExp(':(' + keys.map((k) => k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'g');
    return text.replace(re, (m, key) => String(values[key]));
}

// ---- the pure rule (no DOM): what the tree and the tests both use ------------------------------
// rows = every record in drawing order: [{ k, parent (key or null), lv }].

// The children of a parent (null = the outer level), in order, leaving one key out.
export function kidsOf(rows, parent, except = null) {
    return rows.filter((r) => (r.parent || null) === parent && r.k !== except).map((r) => r.k);
}

// How many levels a record and everything under it take (1 for a record without children).
export function heightOf(rows, k) {
    const i = rows.findIndex((r) => r.k === k);
    if (i < 0) {
        return 1;
    }
    const lv = rows[i].lv;
    let max = lv;
    for (let j = i + 1; j < rows.length && rows[j].lv > lv; j++) {
        max = Math.max(max, rows[j].lv);
    }
    return max - lv + 1;
}

// A keyboard or menu move worked out from the tree as drawn now: { parent, pos, lv, target } — pos
// among the new parent's other children, lv the level it would take — or { no } with the reason key
// (atTop · atBottom · noAbove · atOuter · gone).
export function planMove(act, rows, k) {
    const me = rows.find((r) => r.k === k);
    if (!me) {
        return { no: 'gone' };
    }
    const parent = me.parent || null;
    const sib = kidsOf(rows, parent);
    const i = sib.indexOf(k);
    if (act === 'moveUp') {
        return i <= 0 ? { no: 'atTop' } : { parent, pos: i - 1, lv: me.lv };
    }
    if (act === 'moveDown') {
        return i >= sib.length - 1 ? { no: 'atBottom' } : { parent, pos: i + 1, lv: me.lv };
    }
    if (act === 'indent') {
        if (i <= 0) {
            return { no: 'noAbove' };
        }
        const target = sib[i - 1];
        return { parent: target, pos: kidsOf(rows, target, k).length, lv: me.lv + 1, target };
    }
    if (act === 'outdent') {
        if (!parent) {
            return { no: 'atOuter' };
        }
        const p = rows.find((r) => r.k === parent);
        const up = p ? p.parent || null : null;
        return { parent: up, pos: kidsOf(rows, up, k).indexOf(parent) + 1, lv: me.lv - 1 };
    }
    return { no: 'gone' };
}

// Where a drop lands among the new parent's other children (sib: those children, without the
// dragged record): the first place for "child", before refK, or after afterK.
export function dropPosition(T, sib) {
    if (T.rel === 'child') {
        return 0;
    }
    if (T.rel === 'before') {
        return Math.max(0, sib.indexOf(T.refK));
    }
    return sib.indexOf(T.afterK) + 1;
}

// The rows a drag can land between: everything but the dragged record and what is under it.
export function cleanRows(rows, k) {
    const sub = {};
    const i = rows.findIndex((r) => r.k === k);
    if (i >= 0) {
        const lv = rows[i].lv;
        sub[rows[i].k] = 1;
        for (let j = i + 1; j < rows.length && rows[j].lv > lv; j++) {
            sub[rows[j].k] = 1;
        }
    }
    return { R: rows.filter((r) => !sub[r.k]), sub };
}

// Which part of a row the pointer is over, from its height fraction y (0 top, 1 bottom): the middle
// half is the name (drop in), the top and bottom quarters belong to the gaps above and below.
export function zoneOf(y) {
    return y < 0.25 ? 'above' : y > 0.75 ? 'below' : 'in';
}

// A drop in the gap after R[g] (g = -1: before the first row). x0 is where the pointer entered the
// gap, x where it is now, step the indent per level. Without the label: { mode, rel, refK, parent,
// beforeK | afterK, lv, ph }.
export function gapTarget(R, g, x0, x, step) {
    const up = g >= 0 ? R[g] : null;
    const lo = R[g + 1] || null;
    if (!up) {
        return { mode: 'gap', rel: 'before', refK: lo.k, parent: lo.parent || null, beforeK: lo.k, lv: lo.lv, ph: { at: 'before', k: lo.k } };
    }
    if (lo && lo.lv > up.lv) {
        return { mode: 'gap', rel: 'child', refK: up.k, parent: up.k, beforeK: lo.k, lv: lo.lv, ph: { at: 'after', k: up.k } };
    }
    const lowL = lo ? lo.lv : 1;
    const hiL = up.lv;
    const d = Math.max(0, Math.floor((x0 - x) / step));
    const L = Math.max(lowL, Math.min(hiL, hiL - d));
    let A = up;
    for (let j = g; j >= 0; j--) {
        if (R[j].lv <= L) {
            A = R[j];
            break;
        }
    }
    return { mode: 'gap', rel: 'after', refK: A.k, parent: A.parent || null, afterK: A.k, lv: L, ph: { at: 'after', k: up.k } };
}

// ---- the browser part --------------------------------------------------------------------------
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
        label.textContent = '';
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
const onDocDragover = (e) => {
    const c = active;
    if (!c) {
        return;
    }
    if (label && c.T) {
        // only a new label is measured again: most dragover events keep the same target
        if (label.textContent !== c.T.label || !label.hasAttribute('data-on')) {
            label.setAttribute('data-on', '1');
            label.setAttribute('data-bad', c.T.bad ? '1' : '0');
            label.textContent = c.T.label;
            labelW = label.offsetWidth || 240;
        }
        const vw = window.innerWidth;
        const w = labelW;
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
};
if (typeof document !== 'undefined') {
    document.addEventListener('dragover', onDocDragover);
}

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
        return cleanRows(rows, this.k);
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
        const zone = zoneOf((e.clientY - rc.top) / Math.max(1, rc.height));
        if (zone === 'in') {
            const row = R[i];
            this.gap = null;
            this.dwell(row);
            return this.emit(this.mk({ mode: 'in', rel: 'child', refK: row.k, parent: row.k, lv: row.lv + 1, ph: { at: 'after', k: row.k } }));
        }
        this.dwell(null);
        return this.emit(this.gapT(R, zone === 'above' ? i - 1 : i, e.clientX));
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
        return this.mk(gapTarget(R, g, this.gap.x0, x, this.o.step()));
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

