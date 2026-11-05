// The Trình đơn screen (Alpine.data 'bsMenus'): the menu being edited is a draft kept here until
// "Lưu" sends it whole ($wire.saveDraft); undo / redo, the moves (buttons, Alt + arrows, drag under
// the shared drag rule of admin-tree-dnd.js, "Chuyển đến…"), the add panel, the inline editor and
// the "Khách sẽ thấy" preview all work on that draft. Menus, areas and "Tự thêm trang mới" are
// saved at once by their own calls. Every key is the ulid the server gave (or "new:…" for a row
// not saved yet); labels and descriptions are shown as text only (x-text), never as HTML.
import { createTreeDnd, dropPosition, fill, kidsOf, planMove } from './admin-tree-dnd.js';

const HIST_MAX = 50;
const TOAST_MS = 6000;
const TOAST_UNDO_MS = 10000;
const ISSUES_SHOWN = 3;
const BADGES = ['new', 'hot', 'sale', 'soon'];

const clone = (v) => JSON.parse(JSON.stringify(v));
const fold = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();

function emptyOptions() {
    return { hidden: false, new_tab: false, icon: null, description: {}, cta: false, mega: null, badge: null, device: 'all' };
}

// What a typed address becomes: {ok, url, note} or {ok: false, msg}.
export function normUrl(raw, t) {
    const v = String(raw || '').trim().replace(/\s+/g, '');
    if (!v) {
        return { ok: false, msg: '' };
    }
    if (/^(javascript|data|vbscript):/i.test(v)) {
        return { ok: false, msg: t.urlBad };
    }
    if (/^\/(?!\/)/.test(v) || /^https?:\/\/[^/\s]+\.[^/\s]+/i.test(v)) {
        return { ok: true, url: v };
    }
    if (/^(mailto:|tel:)/i.test(v)) {
        return { ok: true, url: v };
    }
    if (/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) {
        return { ok: true, url: 'mailto:' + v, note: t.urlMail };
    }
    const digits = v.replace(/[.\-()]/g, '');
    if (/^\+?\d{8,15}$/.test(digits)) {
        return { ok: true, url: 'tel:' + digits, note: t.urlTel };
    }
    if (/^[a-z0-9-]+(\.[a-z0-9-]+)+(\/.*)?$/i.test(v)) {
        return { ok: true, url: 'https://' + v, note: t.urlHttps };
    }
    return { ok: false, msg: t.urlBad };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsMenus', (cfg) => {
        const t = cfg.texts;
        // Outside Alpine's reactive state: the drag engine, the history lists, timers.
        let dnd = null;
        let past = [];
        let future = [];
        let toastTimer = null;
        let searchTimer = null;
        let seq = 0;
        let lastFocus = null;

        return {
            screen: 'loading', // loading · error · data
            saving: false,
            saveErr: false,
            d: null, // the load() answer without the open menu
            menu: null, // the open menu: {key, name, autoAdd, version, limits, trash}
            items: [], // the draft, in display order: {key, parent, link, labels, options, source}
            saved: '{}', // key → JSON of each saved row, to tell what changed
            savedOrder: '',
            histN: 0,
            futN: 0,
            loc: cfg.main,
            sel: null,
            rowMenu: null,
            menuMore: null,
            renaming: null,
            renameVal: '',
            fold: {},
            q: '',
            filter: 'all',
            trashOpen: false,
            issues: [],
            issuesAll: false,
            fold2: { check: true, areas: true, auto: true }, // the side cards start folded; the tree stays open
            advOn: false,
            devOn: false,
            device: 'pc',
            pvOpen: null,
            tab: 'page',
            search: '',
            results: [],
            searching: false,
            picked: {},
            addTo: 'end',
            newLabel: '',
            newUrl: '',
            iconOpen: false,
            iconQ: '',
            dragK: null,
            drop: null,
            mv: null,
            ask: null,
            toast: null,
            live: '',

            init() {
                dnd = createTreeDnd({
                    step: () => 22,
                    name: (k) => this.name(this.item(k)),
                    check: (T, k) => this.allow(k, T.lv),
                    say: (text) => { this.live = text; },
                    onTarget: (T) => { this.drop = { k: T.ph.k, at: T.ph.at, lv: T.lv || 1, bad: !!T.bad, label: T.label, refK: T.mode === 'in' ? T.refK : null }; },
                    onExpand: (k) => { this.fold[k] = false; },
                    texts: t.drag,
                });
                this.take(cfg.initial);
                window.addEventListener('beforeunload', (e) => {
                    if (this.dirty().n) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });
            },

            // ---- loading ------------------------------------------------------------------------
            take(res) {
                if (!res || !res.ok) {
                    this.screen = 'error';
                    return;
                }
                const { menu, ...rest } = res;
                this.d = rest;
                if (!rest.locales.some((l) => l.code === this.loc)) {
                    this.loc = cfg.main;
                }
                this.open(menu);
                this.screen = 'data';
                this.check();
            },
            open(menu) {
                this.sel = null;
                this.rowMenu = null;
                this.trashOpen = false;
                this.fold = {};
                past = [];
                future = [];
                this.histN = 0;
                this.futN = 0;
                if (!menu) {
                    this.menu = null;
                    this.items = [];
                    this.saved = '{}';
                    this.savedOrder = '';
                    return;
                }
                const { items, ...head } = menu;
                this.menu = head;
                this.items = items.map((it) => ({ ...it, labels: { ...it.labels }, options: { ...emptyOptions(), ...it.options } }));
                this.markSaved();
            },
            markSaved() {
                const map = {};
                this.items.forEach((it) => { map[it.key] = this.sig(it); });
                this.saved = JSON.stringify(map);
                this.savedOrder = this.order();
            },
            async reload(key = this.menu ? this.menu.key : null) {
                this.screen = this.d ? this.screen : 'loading';
                try {
                    const res = await this.$wire.load(key);
                    if (!res.ok && key) {
                        this.take(await this.$wire.load(null));
                        return;
                    }
                    this.take(res);
                } catch {
                    this.screen = 'error';
                }
            },
            async check() {
                try {
                    const res = await this.$wire.selfCheck(this.menu ? this.menu.key : null);
                    this.issues = res.ok ? res.issues : [];
                } catch {
                    this.issues = [];
                }
            },

            // ---- reading the draft --------------------------------------------------------------
            item(k) {
                return this.items.find((it) => it.key === k) || null;
            },
            locName(code) {
                const l = this.d ? this.d.locales.find((x) => x.code === code) : null;
                return l ? l.name : String(code).toUpperCase();
            },
            get multi() {
                return !!this.d && this.d.locales.length > 1;
            },
            get onMain() {
                return this.loc === cfg.main;
            },
            special(key) {
                return (this.d ? this.d.specials : []).find((s) => s.key === key) || null;
            },
            fallbackName(it) {
                if (!it) {
                    return '';
                }
                if (it.link.type === 'special') {
                    const s = this.special(it.link.key);
                    return s ? s.label : it.link.key;
                }
                if (it.link.type === 'url') {
                    return it.link.url || '';
                }
                return it.source && it.source.title ? it.source.title : '';
            },
            label(it, loc = this.loc) {
                return it && it.labels[loc] ? it.labels[loc] : '';
            },
            name(it) {
                return this.label(it) || this.label(it, cfg.main) || this.fallbackName(it) || t.noName;
            },
            kind(it) {
                if (it.link.type === 'url') {
                    return 'url';
                }
                if (it.link.type === 'special') {
                    return 'special';
                }
                return it.source ? it.source.kind : it.link.type;
            },
            kindName(it) {
                return t.kinds[this.kind(it)] || '';
            },
            depthMap() {
                const dep = {};
                this.items.forEach((it) => { dep[it.key] = it.parent && dep[it.parent] !== undefined ? dep[it.parent] + 1 : 0; });
                return dep;
            },
            kids(k) {
                return this.items.filter((it) => (it.parent || null) === k);
            },
            hasKids(k) {
                return this.items.some((it) => it.parent === k);
            },
            block(k) {
                // k and everything under it: a contiguous run in display order
                const i = this.items.findIndex((it) => it.key === k);
                if (i < 0) {
                    return [];
                }
                const dep = this.depthMap();
                let j = i + 1;
                while (j < this.items.length && dep[this.items[j].key] > dep[k]) {
                    j++;
                }
                return this.items.slice(i, j);
            },
            height(k) {
                const dep = this.depthMap();
                return this.block(k).reduce((h, it) => Math.max(h, dep[it.key] - dep[k] + 1), 1);
            },
            get maxDepth() {
                return this.menu && this.menu.limits ? this.menu.limits.depth : 3;
            },
            treeRows() {
                const dep = this.depthMap();
                return this.items.map((it) => ({ k: it.key, parent: it.parent || null, lv: dep[it.key] + 1 }));
            },
            srcState(it) {
                return it.source && it.source.state !== 'live' ? it.source.state : null;
            },
            untranslated(it) {
                return !this.onMain && !this.label(it);
            },
            overDeep(it, dep) {
                return dep[it.key] + 1 > this.maxDepth;
            },
            needsLook(it, dep) {
                return !!this.srcState(it) || (it.source && it.source.untranslated && it.source.untranslated.length > 0) || this.overDeep(it, dep);
            },
            get filtering() {
                return this.q.trim() !== '' || this.filter !== 'all';
            },
            filters() {
                const dep = this.depthMap();
                const list = [
                    { k: 'all', l: t.fAll, n: this.items.length, title: t.fAllTip },
                    { k: 'issue', l: t.fIssue, n: this.items.filter((it) => this.needsLook(it, dep)).length, title: t.fIssueTip },
                    { k: 'hidden', l: t.fHidden, n: this.items.filter((it) => it.options.hidden).length, title: t.fHiddenTip },
                ];
                if (!this.onMain) {
                    list.push({ k: 'untr', l: fill(t.fUntr, { code: this.loc.toUpperCase() }), n: this.items.filter((it) => this.untranslated(it)).length, title: fill(t.fUntrTip, { lang: this.locName(this.loc) }) });
                }
                return list;
            },
            rows() {
                const dep = this.depthMap();
                const q = fold(this.q.trim());
                if (this.filtering) {
                    return this.items.filter((it) => {
                        if (q && !fold(this.name(it)).includes(q)) {
                            return false;
                        }
                        if (this.filter === 'issue') {
                            return this.needsLook(it, dep);
                        }
                        if (this.filter === 'hidden') {
                            return it.options.hidden;
                        }
                        if (this.filter === 'untr') {
                            return this.untranslated(it);
                        }
                        return true;
                    }).map((it) => this.row(it, dep, 0));
                }
                const out = [];
                const hidden = {};
                this.items.forEach((it) => {
                    if (it.parent && (hidden[it.parent] || this.fold[it.parent])) {
                        hidden[it.key] = true;
                        return;
                    }
                    out.push(this.row(it, dep, dep[it.key]));
                });
                return out;
            },
            row(it, dep, indent) {
                const state = this.srcState(it);
                return {
                    it,
                    k: it.key,
                    indent,
                    lv: dep[it.key] + 1,
                    name: this.name(it),
                    kids: this.hasKids(it.key),
                    state,
                    stateLabel: state ? t.state[state] : '',
                    untr: it.source && it.source.untranslated && it.source.untranslated.length > 0,
                    deep: this.overDeep(it, dep),
                    unsaved: this.isChanged(it),
                    src: this.kindName(it).charAt(0).toUpperCase() + this.kindName(it).slice(1),
                };
            },
            srcText(it) {
                if (it.link.type === 'url') {
                    return it.link.url;
                }
                if (it.link.type === 'special') {
                    return t.kinds.special;
                }
                return it.source && it.source.slug ? '/' + it.source.slug : this.kindName(it);
            },
            isChanged(it) {
                const saved = JSON.parse(this.saved);
                return !(it.key in saved) || this.body(saved[it.key]) !== this.body(it);
            },
            // what a row says (link, labels, options), without where it sits
            body(v) {
                return typeof v === 'string' ? JSON.stringify(JSON.parse(v).slice(1)) : JSON.stringify([v.link, v.labels, v.options]);
            },
            sig(it) {
                return JSON.stringify([it.parent || null, it.link, it.labels, it.options]);
            },
            order() {
                return this.items.map((it) => it.key + '<' + (it.parent || '')).join(',');
            },
            dirty() {
                const saved = JSON.parse(this.saved);
                let changed = 0;
                let added = 0;
                const here = {};
                this.items.forEach((it) => {
                    here[it.key] = true;
                    if (!(it.key in saved)) {
                        added++;
                    } else if (this.body(saved[it.key]) !== this.body(it)) {
                        changed++;
                    }
                });
                const removed = Object.keys(saved).filter((k) => !here[k]).length;
                const moved = this.order() !== this.savedOrder && !added && !removed;
                const parts = [];
                if (changed) {
                    parts.push(fill(t.dEdited, { n: changed }));
                }
                if (added) {
                    parts.push(fill(t.dAdded, { n: added }));
                }
                if (removed) {
                    parts.push(fill(t.dRemoved, { n: removed }));
                }
                if (moved) {
                    parts.push(t.dOrder);
                }
                return { n: changed + added + removed + (moved ? 1 : 0), text: parts.join(' · ') };
            },
            get saveLabel() {
                const n = this.dirty().n;
                return n ? fill(t.saveN, { n }) : t.save;
            },

            // ---- history ------------------------------------------------------------------------
            snap() {
                return JSON.stringify(this.items);
            },
            change(fn, keepSel = true) {
                const before = this.snap();
                fn();
                if (this.snap() !== before) {
                    past.push(before);
                    if (past.length > HIST_MAX) {
                        past.shift();
                    }
                    future = [];
                    this.histN = past.length;
                    this.futN = 0;
                }
                if (!keepSel && this.sel && !this.item(this.sel)) {
                    this.sel = null;
                }
            },
            undo() {
                if (!past.length) {
                    return;
                }
                const k = this.focusedRow();
                future.push(this.snap());
                this.items = JSON.parse(past.pop());
                this.after(t.undone, k);
            },
            redo() {
                if (!future.length) {
                    return;
                }
                const k = this.focusedRow();
                past.push(this.snap());
                this.items = JSON.parse(future.pop());
                this.after(t.redone, k);
            },
            after(text, k = null) {
                this.histN = past.length;
                this.futN = future.length;
                if (this.sel && !this.item(this.sel)) {
                    this.sel = null;
                }
                this.live = text;
                this.toastClose();
                if (k && this.item(k)) {
                    this.focusRow(k, true);
                }
            },
            // The row holding the keyboard focus, if any.
            focusedRow() {
                const row = document.activeElement && document.activeElement.closest('[data-mn-row]');
                return row ? row.getAttribute('data-mn-row') : null;
            },
            reset() {
                this.ask = {
                    title: t.resetTitle,
                    body: fill(t.resetBody, { what: this.dirty().text }),
                    ok: t.resetOk,
                    run: () => {
                        const saved = JSON.parse(this.saved);
                        this.change(() => {
                            this.items = this.items.filter((it) => it.key in saved);
                        });
                        this.reload();
                    },
                };
            },

            // ---- editing rows -------------------------------------------------------------------
            set(k, fn) {
                this.change(() => {
                    const it = this.item(k);
                    if (it) {
                        fn(it);
                    }
                });
            },
            setLabel(k, v) {
                this.set(k, (it) => { it.labels = { ...it.labels, [this.loc]: v }; });
            },
            setOpt(k, name, v) {
                this.set(k, (it) => { it.options = { ...it.options, [name]: v }; });
            },
            setDesc(k, v) {
                this.set(k, (it) => { it.options = { ...it.options, description: { ...it.options.description, [this.loc]: v } }; });
            },
            setUrl(k, v) {
                this.set(k, (it) => { it.link = { type: 'url', url: v.trim() }; });
            },
            // The source's own edit screen (pages, posts, products), or null when it has none.
            srcEdit(it) {
                const tpl = it.link.ref && it.source && it.source.state !== 'gone' ? (cfg.edit || {})[it.link.type] : null;
                return tpl ? tpl.replace('__REF__', encodeURIComponent(it.link.ref)) : null;
            },
            editUrl(k, raw) {
                const r = normUrl(raw, t);
                if (r.ok) {
                    this.setUrl(k, r.url);
                } else {
                    this.say(r.msg, false);
                }
            },
            setBadge(k, kind) {
                this.set(k, (it) => {
                    it.options = { ...it.options, badge: BADGES.includes(kind) ? { kind, until: it.options.badge ? it.options.badge.until : null } : null };
                });
            },
            setBadgeUntil(k, v) {
                this.set(k, (it) => {
                    if (it.options.badge) {
                        it.options = { ...it.options, badge: { ...it.options.badge, until: v || null } };
                    }
                });
            },
            setMega(k, on) {
                this.setOpt(k, 'mega', on ? { type: 'columns', columns: 3 } : null);
            },
            setCols(k, n) {
                this.setOpt(k, 'mega', { type: 'columns', columns: parseInt(n, 10) || 3 });
            },
            pick(k) {
                this.sel = this.sel === k ? null : k;
                this.rowMenu = null;
                this.iconOpen = false;
                if (this.sel) {
                    this.$nextTick(() => {
                        const el = document.getElementById('mn-ed-label');
                        if (el) {
                            el.focus();
                        }
                    });
                }
            },
            closeEditor() {
                const k = this.sel;
                this.sel = null;
                this.focusRow(k);
            },
            labelErr(it) {
                return this.onMain && !this.label(it) && !this.fallbackName(it);
            },
            descMax(it) {
                const dep = this.depthMap();
                return dep[it.key] === 0 && it.options.mega ? 160 : 90;
            },
            canMega(it) {
                const dep = this.depthMap();
                return dep[it.key] === 0 && !!(this.menu && this.menu.limits && this.menu.limits.mega);
            },
            remove(k) {
                const it = this.item(k);
                if (!it) {
                    return;
                }
                const name = this.name(it);
                const n = this.block(k).length - 1;
                const run = () => {
                    this.change(() => {
                        const gone = new Set(this.block(k).map((x) => x.key));
                        this.items = this.items.filter((x) => !gone.has(x.key));
                    }, false);
                    if (this.sel === k) {
                        this.sel = null;
                    }
                    this.say(fill(n ? t.removedKids : t.removed, { name, n }), true);
                };
                this.rowMenu = null;
                if (n) {
                    this.ask = { title: fill(t.removeKidsTitle, { n }), body: fill(t.removeKidsBody, { name, n }), ok: t.removeKidsOk, danger: true, run };
                } else {
                    run();
                }
            },
            toggleHide(k) {
                const it = this.item(k);
                this.setOpt(k, 'hidden', !it.options.hidden);
                this.rowMenu = null;
                this.say(fill(it.options.hidden ? t.hiddenNow : t.shownNow, { name: this.name(it) }), true);
            },
            revert(k) {
                const saved = JSON.parse(this.saved);
                if (!(k in saved)) {
                    return;
                }
                const [, link, labels, options] = JSON.parse(saved[k]);
                this.set(k, (it) => { it.link = link; it.labels = labels; it.options = options; });
                this.rowMenu = null;
            },
            async copy(k) {
                this.rowMenu = null;
                const it = this.item(k);
                if (this.dirty().n) {
                    // copying works on the saved menu: a draft with changes is refused (draft_stale)
                    this.say(t.copyNeedsSave, false);
                    return;
                }
                const res = await this.call(() => this.$wire.copyItem(this.menu.key, k, this.menu.version));
                if (res) {
                    await this.reload();
                    this.say(fill(t.copied, { name: this.name(it) }));
                    this.focusRow(res.item);
                }
            },

            // ---- moves --------------------------------------------------------------------------
            allow(k, lv) {
                const h = this.height(k);
                if (lv + h - 1 > this.maxDepth) {
                    return { ok: false, label: fill(t.tooDeep, { max: this.maxDepth }), why: fill(t.tooDeep, { max: this.maxDepth }) };
                }
                return null;
            },
            place(k, parent, pos) {
                // Move k with what is under it: under parent (null = top level), at pos among its other children.
                const blockRows = this.block(k);
                const rest = this.items.filter((x) => !blockRows.includes(x));
                const sib = rest.filter((x) => (x.parent || null) === parent);
                let at;
                if (pos < sib.length) {
                    at = rest.indexOf(sib[pos]);
                } else if (sib.length) {
                    const last = sib[sib.length - 1];
                    const tmp = this.items;
                    this.items = rest;
                    const lb = this.block(last.key);
                    this.items = tmp;
                    at = rest.indexOf(lb[lb.length - 1]) + 1;
                } else {
                    at = parent ? rest.findIndex((x) => x.key === parent) + 1 : rest.length;
                }
                blockRows[0].parent = parent;
                rest.splice(at, 0, ...blockRows);
                this.items = rest;
            },
            move(act, k) {
                if (this.filtering) {
                    this.say(t.noMoveFiltered, false);
                    return;
                }
                const plan = planMove(act, this.treeRows(), k);
                if (plan.no) {
                    this.say(fill(t.no[plan.no] || '', { name: this.name(this.item(k)) }), false);
                    return;
                }
                const why = this.allow(k, plan.lv);
                if (why) {
                    this.say(why.why, false);
                    return;
                }
                this.change(() => this.place(k, plan.parent, plan.pos));
                if (plan.target) {
                    this.fold[plan.target] = false;
                }
                this.rowMenu = null;
                const it = this.item(k);
                this.live = fill(t.movedLive, { name: this.name(it), lv: plan.lv });
                this.focusRow(k, true);
            },
            rowKey(e, k) {
                if (!e.altKey || this.filtering) {
                    return;
                }
                const act = { ArrowUp: 'moveUp', ArrowDown: 'moveDown', ArrowRight: 'indent', ArrowLeft: 'outdent' }[e.key];
                if (act) {
                    e.preventDefault();
                    this.move(act, k);
                }
            },
            keys(e) {
                const tag = (e.target.tagName || '').toLowerCase();
                const typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
                if ((e.ctrlKey || e.metaKey) && !typing && e.key.toLowerCase() === 'z') {
                    e.preventDefault();
                    if (e.shiftKey) {
                        this.redo();
                    } else {
                        this.undo();
                    }
                } else if ((e.ctrlKey || e.metaKey) && !typing && e.key.toLowerCase() === 'y') {
                    e.preventDefault();
                    this.redo();
                } else if (e.key === 'Escape') {
                    this.escape();
                }
            },
            escape() {
                if (this.ask) {
                    this.askClose();
                } else if (this.mv) {
                    this.mvClose();
                } else if (this.iconOpen) {
                    this.iconOpen = false;
                } else if (this.rowMenu) {
                    const k = this.rowMenu;
                    this.rowMenu = null;
                    this.focusMore(k);
                } else if (this.menuMore) {
                    const k = this.menuMore;
                    this.menuMore = null;
                    const b = document.querySelector('[data-mn-mm="' + k + '"]');
                    if (b) {
                        b.focus();
                    }
                } else if (this.renaming) {
                    this.renaming = null;
                } else if (this.sel) {
                    this.closeEditor();
                }
            },
            focusRow(k, keepButton = false) {
                if (!k) {
                    return;
                }
                this.$nextTick(() => {
                    const row = document.querySelector('[data-mn-row="' + k + '"]');
                    if (!row) {
                        return;
                    }
                    row.scrollIntoView({ block: 'nearest' });
                    const active = document.activeElement;
                    if (keepButton && row.contains(active)) {
                        return;
                    }
                    const b = row.querySelector('[data-mn-name]');
                    if (b) {
                        b.focus();
                    }
                });
            },
            focusMore(k) {
                this.$nextTick(() => {
                    const b = document.querySelector('[data-mn-more="' + k + '"]');
                    if (b) {
                        b.focus();
                    }
                });
            },
            toggleFold(k) {
                this.fold[k] = !this.fold[k];
            },
            get anyFolded() {
                return Object.keys(this.fold).some((k) => this.fold[k]);
            },
            foldAll() {
                if (this.anyFolded) {
                    this.fold = {};
                } else {
                    const f = {};
                    this.items.forEach((it) => { if (this.hasKids(it.key)) { f[it.key] = true; } });
                    this.fold = f;
                }
            },

            // dragging, under the shared drop rule
            dragStart(e, k) {
                if (this.filtering) {
                    e.preventDefault();
                    this.say(t.noMoveFiltered, false);
                    return;
                }
                this.dragK = k;
                this.rowMenu = null;
                dnd.start(e, k, e.target.closest('[data-mn-row]'));
            },
            dragOver(e, k) {
                if (!this.dragK) {
                    return;
                }
                const el = e.target.closest('[data-mn-row]');
                dnd.over(e, el, k, this.shownRows());
            },
            dragOverPh(e) {
                if (this.dragK) {
                    dnd.overPh(e, this.shownRows());
                }
            },
            shownRows() {
                return this.rows().map((r) => ({ k: r.k, lv: r.lv, parent: r.it.parent || null, fold: r.kids && !!this.fold[r.k] }));
            },
            dragDrop(e) {
                e.preventDefault();
                const T = dnd.end();
                const k = this.dragK;
                this.dragEnd();
                if (!k || !T) {
                    return;
                }
                if (T.bad) {
                    this.say(fill(t.kept, { why: T.why || T.label }), false);
                    return;
                }
                const parent = T.parent || null;
                const sib = kidsOf(this.treeRows(), parent, k);
                this.change(() => this.place(k, parent, dropPosition(T, sib)));
                if (parent) {
                    this.fold[parent] = false;
                }
                this.live = T.label;
            },
            dragEnd() {
                if (this.dragK) {
                    dnd.end();
                }
                this.dragK = null;
                this.drop = null;
            },
            get dragHint() {
                if (!this.dragK) {
                    return '';
                }
                if (this.drop) {
                    return this.drop.label + (this.drop.bad ? t.dragBad : '');
                }
                const n = this.block(this.dragK).length - 1;
                return fill(n ? t.dragHintKids : t.dragHint, { name: this.name(this.item(this.dragK)), n });
            },

            // "Chuyển đến…"
            mvOpen(k) {
                this.rowMenu = null;
                this.mv = { k, q: '', pick: null, from: k };
                this.$nextTick(() => {
                    const el = document.getElementById('mn-mv-q');
                    if (el) {
                        el.focus();
                    }
                });
            },
            mvOptions() {
                if (!this.mv) {
                    return [];
                }
                const k = this.mv.k;
                const dep = this.depthMap();
                const inside = new Set(this.block(k).map((x) => x.key));
                const h = this.height(k);
                const q = fold(this.mv.q.trim());
                const out = [{ key: '@top', name: t.mvTop, sub: this.item(k).parent ? t.mvTopSub : t.mvHere, lv: 0, dis: !this.item(k).parent }];
                this.items.forEach((it) => {
                    if (inside.has(it.key)) {
                        return;
                    }
                    const lv = dep[it.key] + 2;
                    const tooDeep = lv + h - 1 > this.maxDepth;
                    const here = this.item(k).parent === it.key;
                    out.push({
                        key: it.key,
                        name: this.name(it),
                        sub: here ? t.mvHere : (tooDeep ? fill(t.tooDeep, { max: this.maxDepth }) : fill(t.mvSub, { n: this.kids(it.key).length })),
                        lv: dep[it.key] + 1,
                        dis: tooDeep || here,
                    });
                });
                return q ? out.filter((o) => fold(o.name).includes(q)) : out;
            },
            get mvBtn() {
                if (!this.mv || !this.mv.pick) {
                    return t.mvPick;
                }
                return this.mv.pick === '@top' ? t.mvGoTop : fill(t.mvGo, { name: this.name(this.item(this.mv.pick)) });
            },
            mvGo() {
                const { k, pick } = this.mv;
                if (!pick) {
                    return;
                }
                const parent = pick === '@top' ? null : pick;
                this.change(() => this.place(k, parent, kidsOf(this.treeRows(), parent, k).length));
                if (parent) {
                    this.fold[parent] = false;
                }
                this.mv = null;
                this.say(fill(t.movedTo, { name: this.name(this.item(k)) }));
                this.focusRow(k);
            },
            mvClose() {
                const k = this.mv ? this.mv.from : null;
                this.mv = null;
                if (k) {
                    this.focusMore(k);
                }
            },

            // ---- adding -------------------------------------------------------------------------
            setTab(k) {
                this.tab = k;
                this.search = '';
                this.picked = {};
                this.results = [];
                if (k !== 'url' && k !== 'special') {
                    this.find();
                }
            },
            findSoon() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => this.find(), 250);
            },
            async find() {
                if (this.tab === 'url' || this.tab === 'special') {
                    return;
                }
                const ask = ++seq;
                this.searching = true;
                try {
                    const res = await this.$wire.search(this.search, this.tab, this.menu ? this.menu.key : null);
                    if (ask === seq) {
                        this.results = res.ok ? res.results : [];
                    }
                } catch {
                    if (ask === seq) {
                        this.results = [];
                    }
                } finally {
                    if (ask === seq) {
                        this.searching = false;
                    }
                }
            },
            inDraft(link) {
                return this.items.some((it) => it.link.type === link.type && (link.ref ? it.link.ref === link.ref : it.link.key === link.key));
            },
            srcRows() {
                if (this.tab === 'special') {
                    const q = fold(this.search.trim());
                    return (this.d ? this.d.specials : [])
                        .filter((s) => !q || fold(s.label).includes(q))
                        .map((s) => ({ key: 'special:' + s.key, link: { type: 'special', key: s.key }, title: s.label, meta: s.available ? s.help : t.specialMissing, used: this.inDraft({ type: 'special', key: s.key }), draft: false }));
                }
                return this.results.map((r) => ({ key: r.key, link: r.link, title: r.title, kind: r.kind, meta: t.kinds[r.kind] || '', used: this.inDraft(r.link), draft: !r.published }));
            },
            togglePick(r) {
                if (r.used) {
                    return;
                }
                const p = { ...this.picked };
                if (p[r.key]) {
                    delete p[r.key];
                } else {
                    p[r.key] = r;
                }
                this.picked = p;
            },
            get pickedN() {
                return Object.keys(this.picked).length;
            },
            get addParent() {
                return this.addTo === 'sel' && this.sel ? this.sel : null;
            },
            addRows(list) {
                const parent = this.addParent;
                if (parent && this.allow(list[0].key, (this.depthMap()[parent] || 0) + 2)) {
                    this.say(fill(t.tooDeep, { max: this.maxDepth }), false);
                    return null;
                }
                const made = list.map((r) => ({ key: 'new:' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7), parent, link: r.link, labels: r.labels || {}, options: emptyOptions(), source: r.kind ? { kind: r.kind, ref: r.link.ref, title: r.title, slug: null, state: r.draft ? 'unpublished' : 'live', untranslated: [] } : null }));
                this.change(() => {
                    made.forEach((it) => {
                        this.items.push({ ...it, parent: null });
                        this.place(it.key, parent, Number.MAX_SAFE_INTEGER);
                    });
                });
                if (parent) {
                    this.fold[parent] = false;
                }
                return made;
            },
            addPicked() {
                const list = Object.values(this.picked).map((r) => ({
                    key: r.key,
                    link: r.link,
                    labels: {},
                    source: r.link.type === 'special' ? null : { kind: this.tab === 'page' ? 'page' : (this.tab === 'category' ? 'category' : 'product'), ref: r.link.ref, title: r.title, slug: null, state: r.draft ? 'unpublished' : 'live', untranslated: [] },
                }));
                if (!list.length || !this.menu) {
                    return;
                }
                const made = this.addRows(list);
                if (made) {
                    this.picked = {};
                    this.say(fill(t.added, { n: made.length }));
                    this.focusRow(made[made.length - 1].key);
                }
            },
            get url() {
                return normUrl(this.newUrl, t);
            },
            addUrl() {
                const u = this.url;
                if (!u.ok || !this.newLabel.trim() || !this.menu) {
                    return;
                }
                const made = this.addRows([{ key: 'url', link: { type: 'url', url: u.url }, labels: { [this.loc]: this.newLabel.trim() } }]);
                if (made) {
                    this.newLabel = '';
                    this.newUrl = '';
                    this.say(fill(t.added, { n: 1 }));
                    this.focusRow(made[0].key);
                }
            },

            // ---- saving -------------------------------------------------------------------------
            draft() {
                const saved = JSON.parse(this.saved);
                const here = new Set(this.items.map((it) => it.key));
                const creates = [];
                const updates = [];
                this.items.forEach((it) => {
                    const labels = { ...it.labels };
                    if (!(it.key in saved)) {
                        creates.push({ key: it.key, link: it.link, labels, options: it.options });
                        return;
                    }
                    const [, link, oldLabels, options] = JSON.parse(saved[it.key]);
                    const up = { key: it.key };
                    if (JSON.stringify(link) !== JSON.stringify(it.link)) {
                        up.link = it.link;
                    }
                    if (JSON.stringify(oldLabels) !== JSON.stringify(it.labels)) {
                        Object.keys(oldLabels).forEach((l) => { if (!(l in labels)) { labels[l] = ''; } });
                        up.labels = labels;
                    }
                    if (JSON.stringify(options) !== JSON.stringify(it.options)) {
                        up.options = it.options;
                    }
                    if (Object.keys(up).length > 1) {
                        updates.push(up);
                    }
                });
                return {
                    version: this.menu.version,
                    tree: this.items.map((it) => ({ key: it.key, parent: it.parent || null })),
                    creates,
                    updates,
                    removes: Object.keys(saved).filter((k) => !here.has(k)),
                };
            },
            async save() {
                if (!this.menu || this.saving || !this.dirty().n) {
                    return;
                }
                const bad = this.items.find((it) => this.labelErr(it));
                if (bad) {
                    this.sel = bad.key;
                    this.say(t.needName, false);
                    this.focusRow(bad.key);
                    return;
                }
                this.saving = true;
                this.saveErr = false;
                try {
                    const res = await this.$wire.saveDraft(this.menu.key, this.draft());
                    if (!res.ok) {
                        this.say(res.message, false);
                        if (res.reason === 'draft_stale') {
                            this.ask = { title: t.staleTitle, body: t.staleBody, ok: t.staleOk, run: () => this.reload() };
                        }
                        return;
                    }
                    past = [];
                    future = [];
                    await this.reload();
                    this.say(t.saved);
                } catch {
                    this.saveErr = true;
                } finally {
                    this.saving = false;
                }
            },
            // A call that changes the site at once: the answer, or null after a refusal (said in the toast).
            async call(fn) {
                try {
                    const res = await fn();
                    if (!res.ok) {
                        this.say(res.message, false);
                        return null;
                    }
                    return res;
                } catch {
                    this.say(t.netErr, false);
                    return null;
                }
            },
            // Leaving the open menu with unsaved rows asks first.
            guard(run) {
                const n = this.dirty().n;
                if (!n) {
                    run();
                    return;
                }
                this.ask = {
                    title: t.leaveTitle,
                    body: fill(t.leaveBody, { what: this.dirty().text }),
                    ok: t.leaveSave,
                    alt: t.leaveDrop,
                    run: async () => { await this.save(); if (!this.dirty().n) { run(); } },
                    altRun: run,
                };
            },

            // ---- menus --------------------------------------------------------------------------
            menuRows() {
                return (this.d ? this.d.menus : []).map((m) => ({ ...m, on: this.menu && this.menu.key === m.key, use: this.useOf(m) }));
            },
            useOf(m) {
                const out = [];
                (this.d ? this.d.areas : []).forEach((a) => {
                    Object.keys(a.shows).forEach((loc) => {
                        if (a.shows[loc] && a.shows[loc].menu === m.key) {
                            out.push(loc === cfg.main || !this.multi ? a.label.toLowerCase() : a.label.toLowerCase() + ' (' + this.locName(loc) + ')');
                        }
                    });
                });
                return out;
            },
            menuCount(m) {
                return m.on ? this.items.length : null;
            },
            selectMenu(key) {
                if (this.menu && this.menu.key === key) {
                    return;
                }
                this.guard(() => this.reload(key));
            },
            startRename(m) {
                this.menuMore = null;
                this.renaming = m.key;
                this.renameVal = m.name;
                this.$nextTick(() => {
                    const el = document.getElementById('mn-rename');
                    if (el) {
                        el.focus();
                        el.select();
                    }
                });
            },
            async doneRename() {
                const k = this.renaming;
                const name = this.renameVal.trim();
                this.renaming = null;
                const m = this.d.menus.find((x) => x.key === k);
                if (!m || !name || name === m.name) {
                    return;
                }
                const res = await this.call(() => this.$wire.renameMenu(k, name));
                if (res) {
                    m.name = name;
                    if (this.menu && this.menu.key === k) {
                        this.menu.name = name;
                        this.menu.version = res.version;
                    }
                    this.say(t.renamed);
                }
            },
            async newMenu() {
                const res = await this.call(() => this.$wire.createMenu(t.newMenuName));
                if (res) {
                    this.guard(async () => {
                        await this.reload(res.menu);
                        this.startRename(this.d.menus.find((x) => x.key === res.menu) || { key: res.menu, name: t.newMenuName });
                    });
                }
            },
            async dupMenu(m) {
                this.menuMore = null;
                const res = await this.call(() => this.$wire.copyMenu(m.key));
                if (res) {
                    this.guard(async () => {
                        await this.reload(res.menu);
                        this.say(fill(t.menuCopied, { name: m.name }));
                    });
                }
            },
            delMenu(m) {
                this.menuMore = null;
                const used = m.use.length > 0;
                this.ask = {
                    title: fill(t.delTitle, { name: m.name }),
                    body: used ? fill(t.delBodyUsed, { where: m.use.join(' · ') }) : t.delBody,
                    ok: t.delOk,
                    danger: true,
                    run: async () => {
                        const res = await this.call(() => this.$wire.deleteMenu(m.key, used));
                        if (res) {
                            const open = this.menu && this.menu.key === m.key;
                            await this.reload(open ? null : this.menu.key);
                            this.say(fill(t.menuDeleted, { name: m.name }));
                        }
                    },
                };
            },
            async setAutoAdd() {
                if (!this.menu) {
                    return;
                }
                const on = !this.menu.autoAdd;
                const res = await this.call(() => this.$wire.setAutoAdd(this.menu.key, on));
                if (res) {
                    this.menu.autoAdd = on;
                    this.menu.version = res.version;
                    const m = this.d.menus.find((x) => x.key === this.menu.key);
                    if (m) {
                        m.autoAdd = on;
                    }
                }
            },

            // ---- areas --------------------------------------------------------------------------
            areaRows() {
                const all = this.d ? this.d.areas : [];
                const list = all.filter((a) => !a.advanced || this.advOn);
                return list.map((a, i) => ({ ...a, head: i === 0 || list[i - 1].group !== a.group }));
            },
            areaValue(a, loc) {
                const s = a.shows[loc] || {};
                return s.menu ? s.menu : (s.follows ? '@same' : '');
            },
            areaOptions(a, loc) {
                const out = [{ v: '', l: loc === cfg.main ? t.areaNone : fill(t.areaMain, { lang: this.locName(cfg.main) }) }];
                if (a.canSame) {
                    const f = (this.d.areas.find((x) => x.key === a.canSame) || {}).label || '';
                    out.push({ v: '@same', l: fill(t.areaSame, { area: f }) });
                }
                this.d.menus.forEach((m) => out.push({ v: m.key, l: m.name }));
                return out;
            },
            get areasSet() {
                const as = this.d ? this.d.areas : [];
                return fill(t.areasSet, { on: as.filter((a) => this.areaValue(a, cfg.main)).length, all: as.length });
            },
            async setArea(a, loc, v) {
                const res = await this.call(() => (v === '@same'
                    ? this.$wire.follow(a.key, loc)
                    : (v ? this.$wire.assign(a.key, loc, v) : this.$wire.clearArea(a.key, loc))));
                if (res) {
                    a.shows[loc] = v === '@same' ? { follows: a.canSame } : (v ? { menu: v } : {});
                    this.say(t.areaSaved);
                    this.check();
                    if (this.menu) {
                        // the area's depth limit may have changed for the open menu
                        const keep = this.dirty().n;
                        if (!keep) {
                            this.reload();
                        }
                    }
                } else {
                    a.shows = { ...a.shows };
                }
            },
            copyCode(text) {
                try {
                    navigator.clipboard.writeText(text);
                    this.say(fill(t.codeCopied, { code: text }));
                } catch {
                    this.say(text);
                }
            },

            // ---- languages ----------------------------------------------------------------------
            langStat(code) {
                const done = this.items.filter((it) => this.label(it, code) || (code === cfg.main && this.fallbackName(it))).length;
                return { done, total: this.items.length };
            },
            pickLoc(code) {
                this.loc = code;
                this.sel = null;
                this.rowMenu = null;
                if (this.filter === 'untr' && code === cfg.main) {
                    this.filter = 'all';
                }
            },

            // ---- self-check ---------------------------------------------------------------------
            get checkTitle() {
                const bad = this.issues.filter((q) => q.level !== 'tip').length;
                const tip = this.issues.length - bad;
                if (!this.issues.length) {
                    return t.checkOk;
                }
                if (!bad) {
                    return fill(t.checkTips, { n: tip });
                }
                return fill(t.checkBad, { n: bad }) + (tip ? fill(t.checkAndTips, { n: tip }) : '');
            },
            shownIssues() {
                return this.issuesAll ? this.issues : this.issues.slice(0, ISSUES_SHOWN);
            },
            goIssue(q) {
                this.filter = 'all';
                this.q = '';
                let p = this.item(q.item);
                while (p && p.parent) {
                    this.fold[p.parent] = false;
                    p = this.item(p.parent);
                }
                this.focusRow(q.item);
            },

            // ---- preview ------------------------------------------------------------------------
            pvTop() {
                return this.items.filter((it) => !it.parent && !it.options.hidden && it.options.device !== 'phone' && !this.srcState(it));
            },
            pvKids(k, phone = false) {
                return this.kids(k).filter((it) => !it.options.hidden && it.options.device !== (phone ? 'desktop' : 'phone') && !this.srcState(it));
            },
            pvMobile() {
                const out = [];
                const walk = (parent, d) => this.pvKids(parent, true).forEach((it) => {
                    out.push({ k: it.key, name: this.name(it), d, kids: this.hasKids(it.key) });
                    if (d < 1) {
                        walk(it.key, d + 1);
                    }
                });
                walk(null, 0);
                return out;
            },
            pvCols(it) {
                const n = it.options.mega ? it.options.mega.columns : 3;
                const kids = this.pvKids(it.key);
                const cols = Array.from({ length: n }, () => []);
                kids.forEach((k, i) => cols[i % n].push(k));
                return cols;
            },
            pvCrowded() {
                return this.pvTop().reduce((n, it) => n + this.name(it).length, 0) > 68;
            },
            badgeText(it) {
                return it.options.badge ? t.badges[it.options.badge.kind] : '';
            },
            desc(it) {
                return it.options.description ? it.options.description[this.loc] || '' : '';
            },

            // ---- icons --------------------------------------------------------------------------
            iconRows() {
                const q = fold(this.iconQ.trim());
                return cfg.icons.filter((ic) => !q || fold(ic.label).includes(q) || ic.name.includes(q));
            },
            setIcon(k, name) {
                this.setOpt(k, 'icon', name);
                this.iconOpen = false;
            },

            // ---- trash --------------------------------------------------------------------------
            trashMeta(r) {
                const days = Math.max(0, 30 - Math.floor((Date.now() - Date.parse(r.removedAt)) / 86400000));
                return (r.count > 1 ? fill(t.trashKids, { n: r.count - 1 }) + ' · ' : '') + fill(t.trashDays, { n: days });
            },
            async restore(r) {
                if (this.dirty().n) {
                    this.say(t.restoreNeedsSave, false);
                    return;
                }
                const res = await this.call(() => this.$wire.restore(this.menu.key, r.key));
                if (res) {
                    await this.reload();
                    this.say(fill(t.restored, { name: r.label }));
                }
            },

            // ---- dialogs and messages -----------------------------------------------------------
            askOk() {
                const run = this.ask && this.ask.run;
                this.askClose();
                if (run) {
                    run();
                }
            },
            askAlt() {
                const run = this.ask && this.ask.altRun;
                this.askClose();
                if (run) {
                    run();
                }
            },
            askClose() {
                this.ask = null;
                const back = this._askFrom;
                this._askFrom = null;
                // After the dialog's focus trap has let go (it hands focus back to where it opened).
                setTimeout(() => {
                    if (back && document.contains(back)) {
                        back.focus();
                    }
                });
            },
            // Where focus should come back to after a dialog: a "⋯" menu's item → that menu's button.
            opener(el) {
                const list = el.closest('[role="menu"]');
                return list && list.parentElement ? list.parentElement.querySelector('[data-mn-more], [data-mn-mm]') : el;
            },
            noteFocus(el) {
                lastFocus = this.opener(el);
            },
            askOpened() {
                // Focus comes back where it was; a "⋯" menu has closed by now, so to its button.
                const from = document.activeElement;
                this._askFrom = from && from !== document.body ? this.opener(from) : lastFocus;
                this.$nextTick(() => {
                    const b = document.getElementById('mn-ask-cancel');
                    if (b) {
                        b.focus();
                    }
                });
            },
            say(text, undo = false) {
                clearTimeout(toastTimer);
                this.toast = { text, undo };
                this.live = text;
                toastTimer = setTimeout(() => this.toastClose(), undo ? TOAST_UNDO_MS : TOAST_MS);
            },
            toastHold() {
                clearTimeout(toastTimer);
            },
            toastGo() {
                if (this.toast) {
                    toastTimer = setTimeout(() => this.toastClose(), TOAST_MS);
                }
            },
            toastClose() {
                clearTimeout(toastTimer);
                this.toast = null;
            },
            toastUndo() {
                this.undo();
            },
        };
    });
});
