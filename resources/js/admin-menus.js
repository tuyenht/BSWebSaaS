// The Trình đơn screen (Alpine.data 'bsMenus'): the menu being edited is a draft kept here until
// "Lưu" sends it whole ($wire.saveDraft). The item tree is the shared tree (<x-bs-admin::tree
// local>, admin-tree.js): it drags, moves by keyboard and by the ⋮ menu, and hands each move to
// treeMove() here; undo / redo (one history, makeHistory) cover every change to the draft. The
// add panel, the inline editor, "Chuyển đến…" and the "Khách sẽ thấy" preview work on the same
// draft. Menus, areas and "Tự thêm trang mới" are saved at once by their own calls. Every key is
// the ulid the server gave (or "new:…" for a row not saved yet); labels and descriptions are shown
// as text only (x-text), never as HTML.
import { fill, kidsOf, makeHistory, treeGuides } from './admin-tree-dnd.js';

const HIST_MAX = 50;
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

// What "Lưu" sends (ApplyMenuDraft): items = the draft in display order, saved = key → the saved
// row's signature ([parent, link, labels, options] as JSON), version = the token the draft was
// built on. Every live row is named once in `tree`; a removed row and the rows under it are in
// `removes`; a label cleared in a language is sent as '' so the server clears it; a row not saved
// yet keeps its "new:…" key.
export function buildDraft(items, saved, version) {
    const here = new Set(items.map((it) => it.key));
    const creates = [];
    const updates = [];
    items.forEach((it) => {
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
            Object.keys(oldLabels).forEach((l) => {
                if (!(l in labels)) {
                    labels[l] = '';
                }
            });
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
        version,
        tree: items.map((it) => ({ key: it.key, parent: it.parent || null })),
        creates,
        updates,
        removes: Object.keys(saved).filter((k) => !here.has(k)),
    };
}

// The icon picker's groups ({group, icons: [{name, label}]}) for what is typed: every group with
// icons when nothing is, else one group of the matches, named by `hits` (":n biểu tượng khớp"),
// or none. A match is a label or a name that holds the typed words, accents and case ignored.
export function iconGroupsOf(groups, q, hits) {
    const qq = fold(String(q || '').trim());
    if (!qq) {
        return groups.filter((g) => g.icons.length);
    }
    const found = groups.flatMap((g) => g.icons).filter((ic) => fold(ic.label).includes(qq) || ic.name.includes(qq));
    return found.length ? [{ group: fill(hits, { n: found.length }), icons: found }] : [];
}

// The rows that point at something no longer there (the source was deleted for good), in order.
// A source in the trash or not published yet is not one: it can come back.
export function goneKeysOf(items) {
    return items.filter((it) => it.source && it.source.state === 'gone').map((it) => it.key);
}

// The rows the shared tree draws from the draft: each with its level, its place among its siblings
// and its guide lines (the fields of a TreeRows entry). While a filter is on, the matches stand flat,
// in order, and cannot be moved.
export function treeRowsOf(items, flat = false) {
    const dep = {};
    const size = {};
    const pos = {};
    items.forEach((it) => {
        const p = it.parent || '';
        dep[it.key] = it.parent && dep[it.parent] !== undefined ? dep[it.parent] + 1 : 0;
        size[p] = (size[p] || 0) + 1;
        pos[it.key] = size[p];
    });
    const kids = {};
    items.forEach((it) => {
        if (it.parent) {
            kids[it.parent] = (kids[it.parent] || 0) + 1;
        }
    });
    if (flat) {
        return items.map((it, i) => ({ it, k: it.key, parent: null, lv: 1, kids: 0, pos: i + 1, size: items.length, guides: [] }));
    }
    const guides = treeGuides(items.map((it) => dep[it.key] + 1));
    return items.map((it, i) => ({
        it,
        k: it.key,
        parent: it.parent || null,
        lv: dep[it.key] + 1,
        kids: kids[it.key] || 0,
        pos: pos[it.key],
        size: size[it.parent || ''],
        guides: guides[i],
    }));
}

if (typeof document !== 'undefined') document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsMenus', (cfg) => {
        const t = cfg.texts;
        // Outside Alpine's reactive state: the history, timers.
        let history = makeHistory({ max: HIST_MAX });
        let searchTimer = null;
        let seq = 0;
        let toastSeq = 0;

        return {
            screen: 'loading', // loading · error · data
            saveBusy: false,
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
            renaming: null,
            renameVal: '',
            treeOn: 0, // bumped when the tree starts: what reads the tree's folding reads it again
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
            mv: null,
            ask: null,
            toast: null,
            live: '',

            init() {
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
                this.trashOpen = false;
                history = makeHistory({ max: HIST_MAX });
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
            // null opens the menu the screen opens on first (the server picks it, as on the first draw).
            async reload(key = this.menu?.key ?? null) {
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
            childrenOf(k) {
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
            spanOf(k) {
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
                if (!this.filtering) {
                    return treeRowsOf(this.items).map((r) => this.rowOf(r, dep));
                }
                const q = fold(this.q.trim());
                const hits = this.items.filter((it) => {
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
                });
                return treeRowsOf(hits, true).map((r) => this.rowOf(r, dep));
            },
            rowOf(r, dep) {
                const it = r.it;
                const state = this.srcState(it);
                return {
                    ...r,
                    name: this.name(it),
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
                if (this.saveBusy) {
                    return; // the editing area is inert while "Lưu" runs; nothing slips in
                }
                const before = this.snap();
                fn();
                if (this.snap() !== before) {
                    history.record(before, 'do');
                    this.histN = history.undoSize;
                    this.futN = 0;
                    this.dropUndoOffers();
                }
                if (!keepSel && this.sel && !this.item(this.sel)) {
                    this.sel = null;
                }
            },
            undo() {
                const prev = this.saveBusy ? null : history.takeUndo();
                if (!prev) {
                    return;
                }
                const k = this.focusedRow();
                history.record(this.snap(), 'undo');
                this.items = JSON.parse(prev);
                this.after(t.undone, k);
            },
            redo() {
                const next = this.saveBusy ? null : history.takeRedo();
                if (!next) {
                    return;
                }
                const k = this.focusedRow();
                history.record(this.snap(), 'redo');
                this.items = JSON.parse(next);
                this.after(t.redone, k);
            },
            after(text, k = null) {
                this.histN = history.undoSize;
                this.futN = history.redoSize;
                if (this.sel && !this.item(this.sel)) {
                    this.sel = null;
                }
                this.live = text;
                this.toastClose();
                this.dropUndoOffers();
                if (k && this.item(k)) {
                    this.focusItem(k, true);
                }
            },
            // The row holding the keyboard focus, if any.
            focusedRow() {
                const row = document.activeElement && document.activeElement.closest('#mn-tree [data-tree-row]');
                return row ? row.dataset.k : null;
            },
            reset() {
                this.ask = {
                    title: t.resetTitle,
                    body: fill(t.resetBody, { what: this.dirty().text }),
                    ok: t.resetOk,
                    run: () => this.reload(), // the saved menu again, with a fresh history
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
            // The source's own short text in this language, offered while the item has none.
            descSrc(it) {
                return it.source && it.source.summary && !this.desc(it) ? it.source.summary[this.loc] || '' : '';
            },
            descFromSource(k) {
                const it = this.item(k);
                const text = it ? this.descSrc(it) : '';
                if (text) {
                    this.setDesc(k, text);
                    this.notify(fill(t.descTaken, { name: it.source.title || this.name(it) }), true);
                }
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
                    this.fail(r.msg);
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
                this.focusItem(k);
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
                    this.notify(fill(n ? t.removedKids : t.removed, { name, n }), true);
                };
                if (n) {
                    this.ask = { title: fill(t.removeKidsTitle, { n }), body: fill(t.removeKidsBody, { name, n }), ok: t.removeKidsOk, danger: true, run };
                } else {
                    run();
                }
            },
            // Links that followed a page to its new address by themselves ("Đã tự sửa N liên kết").
            get fixedN() {
                return this.items.filter((it) => it.source && it.source.moved).length;
            },
            // "Gỡ N mục không còn": every row whose source is gone, at once, after a confirm that names
            // them; like remove(), they leave the draft and go to the trash on "Lưu".
            get goneKeys() {
                return goneKeysOf(this.items);
            },
            removeGone() {
                const keys = this.goneKeys;
                if (keys.length < 2) {
                    return;
                }
                const n = keys.length;
                const names = keys.map((k) => this.name(this.item(k)));
                const list = names.slice(0, 3).join(', ') + (names.length > 3 ? ', …' : '');
                const run = () => {
                    this.change(() => {
                        const gone = new Set(keys.flatMap((k) => this.block(k).map((x) => x.key)));
                        this.items = this.items.filter((x) => !gone.has(x.key));
                    }, false);
                    if (this.sel && !this.item(this.sel)) {
                        this.sel = null;
                    }
                    this.notify(fill(t.goneRemoved, { n }), true);
                };
                this.ask = { title: fill(t.goneTitle, { n }), body: fill(t.goneBody, { n, list }), ok: fill(t.goneOk, { n }), danger: true, run };
            },
            toggleHide(k) {
                const it = this.item(k);
                this.setOpt(k, 'hidden', !it.options.hidden);
                this.notify(fill(it.options.hidden ? t.hiddenNow : t.shownNow, { name: this.name(it) }), true);
            },
            revert(k) {
                const saved = JSON.parse(this.saved);
                if (!(k in saved)) {
                    return;
                }
                const [, link, labels, options] = JSON.parse(saved[k]);
                this.set(k, (it) => { it.link = link; it.labels = labels; it.options = options; });
            },
            async copy(k) {
                const it = this.item(k);
                if (this.dirty().n) {
                    // copying works on the saved menu: a draft with changes is refused (draft_stale)
                    this.fail(t.copyNeedsSave);
                    return;
                }
                const res = await this.server(() => this.$wire.copyItem(this.menu.key, k, this.menu.version));
                if (res) {
                    await this.reload();
                    this.notify(fill(t.copied, { name: this.name(it) }));
                    this.focusItem(res.item);
                }
            },

            // ---- moves --------------------------------------------------------------------------
            canPlace(k, lv) {
                const h = this.spanOf(k);
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
            // The shared tree's move (drag, Alt + arrows, the ⋮ menu): null when done, else the reason.
            treeMove(k, parent, pos) {
                if (this.filtering) {
                    return t.noMoveFiltered;
                }
                if (!this.item(k)) {
                    return t.no.gone;
                }
                const why = this.canPlace(k, parent ? this.depthMap()[parent] + 2 : 1);
                if (why) {
                    return why.why;
                }
                this.change(() => this.place(k, parent, pos));
                return null;
            },
            keys(e) {
                if (e.defaultPrevented) {
                    return; // the tree took it (its own Ctrl+Z, Esc during a drag)
                }
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
                if (this.ask || this.mv) {
                    return; // the dialog closes itself
                }
                if (this.iconOpen) {
                    this.iconOpen = false;
                } else if (this.renaming) {
                    this.renaming = null;
                } else if (this.sel) {
                    this.closeEditor();
                }
            },
            focusItem(k, keepButton = false) {
                if (!k) {
                    return;
                }
                this.$nextTick(() => {
                    const row = document.querySelector('#mn-tree [data-tree-row][data-k="' + k + '"]');
                    if (!row) {
                        return;
                    }
                    row.scrollIntoView({ block: 'nearest' });
                    if (keepButton && row.contains(document.activeElement)) {
                        return;
                    }
                    const b = row.querySelector('[data-tree-name]');
                    if (b) {
                        b.focus();
                    }
                });
            },
            // The shared tree's own state (its folding), or null before it is drawn.
            tree() {
                // null until the tree's own x-data has started (before that $data is this screen's)
                const el = document.getElementById('mn-tree');
                const tr = el ? window.Alpine.$data(el) : null;
                return tr && typeof tr.openTo === 'function' ? tr : null;
            },
            openItem(k) {
                const tr = this.tree();
                if (tr && k) {
                    tr.expand(k);
                }
            },
            get anyShut() {
                void this.treeOn;
                const tr = this.tree();
                return tr ? tr.anyFolded() : false;
            },
            foldRows() {
                const tr = this.tree();
                if (tr) {
                    tr.foldAll(!tr.anyFolded());
                }
            },

            // "Chuyển đến…"
            mvOpen(k) {
                this.mv = { k, q: '', pick: null };
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
                const h = this.spanOf(k);
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
                        sub: here ? t.mvHere : (tooDeep ? fill(t.tooDeep, { max: this.maxDepth }) : fill(t.mvSub, { n: this.childrenOf(it.key).length })),
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
                this.openItem(parent);
                this.mv = null;
                this.notify(fill(t.movedTo, { name: this.name(this.item(k)) }));
                this.focusItem(k);
            },
            mvClose() {
                this.mv = null; // the dialog puts the focus back on the ⋮ it was opened from
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
                return this.results.map((r) => ({ key: r.key, link: r.link, title: r.title, kind: r.kind, meta: t.kinds[r.kind] || '', used: this.inDraft(r.link), draft: !r.published, summary: r.summary || null }));
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
                if (parent && (this.depthMap()[parent] || 0) + 2 > this.maxDepth) {
                    this.fail(fill(t.tooDeep, { max: this.maxDepth }));
                    return null;
                }
                const made = list.map((r) => ({ key: 'new:' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7), parent, link: r.link, labels: r.labels || {}, options: emptyOptions(), source: r.source || null }));
                this.change(() => {
                    made.forEach((it) => {
                        this.items.push({ ...it, parent: null });
                        this.place(it.key, parent, Number.MAX_SAFE_INTEGER);
                    });
                });
                this.openItem(parent);
                return made;
            },
            addPicked() {
                const list = Object.values(this.picked).map((r) => ({
                    key: r.key,
                    link: r.link,
                    labels: {},
                    // The search names the kind and carries the short text in the main language.
                    source: r.link.type === 'special' ? null : { kind: r.kind || (this.tab === 'category' ? 'category' : (this.tab === 'product' ? 'product' : 'page')), ref: r.link.ref, title: r.title, slug: null, state: r.draft ? 'unpublished' : 'live', untranslated: [], summary: r.summary ? { [cfg.main]: r.summary } : {}, moved: false },
                }));
                if (!list.length || !this.menu) {
                    return;
                }
                const made = this.addRows(list);
                if (made) {
                    this.picked = {};
                    this.notify(fill(t.added, { n: made.length }));
                    this.focusItem(made[made.length - 1].key);
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
                    this.notify(fill(t.added, { n: 1 }));
                    this.focusItem(made[0].key);
                }
            },

            // ---- saving -------------------------------------------------------------------------
            draft() {
                return buildDraft(this.items, JSON.parse(this.saved), this.menu.version);
            },
            async save() {
                if (!this.menu || this.saveBusy || !this.dirty().n) {
                    return;
                }
                const bad = this.items.find((it) => this.labelErr(it));
                if (bad) {
                    this.sel = bad.key;
                    this.fail(t.needName);
                    this.focusItem(bad.key);
                    return;
                }
                this.saveBusy = true;
                this.saveErr = false;
                try {
                    const res = await this.$wire.saveDraft(this.menu.key, this.draft());
                    if (!res.ok) {
                        this.refused(res);
                        return;
                    }
                    await this.reload();
                    this.notify(t.saved);
                } catch {
                    this.saveErr = true;
                } finally {
                    this.saveBusy = false;
                }
            },
            // A call that changes the site at once: the answer, or null after a refusal (said in the toast).
            async server(fn) {
                try {
                    const res = await fn();
                    if (!res.ok) {
                        this.refused(res);
                        return null;
                    }
                    return res;
                } catch {
                    this.fail(t.netErr);
                    return null;
                }
            },
            // A refusal stays on screen; a menu changed elsewhere since it was read asks to load it again.
            refused(res) {
                this.fail(res.message);
                if (res.reason === 'draft_stale') {
                    this.ask = { title: t.staleTitle, body: t.staleBody, ok: t.staleOk, run: () => this.reload() };
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
                const open = this.menu && this.menu.key === k;
                // the open menu's token goes along: a menu changed elsewhere since is refused, so the
                // newer token is only taken when it follows the one the draft was built on
                const res = await this.server(() => this.$wire.renameMenu(k, name, open ? this.menu.version : null));
                if (res) {
                    m.name = name;
                    if (open) {
                        this.menu.name = name;
                        this.menu.version = res.version;
                    }
                    this.notify(t.renamed);
                }
            },
            // Both open the new menu: the open draft is asked about first, before anything is made.
            newMenu() {
                this.guard(async () => {
                    const res = await this.server(() => this.$wire.createMenu(t.newMenuName));
                    if (res) {
                        await this.reload(res.menu);
                        this.startRename(this.d.menus.find((x) => x.key === res.menu) || { key: res.menu, name: t.newMenuName });
                    }
                });
            },
            dupMenu(m) {
                this.guard(async () => {
                    const res = await this.server(() => this.$wire.copyMenu(m.key));
                    if (res) {
                        await this.reload(res.menu);
                        this.notify(fill(t.menuCopied, { name: m.name }));
                    }
                });
            },
            delMenu(m) {
                const used = m.use.length > 0;
                this.ask = {
                    title: fill(t.delTitle, { name: m.name }),
                    body: used ? fill(t.delBodyUsed, { where: m.use.join(' · ') }) : t.delBody,
                    ok: t.delOk,
                    danger: true,
                    run: async () => {
                        const res = await this.server(() => this.$wire.deleteMenu(m.key, used));
                        if (!res) {
                            return;
                        }
                        if (this.menu?.key === m.key) {
                            await this.reload(null); // its draft went with it; open the first menu
                        } else if (this.dirty().n) {
                            this.dropMenu(m.key); // keep the open draft and its undo: take the row out here
                        } else {
                            await this.reload(this.menu?.key ?? null);
                        }
                        this.notify(fill(t.menuDeleted, { name: m.name }));
                    },
                };
            },
            // A deleted menu leaves the list and every area that showed it, without reloading the draft.
            dropMenu(key) {
                this.d.menus = this.d.menus.filter((x) => x.key !== key);
                this.d.areas.forEach((a) => {
                    Object.keys(a.shows).forEach((loc) => {
                        if (a.shows[loc] && a.shows[loc].menu === key) {
                            a.shows[loc] = {};
                        }
                    });
                });
            },
            async setAutoAdd() {
                if (!this.menu) {
                    return;
                }
                const on = !this.menu.autoAdd;
                const res = await this.server(() => this.$wire.setAutoAdd(this.menu.key, on, this.menu.version));
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
                const res = await this.server(() => (v === '@same'
                    ? this.$wire.follow(a.key, loc)
                    : (v ? this.$wire.assign(a.key, loc, v) : this.$wire.clearArea(a.key, loc))));
                if (res) {
                    a.shows[loc] = v === '@same' ? { follows: a.canSame } : (v ? { menu: v } : {});
                    this.notify(t.areaSaved);
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
                    this.notify(fill(t.codeCopied, { code: text }));
                } catch {
                    this.notify(text);
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
                this.$nextTick(() => {
                    const tr = this.tree();
                    if (tr) {
                        tr.openTo(q.item);
                    }
                    this.focusItem(q.item);
                });
            },

            // ---- preview ------------------------------------------------------------------------
            pvTop() {
                return this.items.filter((it) => !it.parent && !it.options.hidden && it.options.device !== 'phone' && !this.srcState(it));
            },
            pvKids(k, phone = false) {
                return this.childrenOf(k).filter((it) => !it.options.hidden && it.options.device !== (phone ? 'desktop' : 'phone') && !this.srcState(it));
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
            iconGroups() {
                return iconGroupsOf(cfg.icons, this.iconQ, t.iconHits);
            },
            get iconTotal() {
                return cfg.icons.reduce((n, g) => n + g.icons.length, 0);
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
                    this.fail(t.restoreNeedsSave);
                    return;
                }
                const res = await this.server(() => this.$wire.restore(this.menu.key, r.key));
                if (res) {
                    await this.reload();
                    this.notify(fill(t.restored, { name: r.label }));
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
                this.ask = null; // the dialog hands the focus back to where it was opened from
            },
            // A message at the bottom (the shared toast): a done change, with "Hoàn tác" when `undo`;
            // it hides by itself. fail() is a refusal or an error: it stays until closed.
            notify(text, undo = false) {
                this.toast = { text, tone: 'success', undo: !!undo, seq: ++toastSeq };
            },
            fail(text) {
                if (text) {
                    this.toast = { text, tone: 'error', undo: false, seq: ++toastSeq };
                }
            },
            toastClose(n) {
                if (this.toast && (n === undefined || this.toast.seq === n)) {
                    this.toast = null;
                }
            },
            // A "Hoàn tác" offer (the toast's, the tree's result line) undoes the newest change; once
            // another change or an undo has happened it would undo something else, so it goes.
            dropUndoOffers() {
                if (this.toast && this.toast.undo) {
                    this.toast = null;
                }
                const tr = this.tree();
                if (tr) {
                    tr.msgUndo = false;
                }
            },
            toastUndo() {
                this.toastClose();
                this.undo();
            },
        };
    });
});
