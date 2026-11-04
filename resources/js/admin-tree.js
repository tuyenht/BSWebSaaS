// The shared admin tree (<x-bs-admin::tree>, Alpine.data 'bsTree'): collapse and expand, drag and
// drop under the one drag rule (admin-tree-dnd.js), the keyboard moves (Alt+↑/↓ move within the
// group, Alt+→ into the record above, Alt+← out one level, Ctrl+Z undoes, Ctrl+Shift+Z redoes) and
// the arrow keys between the names, which share ONE Tab stop (a roving tabindex). The server draws
// every row; this object only reads them. A move is ONE call to the screen's Livewire method —
// (record key, new parent key or null, position among the new parent's other children) — which
// answers null when done or a plain-language reason when it refuses; after a refusal (or a lost
// connection) the tree is read again from the server. Keys are whatever opaque string the screen
// gave each row.
//
// Moves wait in line: while one call is out, the next move, undo or redo waits its turn and works
// out its place from the tree as the server redrew it after the call before ("Đang lưu…" shows
// meanwhile, and a new drag waits until the line is empty).
import { createTreeDnd, dropPosition, fill, kidsOf, makeHistory, makeQueue, planMove } from './admin-tree-dnd.js';

const UNDO_MS = 10000;
const QUEUE_MAX = 10;
// The row attributes the server's redraw can change; any change rebuilds the key → row index.
const WATCH = ['data-k', 'data-parent', 'data-lv', 'data-kids', 'data-lock', 'data-name'];
const ACT_FOCUSABLE = '.tree-c-act a[href], .tree-c-act button, .tree-c-act input, .tree-c-act select, .tree-c-act textarea';

