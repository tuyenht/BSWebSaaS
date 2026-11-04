// The shared admin tree (<x-bs-admin::tree>, Alpine.data 'bsTree'): collapse and expand, drag and
// drop under the one drag rule (admin-tree-dnd.js), the keyboard moves (Alt+↑/↓ move within the
// group, Alt+→ into the record above, Alt+← out one level, Ctrl+Z undoes) and the arrow keys between
// the names. The server draws every row; this object only reads them. A move is ONE call to the
// screen's Livewire method — (record key, new parent key or null, position among the new parent's
// other children) — which answers null when done or a plain-language reason when it refuses; after a
// refusal (or a lost connection) the tree is read again from the server. Keys are whatever opaque
// string the screen gave each row.
import { createTreeDnd, fill } from './admin-tree-dnd.js';

const UNDO_MS = 10000;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsTree', (cfg) => ({
        collapsed: {},
        msg: '',
        msgBad: false,
        msgUndo: false,
        busy: false,
        dragK: null,
        hist: [],

        init() {
            this.t = cfg.texts;
            this.dnd = createTreeDnd({
                step: () => parseFloat(getComputedStyle(this.$refs.grid).getPropertyValue('--tree-step')) || 32,
                name: (k) => this.nameOf(k),
                check: (T, k) => this.allow(k, T.parent || null, T.lv),
                say: (text) => this.say(text),
                onTarget: (T) => this.showTarget(T),
                onExpand: (k) => this.expand(k),
                texts: this.t.drag,
            });
            this.onUndoKey = (e) => {
                if (!(e.ctrlKey || e.metaKey) || e.shiftKey || e.altKey || (e.key !== 'z' && e.key !== 'Z')) {
                    return;
                }
                const a = document.activeElement;
                if (a && a.closest && a.closest('input,textarea,select,[contenteditable="true"]')) {
                    return;
                }
                if (!this.hist.length || !(this.$el.contains(a) || a === document.body || a === null)) {
                    return;
                }
                e.preventDefault();
                this.undo();
            };
            document.addEventListener('keydown', this.onUndoKey);
        },

        destroy() {
            document.removeEventListener('keydown', this.onUndoKey);
            clearTimeout(this.msgTimer);
            this.dnd.end();
        },

        // ---- reading the rows the server drew -------------------------------------------------
        rowEls() {
            return Array.from(this.$refs.grid.querySelectorAll(':scope > [data-tree-row]'));
        },
        row(k) {
            return this.rowEls().find((el) => el.dataset.k === k) || null;
        },
        parentOf(k) {
            const el = this.row(k);
            return el && el.dataset.parent ? el.dataset.parent : null;
        },
        levelOf(k) {
            const el = this.row(k);
            return el ? Number(el.dataset.lv) : 1;
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
            return this.rowEls()
                .filter((el) => (el.dataset.parent || null) === parent && el.dataset.k !== except)
                .map((el) => el.dataset.k);
        },
        // How many levels the record and everything under it take (1 for a record without children).
        height(k) {
            const els = this.rowEls();
            const i = els.findIndex((el) => el.dataset.k === k);
            if (i < 0) {
                return 1;
            }
            const lv = Number(els[i].dataset.lv);
            let max = lv;
            for (let j = i + 1; j < els.length && Number(els[j].dataset.lv) > lv; j++) {
                max = Math.max(max, Number(els[j].dataset.lv));
            }
            return max - lv + 1;
        },
        // The rows on screen, for the drag rule.
        shown() {
            return this.rowEls()
                .filter((el) => !el.hidden)
                .map((el) => ({ k: el.dataset.k, lv: Number(el.dataset.lv), parent: el.dataset.parent || null, fold: el.dataset.kids === '1' && !!this.collapsed[el.dataset.k] }));
        },

        // ---- collapse / expand ----------------------------------------------------------------
        hid(el) {
            let p = el.dataset.parent;
            while (p) {
                if (this.collapsed[p]) {
                    return true;
                }
                const pe = this.row(p);
                p = pe ? pe.dataset.parent : '';
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
        },
        expand(k) {
            delete this.collapsed[k];
        },

        // ---- the rule shared by dragging and the keyboard -------------------------------------
        // Null when the record may go under `parent` at `level`, else { ok: false, label, why }.
        allow(k, parent, level) {
            const lock = parent ? this.lockOf(parent) : null;
            if (lock) {
                const label = fill(this.t.lockedTarget, { name: this.nameOf(parent) });
                return { ok: false, label, why: label + ': ' + lock + '.' };
            }
            const max = cfg.maxDepth;
            const h = this.height(k);
            if (max && level + h - 1 > max) {
                const why = fill(this.t.tooDeepWhy, { max }) + (h > 1 ? ' ' + fill(this.t.tooDeepKids, { name: this.nameOf(k), n: h - 1 }) : '');
                return { ok: false, label: fill(this.t.tooDeep, { max }), why };
            }
            return null;
        },

        // ---- dragging ---------------------------------------------------------------------------
        dragStart(e) {
            const el = e.target.closest ? e.target.closest('[data-tree-row]') : null;
            if (!el || this.busy) {
                e.preventDefault();
                return;
            }
            const k = el.dataset.k;
            if (this.lockOf(k)) {
                e.preventDefault();
                this.note(fill(this.t.lockedSelf, { name: this.nameOf(k), why: this.lockOf(k) }), true);
                return;
            }
            // The placeholder takes the dragged row's height, so the rows below move by one row.
            this.$refs.grid.style.setProperty('--tree-phh', Math.round(el.getBoundingClientRect().height) + 'px');
            this.dragK = k;
            el.setAttribute('data-drag', '1');
            this.dnd.start(e, k, el);
        },
        dragOver(e) {
            if (!this.dragK) {
                return;
            }
            if (this.$refs.ph.contains(e.target)) {
                this.dnd.overPh(e, this.shown());
                return;
            }
            const el = e.target.closest ? e.target.closest('[data-tree-row]') : null;
            if (el && !el.hidden) {
                this.dnd.over(e, el, el.dataset.k, this.shown());
            } else {
                e.preventDefault();
            }
        },
        showTarget(T) {
            const ph = this.$refs.ph;
            this.rowEls().forEach((el) => el.removeAttribute('data-drop'));
            if (T.mode === 'in' && !T.self) {
                const target = this.row(T.refK);
                if (target) {
                    target.setAttribute('data-drop', T.bad ? 'bad' : 'in');
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
            const T = this.dnd.end();
            const k = this.dragK;
            this.clearDrag();
            if (!k || !T) {
                return;
            }
            if (T.bad) {
                this.note(fill(this.t.kept, { why: T.why || T.label }), true);
                return;
            }
            const parent = T.parent || null;
            const sib = this.kids(parent, k);
            const pos = T.rel === 'child' ? 0 : T.rel === 'before' ? Math.max(0, sib.indexOf(T.refK)) : sib.indexOf(T.afterK) + 1;
            const name = this.nameOf(k);
            const text = T.rel === 'child'
                ? fill(this.t.movedChild, { name, parent: this.nameOf(parent) })
                : fill(T.rel === 'before' ? this.t.movedBefore : this.t.movedAfter, { name, ref: this.nameOf(T.refK) });
            this.send(k, parent, pos, text);
        },
        // The drag ended without a drop on the tree (Esc, or let go elsewhere): nothing moves.
        dragCancel() {
            this.dnd.end();
            if (this.dragK) {
                this.clearDrag();
            }
        },
        clearDrag() {
            const ph = this.$refs.ph;
            ph.hidden = true;
            this.$refs.grid.appendChild(ph); // back at its place after the rows, as the server drew it
            this.rowEls().forEach((el) => {
                el.removeAttribute('data-drop');
                el.removeAttribute('data-drag');
            });
            this.dragK = null;
        },

        // ---- the keyboard -----------------------------------------------------------------------
        keydown(e) {
            const el = e.target.closest ? e.target.closest('[data-tree-row]') : null;
            if (!el || !this.$refs.grid.contains(el) || (e.target.closest && e.target.closest('input,textarea,select,[contenteditable="true"]'))) {
                return;
            }
            const k = el.dataset.k;
            if (e.altKey && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                const act = { ArrowUp: 'moveUp', ArrowDown: 'moveDown', ArrowRight: 'indent', ArrowLeft: 'outdent' }[e.key];
                if (act) {
                    e.preventDefault();
                    this[act](k);
                }
                return;
            }
            if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey || !e.target.matches('[data-tree-name]')) {
                return;
            }
            const names = this.rowEls().filter((r) => !r.hidden);
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
                name.focus();
            }
        },
        refuseLocked(k) {
            const lock = this.lockOf(k);
            if (lock) {
                this.note(fill(this.t.lockedSelf, { name: this.nameOf(k), why: lock }), true);
            }
            return !!lock;
        },
        moveUp(k) {
            if (this.refuseLocked(k)) {
                return;
            }
            const parent = this.parentOf(k);
            const i = this.kids(parent).indexOf(k);
            if (i <= 0) {
                this.note(fill(this.t.atTop, { name: this.nameOf(k) }), false);
                return;
            }
            this.send(k, parent, i - 1, fill(this.t.movedUp, { name: this.nameOf(k) }));
        },
        moveDown(k) {
            if (this.refuseLocked(k)) {
                return;
            }
            const parent = this.parentOf(k);
            const sib = this.kids(parent);
            const i = sib.indexOf(k);
            if (i < 0 || i >= sib.length - 1) {
                this.note(fill(this.t.atBottom, { name: this.nameOf(k) }), false);
                return;
            }
            this.send(k, parent, i + 1, fill(this.t.movedDown, { name: this.nameOf(k) }));
        },
        indent(k) {
            if (this.refuseLocked(k)) {
                return;
            }
            const sib = this.kids(this.parentOf(k));
            const i = sib.indexOf(k);
            if (i <= 0) {
                this.note(this.t.noAbove, false);
                return;
            }
            const target = sib[i - 1];
            const no = this.allow(k, target, this.levelOf(target) + 1);
            if (no) {
                this.note(fill(this.t.kept, { why: no.why }), true);
                return;
            }
            this.send(k, target, this.kids(target, k).length, fill(this.t.movedIn, { name: this.nameOf(k), parent: this.nameOf(target) }));
        },
        outdent(k) {
            if (this.refuseLocked(k)) {
                return;
            }
            const parent = this.parentOf(k);
            if (!parent) {
                this.note(fill(this.t.atOuter, { name: this.nameOf(k) }), false);
                return;
            }
            const up = this.parentOf(parent);
            const no = this.allow(k, up, this.levelOf(parent));
            if (no) {
                this.note(fill(this.t.kept, { why: no.why }), true);
                return;
            }
            this.send(k, up, this.kids(up, k).indexOf(parent) + 1, fill(this.t.movedOut, { name: this.nameOf(k) }));
        },

        // ---- the one server call ----------------------------------------------------------------
        async send(k, parent, pos, okText, record = true) {
            const from = this.parentOf(k);
            const fromPos = this.kids(from).indexOf(k);
            if (from === parent && fromPos === pos) {
                return;
            }
            this.busy = true;
            let answer;
            try {
                answer = await this.$wire.$call(cfg.move, k, parent, pos);
            } catch {
                this.busy = false;
                this.note(this.t.offline, true);
                this.$wire.$refresh();
                return;
            }
            this.busy = false;
            if (typeof answer === 'string' && answer !== '') {
                this.note(fill(this.t.kept, { why: answer }), true);
                this.$wire.$refresh();
                return;
            }
            if (record) {
                this.hist.push({ k, parent: from, pos: fromPos });
                if (this.hist.length > 50) {
                    this.hist.shift();
                }
            }
            if (parent) {
                this.expand(parent);
            }
            this.note(okText, false, record);
            this.$nextTick(() => {
                const el = this.row(k);
                if (!el) {
                    return;
                }
                el.setAttribute('data-flash', '1');
                setTimeout(() => el.removeAttribute('data-flash'), 1100);
                if (this.$el.contains(document.activeElement) || document.activeElement === document.body) {
                    this.focusRow(el);
                }
            });
        },
        undo() {
            const last = this.hist.pop();
            if (last) {
                this.send(last.k, last.parent, last.pos, this.t.undone, false);
            }
        },

        // ---- what the reader is told --------------------------------------------------------------
        say(text) {
            const live = this.$refs.live;
            if (live.textContent !== text) {
                live.textContent = text;
            }
        },
        // A result line under the tree: a done move stays 10 s with "Hoàn tác" (the count stops while
        // the pointer or the focus is on it); a refusal stays until closed.
        note(text, bad, undo = false) {
            clearTimeout(this.msgTimer);
            this.msg = text;
            this.msgBad = bad;
            this.msgUndo = undo;
            this.say(text);
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
            this.msg = '';
            this.msgUndo = false;
        },
    }));
});