function setTab(el, value) {
    if (el && el.getAttribute('tabindex') !== value) {
        el.setAttribute('tabindex', value);
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsTree', (cfg) => {
        // Kept outside Alpine's reactive state (a reactive proxy around DOM nodes and long lists would
        // track every read): the key → row index, the history, the line of waiting moves.
        const t = cfg.texts;
        let ix = null;
        let dnd = null;
        let mo = null;
        const history = makeHistory({ max: 50 });
        let queue = null;
        let cur = null; // the key whose name holds the tree's one Tab stop
        let dropEl = null; // the row painted as the drop target

        return {
            collapsed: {},
            // Bumped whenever the rows change under Alpine (a move, a refresh, the server's redraw):
            // hid() reads it, so every row works out again whether a collapsed parent hides it.
            ver: 0,
            msg: '',
            msgBad: false,
            msgUndo: false,
            busy: false,
            dragK: null,

            init() {
                dnd = createTreeDnd({
                    step: () => parseFloat(getComputedStyle(this.$refs.grid).getPropertyValue('--tree-step')) || 32,
                    name: (k) => this.nameOf(k),
                    check: (T, k) => this.allow(k, T.parent || null, T.lv),
                    say: (text) => this.say(text),
                    onTarget: (T) => this.showTarget(T),
                    onExpand: (k) => this.expand(k),
                    texts: t.drag,
                });
                queue = makeQueue({ max: QUEUE_MAX, onBusy: (on) => (this.busy = on) });
                mo = new MutationObserver((records) => this.observed(records));
                mo.observe(this.$refs.grid, { childList: true, subtree: true, attributes: true, attributeFilter: [...WATCH, 'tabindex'] });
                this.$nextTick(() => this.settle());
            },

            destroy() {
                if (mo) {
                    mo.disconnect();
                }
                clearTimeout(this.msgTimer);
                if (dnd) {
                    dnd.end();
                }
            },

            // ---- reading the rows the server drew -------------------------------------------------
            // One index per drawing of the rows: [{ k, parent, lv, i, el }] in order, and by key.
            index() {
                if (!ix) {
                    const els = Array.from(this.$refs.grid.querySelectorAll(':scope > [data-tree-row]'));
                    const by = new Map();
                    const rows = els.map((el, i) => {
                        const r = { k: el.dataset.k, parent: el.dataset.parent || null, lv: Number(el.dataset.lv), i, el };
                        by.set(r.k, r);
                        return r;
                    });
                    ix = { rows, by };
                }
                return ix;
            },
            // The rows changed under us: read them again, and let every row re-check its collapse.
            reindex() {
                ix = null;
                this.ver++;
                this.settle();
            },
            // The tab stops written again; the observer is not told about the tree's own writes.
            settle() {
                this.rove(true);
                if (mo) {
                    mo.takeRecords();
                }
            },
            observed(records) {
                const grid = this.$refs.grid;
                const ph = this.$refs.ph;
                let rows = false;
                let stops = false;
                for (const r of records) {
                    if (ph.contains(r.target)) {
                        continue; // the placeholder's own label
                    }
                    if (r.type === 'attributes') {
                        if (r.attributeName === 'tabindex') {
                            stops = true;
                        } else {
                            rows = true;
                        }
                    } else if (r.target === grid) {
                        const nodes = [...r.addedNodes, ...r.removedNodes];
                        if (nodes.some((n) => n.nodeType === 1 && n !== ph)) {
                            rows = true;
                        }
                    } else {
                        stops = true; // a row's inside was redrawn (a new toggle, new buttons)
                    }
                }
                if (rows) {
                    this.reindex();
                } else if (stops) {
                    this.settle();
                }
            },
            rec(k) {
                return this.index().by.get(k) || null;
            },
            row(k) {
                const r = this.rec(k);
                return r ? r.el : null;
            },
            parentOf(k) {
                const r = this.rec(k);
                return r ? r.parent : null;
            },
            levelOf(k) {
                const r = this.rec(k);
                return r ? r.lv : 1;
            },
            nameOf(k) {
                const el = this.row(k);
                return el ? el.dataset.name : String(k);
            },
            lockOf(k) {
                const el = this.row(k);
                return el && el.dataset.lock ? el.dataset.lock : null;
            },
            // The children of a parent (null = the outer level), in order, leaving one key out.
            kids(parent, except = null) {
                return kidsOf(this.index().rows, parent, except);
            },
            // How many levels the record and everything under it take (1 for a record without children).
            height(k) {
                const { rows } = this.index();
                const r = this.rec(k);
                if (!r) {
                    return 1;
                }
                let max = r.lv;
                for (let j = r.i + 1; j < rows.length && rows[j].lv > r.lv; j++) {
                    max = Math.max(max, rows[j].lv);
                }
                return max - r.lv + 1;
            },
            // The rows on screen, for the drag rule.
            shown() {
                return this.index().rows
                    .filter((r) => !r.el.hidden)
                    .map((r) => ({ k: r.k, lv: r.lv, parent: r.parent, fold: r.el.dataset.kids === '1' && !!this.collapsed[r.k] }));
            },

            // ---- collapse / expand ----------------------------------------------------------------
            hid(el) {
                void this.ver;
                const by = this.index().by;
                // el may be a redraw's fresh copy, not in the index yet: start from its own attribute
                let p = el.dataset.parent || null;
                for (let n = 0; p && n < 64; n++) {
                    if (this.collapsed[p]) {
                        return true;
                    }
                    const r = by.get(p);
                    p = r ? r.parent : null;
                }
                return false;
            },
            isOpen(k) {
                return !this.collapsed[k];
            },
            toggle(k) {
                if (this.collapsed[k]) {
                    delete this.collapsed[k];
                } else {
                    this.collapsed[k] = true;
                }
                this.$nextTick(() => this.rove());
            },
            expand(k) {
                delete this.collapsed[k];
            },
            // The row's toggle: its label says what a press does now.
            tg(el) {
                this.toggle(el.closest('[data-tree-row]').dataset.k);
            },
            tgLabel(el) {
                return this.isOpen(el.closest('[data-tree-row]').dataset.k) ? el.dataset.open : el.dataset.shut;
            },

            // ---- one Tab stop: the name of the current row ---------------------------------------
            // The current row's name is the tree's Tab stop and its own buttons follow it; every other
            // name and row button leaves the Tab order (the arrow keys reach them). The toggles are for
            // the pointer: ← and → on a name collapse and expand.
            rove(full = false) {
                const { rows, by } = this.index();
                let r = cur ? by.get(cur) : null;
                while (r && r.el.hidden) {
                    r = r.parent ? by.get(r.parent) : null;
                }
                if (!r) {
                    r = rows.find((x) => !x.el.hidden) || null;
                }
                const next = r ? r.k : null;
                if (full) {
                    rows.forEach((x) => this.stops(x.el, x.k === next));
                } else {
                    const old = cur ? by.get(cur) : null;
                    if (old && old !== r) {
                        this.stops(old.el, false);
                    }
                    if (r) {
                        this.stops(r.el, true);
                    }
                }
                cur = next;
            },
            stops(el, on) {
                setTab(el.querySelector('[data-tree-name]'), on ? '0' : '-1');
                const tg = el.querySelector('button.tree-tg');
                setTab(tg, '-1');
                el.querySelectorAll(ACT_FOCUSABLE).forEach((b) => {
                    if (b.closest('[role="menu"]')) {
                        return; // the ⋮ menu's items keep their own arrow-key order
                    }
                    if (on) {
                        if (b.hasAttribute('data-tree-rv')) {
                            b.removeAttribute('data-tree-rv');
                            b.removeAttribute('tabindex');
                        }
                    } else if (b.getAttribute('tabindex') !== '-1') {
                        b.setAttribute('data-tree-rv', '');
                        b.setAttribute('tabindex', '-1');
                    }
                });
            },
            focused(e) {
                const el = e.target.closest ? e.target.closest('[data-tree-row]') : null;
                if (el && el.dataset.k !== cur && this.$refs.grid.contains(el)) {
                    const old = cur;
                    cur = el.dataset.k;
                    const r = this.rec(old);
                    if (r) {
                        this.stops(r.el, false);
                    }
                    this.stops(el, true);
                }
            },

            // ---- the rule shared by dragging and the keyboard -------------------------------------
            // Null when the record may go under `parent` at `level`, else { ok: false, label, why }.
            allow(k, parent, level) {
                const lock = parent ? this.lockOf(parent) : null;
                if (lock) {
                    const label = fill(t.lockedTarget, { name: this.nameOf(parent) });
                    return { ok: false, label, why: label + ': ' + lock + '.' };
                }
                const max = cfg.maxDepth;
                const h = this.height(k);
                if (max && level + h - 1 > max) {
                    const why = fill(t.tooDeepWhy, { max }) + (h > 1 ? ' ' + fill(t.tooDeepKids, { name: this.nameOf(k), n: h - 1 }) : '');
                    return { ok: false, label: fill(t.tooDeep, { max }), why };
                }
                return null;
            },

            // ---- dragging ---------------------------------------------------------------------------
            dragStart(e) {
                const el = e.target.closest ? e.target.closest('[data-tree-row]') : null;
                if (!el) {
                    e.preventDefault();
                    return;
                }
                if (this.busy) {
                    e.preventDefault();
                    this.note(t.wait, false);
                    return;
                }
                const k = el.dataset.k;
                if (this.lockOf(k)) {
                    e.preventDefault();
                    this.note(fill(t.lockedSelf, { name: this.nameOf(k), why: this.lockOf(k) }), true);
                    return;
                }
                // The placeholder takes the dragged row's height, so the rows below move by one row.
                this.$refs.grid.style.setProperty('--tree-phh', Math.round(el.getBoundingClientRect().height) + 'px');
                this.dragK = k;
                el.setAttribute('data-drag', '1');
                dnd.start(e, k, el);
            },
            dragOver(e) {
                if (!this.dragK) {
                    return;
                }
                if (this.$refs.ph.contains(e.target)) {
                    dnd.overPh(e, this.shown());
                    return;
                }
                const el = e.target.closest ? e.target.closest('[data-tree-row]') : null;
                if (el && !el.hidden) {
                    dnd.over(e, el, el.dataset.k, this.shown());
                } else {
                    e.preventDefault();
                }
            },
            showTarget(T) {
                const ph = this.$refs.ph;
                if (dropEl) {
                    dropEl.removeAttribute('data-drop');
                    dropEl = null;
                }
                if (T.mode === 'in' && !T.self) {
                    const target = this.row(T.refK);
                    if (target) {
                        target.setAttribute('data-drop', T.bad ? 'bad' : 'in');
                        dropEl = target;
                    }
                }
                const at = this.row(T.ph.k);
                if (!at) {
                    return;
                }
                ph.setAttribute('data-lv', String(Math.max(1, T.lv || 1)));
                ph.setAttribute('data-bad', T.bad ? '1' : '0');
                ph.querySelector('[data-tree-ph-text]').textContent = T.label;
                at.parentNode.insertBefore(ph, T.ph.at === 'before' ? at : at.nextSibling);
                ph.hidden = false;
            },
            dragDone() {
                const T = dnd.end();
                const k = this.dragK;
                this.clearDrag();
                if (!k || !T) {
                    return;
                }
                if (T.bad) {
                    this.note(fill(t.kept, { why: T.why || T.label }), true);
                    return;
                }
                const parent = T.parent || null;
                const name = this.nameOf(k);
                const text = T.rel === 'child'
                    ? fill(t.movedChild, { name, parent: this.nameOf(parent) })
                    : fill(T.rel === 'before' ? t.movedBefore : t.movedAfter, { name, ref: this.nameOf(T.refK) });
                // the place among the siblings is worked out when its turn comes
                this.enqueue(() => this.call(k, parent, dropPosition(T, this.kids(parent, k)), text));
            },
            // The drag ended without a drop on the tree (Esc, or let go elsewhere): nothing moves.
            dragCancel() {
                dnd.end();
                if (this.dragK) {
                    this.clearDrag();
                    this.say(t.dragCancelled);
                }
            },
            clearDrag() {
                const ph = this.$refs.ph;
                ph.hidden = true;
                ph.querySelector('[data-tree-ph-text]').textContent = '';
                this.$refs.grid.appendChild(ph); // back at its place after the rows, as the server drew it
                if (dropEl) {
                    dropEl.removeAttribute('data-drop');
                    dropEl = null;
                }
                const el = this.dragK ? this.row(this.dragK) : null;
                if (el) {
                    el.removeAttribute('data-drag');
                }
                this.dragK = null;
            },

            // ---- the keyboard -----------------------------------------------------------------------
            keydown(e) {
                const target = e.target;
                if (target.closest && target.closest('input,textarea,select,[contenteditable="true"]')) {
                    return;
                }
                // Ctrl+Z / Ctrl+Shift+Z: only the tree that holds the focus
                if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'z' || e.key === 'Z')) {
                    e.preventDefault();
                    if (e.shiftKey) {
                        this.redo();
                    } else {
                        this.undo();
                    }
                    return;
                }
                const el = target.closest ? target.closest('[data-tree-row]') : null;
                if (!el || !this.$refs.grid.contains(el)) {
                    return;
                }
                const k = el.dataset.k;
                if (e.altKey && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                    const act = { ArrowUp: 'moveUp', ArrowDown: 'moveDown', ArrowRight: 'indent', ArrowLeft: 'outdent' }[e.key];
                    if (act) {
                        e.preventDefault();
                        this.move(act, k);
                    }
                    return;
                }
                if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey || !target.matches('[data-tree-name]')) {
                    return;
                }
                const names = this.index().rows.filter((r) => !r.el.hidden).map((r) => r.el);
                const i = names.indexOf(el);
                let to = null;
                if (e.key === 'ArrowDown') {
                    to = names[i + 1];
                } else if (e.key === 'ArrowUp') {
                    to = names[i - 1];
                } else if (e.key === 'Home') {
                    to = names[0];
                } else if (e.key === 'End') {
                    to = names[names.length - 1];
                } else if (e.key === 'ArrowRight' && el.dataset.kids === '1') {
                    if (this.collapsed[k]) {
                        this.expand(k);
                        e.preventDefault();
                        return;
                    }
                    to = names[i + 1];
                } else if (e.key === 'ArrowLeft') {
                    if (el.dataset.kids === '1' && !this.collapsed[k]) {
                        this.toggle(k);
                        e.preventDefault();
                        return;
                    }
                    to = el.dataset.parent ? this.row(el.dataset.parent) : null;
                } else {
                    return;
                }
                e.preventDefault();
                this.focusRow(to);
            },
            focusRow(el) {
                const name = el ? el.querySelector('[data-tree-name]') : null;
                if (name) {
                    this.focused({ target: el });
                    name.focus();
                }
            },
            refuseLocked(k) {
                const lock = this.lockOf(k);
                if (lock) {
                    this.note(fill(t.lockedSelf, { name: this.nameOf(k), why: lock }), true);
                }
                return !!lock;
            },
            // The keyboard and the ⋮ menu: each waits its turn, then reads the tree as it is by then.
            move(act, k) {
                this.enqueue(() => this.runMove(act, k));
            },
            moveUp(k) {
                this.move('moveUp', k);
            },
            moveDown(k) {
                this.move('moveDown', k);
            },
            indent(k) {
                this.move('indent', k);
            },
            outdent(k) {
                this.move('outdent', k);
            },
            // A ⋮ menu item (<x-bs-admin::tree.move-menu>): the move of its row, unless it is greyed.
            go(act, el) {
                const row = el.closest('[data-tree-row]');
                if (row && el.getAttribute('aria-disabled') !== 'true') {
                    this.move(act, row.dataset.k);
                }
            },
            runMove(act, k) {
                if (!this.rec(k) || this.refuseLocked(k)) {
                    return null;
                }
                const plan = planMove(act, this.index().rows, k);
                const name = this.nameOf(k);
                if (plan.no) {
                    if (plan.no !== 'gone') {
                        this.note(fill(t[plan.no], { name }), false);
                    }
                    return null;
                }
                if (act === 'indent' || act === 'outdent') {
                    const no = this.allow(k, plan.parent, plan.lv);
                    if (no) {
                        this.note(fill(t.kept, { why: no.why }), true);
                        return null;
                    }
                }
                const text = {
                    moveUp: fill(t.movedUp, { name }),
                    moveDown: fill(t.movedDown, { name }),
                    indent: fill(t.movedIn, { name, parent: this.nameOf(plan.parent) }),
                    outdent: fill(t.movedOut, { name }),
                }[act];
                return this.call(k, plan.parent, plan.pos, text);
            },
            undo() {
                this.enqueue(() => {
                    const last = history.takeUndo();
                    return last && this.rec(last.k) ? this.call(last.k, last.parent, last.pos, t.undone, 'undo') : null;
                });
            },
            redo() {
                this.enqueue(() => {
                    const next = history.takeRedo();
                    return next && this.rec(next.k) ? this.call(next.k, next.parent, next.pos, t.redone, 'redo') : null;
                });
            },

            // ---- the one server call, one at a time -----------------------------------------------------
            enqueue(job) {
                if (!queue.enqueue(job)) {
                    this.note(t.wait, false); // the line is full: say so instead of dropping the input
                }
            },
            // kind: 'do' (a new move: clears the redo list) · 'undo' · 'redo'.
            async call(k, parent, pos, okText, kind = 'do') {
                const from = this.parentOf(k);
                const fromPos = this.kids(from).indexOf(k);
                if (from === parent && fromPos === pos) {
                    return;
                }
                const moving = this.row(k);
                if (moving) {
                    moving.setAttribute('data-saving', '1');
                }
                this.saving();
                let answer;
                try {
                    answer = await this.$wire.$call(cfg.move, k, parent, pos);
                } catch {
                    if (moving) {
                        moving.removeAttribute('data-saving');
                    }
                    history.giveBack({ k, parent, pos }, kind); // a lost call keeps its undo / redo entry
                    this.note(t.offline, true);
                    await this.refresh();
                    return;
                }
                if (moving) {
                    moving.removeAttribute('data-saving');
                }
                this.reindex(); // the call resolves after the server's redraw: read the new order
                if (typeof answer === 'string' && answer !== '') {
                    this.note(fill(t.kept, { why: answer }), true);
                    await this.refresh();
                    return;
                }
                history.record({ k, parent: from, pos: fromPos }, kind);
                if (parent) {
                    this.expand(parent);
                }
                this.note(okText, false, kind !== 'undo');
                await this.$nextTick();
                const el = this.row(k);
                if (!el) {
                    return;
                }
                el.setAttribute('data-flash', '1');
                setTimeout(() => el.removeAttribute('data-flash'), 1100);
                if (this.$el.contains(document.activeElement) || document.activeElement === document.body) {
                    this.focusRow(el);
                }
            },
            async refresh() {
                try {
                    await this.$wire.$refresh();
                } catch {
                    // still offline: the rows stay as last drawn
                }
                this.reindex();
            },

            // ---- what the reader is told --------------------------------------------------------------
            // Polite for what a drag will do and for done moves; assertive only for a refused move.
            say(text, loud = false) {
                const on = loud ? this.$refs.alert : this.$refs.live;
                const off = loud ? this.$refs.live : this.$refs.alert;
                if (off.textContent) {
                    off.textContent = '';
                }
                if (on.textContent !== text) {
                    on.textContent = text;
                }
            },
            // "Đang lưu…" while a call is out: the line shows it, the reader is told the result after.
            saving() {
                clearTimeout(this.msgTimer);
                this.msg = t.saving;
                this.msgBad = false;
                this.msgUndo = false;
            },
            // A result line under the tree: a done move stays 10 s with "Hoàn tác" (the count stops while
            // the pointer or the focus is on it); a refusal stays until closed.
            note(text, bad, undo = false) {
                clearTimeout(this.msgTimer);
                this.msg = text;
                this.msgBad = bad;
                this.msgUndo = undo;
                this.say(text, bad);
                if (!bad) {
                    this.msgLeft = undo ? UNDO_MS : UNDO_MS / 2;
                    this.msgRun();
                }
            },
            msgRun() {
                if (this.msgBad || !this.msg) {
                    return;
                }
                clearTimeout(this.msgTimer);
                this.msgStart = Date.now();
                this.msgTimer = setTimeout(() => this.msgClose(), this.msgLeft);
            },
            msgHold() {
                if (this.msgBad || !this.msg) {
                    return;
                }
                clearTimeout(this.msgTimer);
                this.msgLeft = Math.max(3000, this.msgLeft - (Date.now() - this.msgStart));
            },
            msgClose() {
                clearTimeout(this.msgTimer);
                if (this.busy) {
                    this.saving(); // a note ending while a call is out must not hide "Đang lưu…"
                    return;
                }
                this.msg = '';
                this.msgUndo = false;
            },
        };
    });
});
