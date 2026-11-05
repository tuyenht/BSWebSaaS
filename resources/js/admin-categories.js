// The Chuyên mục screen (design 10), Alpine.data 'bsCategories', loaded through the shared lazy
// loader (admin-lazy.js) because it would push app.js past its split line. The server draws the
// tree (<x-bs-admin::tree host>, each row carrying its category as data-node JSON) and makes every
// change (App\Modules\Admin\Livewire\Categories\Index); this part keeps what only the browser
// holds: the search and quick filters, the editor drawer, the move / delete / merge dialogs, the
// copy-link dialog and the message with its "Hoàn tác". A call resolves after the server's redraw,
// so the rows are read again from the page after each one. Names, paths and descriptions are only
// ever shown as text.
import { guardLeave } from './admin-leave.js';
import { fill, makeHistory } from './admin-tree-dnd.js';

// Lower case without Vietnamese marks, one character per character of an NFC text (so a position in
// the folded text is a position in the name).
const fold = (s) => String(s ?? '').normalize('NFC').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');

function slugify(text, max) {
    return fold(text).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, max).replace(/-+$/g, '');
}

const blank = () => ({ names: {}, slugs: {}, auto: {}, descriptions: {}, seoTitles: {}, seoDescriptions: {}, parent: '', listTemplate: '', detailTemplate: '', cover: null, coverChanged: false });

window.Alpine.data('bsCategories', (cfg) => {
    const t = cfg.texts;
    const history = makeHistory({ max: 20 }); // the server's undo tokens, newest last
    let cache = null; // the rows' categories, read again after each redraw
    let actRun = null; // what the message's extra button does
    let searchTimer = null; // the server's search, a moment after the typing stops
    let mvTimer = null; // "Chuyển đến…"'s search, the same way
    let offLeave = null;
    let askRun = null;
    let askAlt = null;

    return {
        q: '',
        chip: 'all',
        fOpen: false,
        roleOpen: false,
        hits: null,
        ver: 0,
        toast: null,
        seq: 0,
        pan: null,
        mv: null,
        del: null,
        ask: null,
        copyUrl: null,

        init() {
            this.$watch('q', () => this.search());
            this.$watch('chip', () => this.applyFilter());
            // unsaved work in the drawer: closing the tab asks the browser's question, a link inside
            // the admin asks the screen's own (leave without saving, or save)
            offLeave = guardLeave({ dirty: () => this.panDirty, ask: (go) => this.askUnsaved(go) });
        },
        destroy() {
            clearTimeout(searchTimer);
            clearTimeout(mvTimer);
            if (offLeave) {
                offLeave();
            }
        },

        // A sentence with its :names filled in, for the markup (one pass: a name or a search that
        // holds "$&" or ":name" stays as typed).
        fill(text, values) {
            return fill(text, values);
        },
        // The template a category keeps that the theme no longer has (shown as its own choice in
        // the drawer, so it can be seen and cleared), or null.
        goneTpl(field) {
            const v = this.pan && this.pan.node ? this.pan.node[field] : null;
            return v && !(cfg.layouts || []).includes(v) ? v : null;
        },

        // ---- the rows as the server drew them ---------------------------------------------------
        all() {
            if (!cache) {
                cache = Array.from(this.$root.querySelectorAll('[data-tree-row][data-node]')).map((el) => {
                    try {
                        return Object.assign(JSON.parse(el.dataset.node), { lv: Number(el.dataset.lv) });
                    } catch {
                        return null;
                    }
                }).filter(Boolean);
            }
            return cache;
        },
        node(k) {
            return this.all().find((n) => n.key === k) || null;
        },
        root() {
            return this.all().find((n) => n.locked) || null;
        },
        kidsOf(k) {
            return this.all().filter((n) => (n.parent || null) === (k || null) && !n.locked);
        },
        branch(k) {
            const out = [k];
            for (let i = 0; i < out.length; i++) {
                this.all().forEach((n) => {
                    if (n.parent === out[i]) {
                        out.push(n.key);
                    }
                });
            }
            return out;
        },
        height(k) {
            const n = this.node(k);
            return n ? Math.max(...this.branch(k).map((b) => this.node(b).lv)) - n.lv + 1 : 1;
        },
        treeEl() {
            return this.$root.querySelector('.tree-w');
        },
        tree() {
            // null until the tree's own part has started (before that $data is this screen's)
            const el = this.treeEl();
            const d = el && el._x_dataStack ? window.Alpine.$data(el) : null;
            return d && typeof d.anyFolded === 'function' ? d : null;
        },
        refreshed() {
            cache = null;
            this.ver++;
            this.$nextTick(() => {
                const tr = this.tree();
                if (tr) {
                    tr.reindex();
                }
                this.applyFilter();
            });
        },
        missing(n) {
            return cfg.locales.filter((c) => c !== cfg.main && !(n.names[c] && n.slugs[c]));
        },
        get counts() {
            void this.ver;
            const ns = this.all().filter((n) => !n.locked);
            return { total: ns.length, empty: ns.filter((n) => !n.items).length, tr: ns.filter((n) => this.missing(n).length).length };
        },
        get nested() {
            void this.ver;
            return this.all().some((n) => !n.locked && this.kidsOf(n.key).length);
        },
        get folded() {
            void this.ver;
            const tr = this.tree();
            return !!tr && tr.anyFolded();
        },
        foldAll() {
            const tr = this.tree();
            if (tr) {
                tr.foldAll(!tr.anyFolded());
            }
        },

        // ---- search and quick filters -----------------------------------------------------------
        get filtering() {
            return this.q.trim() !== '' || this.chip !== 'all';
        },
        get shown() {
            const tr = this.tree();
            return tr && tr.only ? Object.keys(tr.only).length : this.counts.total;
        },
        chipOk(n) {
            return this.chip === 'empty' ? !n.items && !n.locked : this.chip === 'tr' ? !n.locked && this.missing(n).length > 0 : true;
        },
        localHits(q) {
            const needle = fold(q.trim());
            return this.all().filter((n) => fold([...Object.values(n.names), ...Object.values(n.slugs), ...n.trail].join('\n')).includes(needle)).map((n) => n.key);
        },
        search() {
            clearTimeout(searchTimer);
            const q = this.q.trim();
            this.hits = q ? this.localHits(q) : null;
            this.applyFilter();
            if (!q) {
                return;
            }
            // the server also matches the names of the parents in every language
            searchTimer = setTimeout(async () => {
                try {
                    const r = await this.$wire.search(cfg.section, q);
                    if (r.ok && q === this.q.trim()) {
                        this.hits = r.keys;
                        this.applyFilter();
                    }
                } catch {
                    // offline: the browser's own match stays
                }
            }, 250);
        },
        applyFilter() {
            const tr = this.tree();
            if (!tr) {
                return;
            }
            if (!this.filtering) {
                tr.only = null;
                return;
            }
            const only = {};
            this.all().forEach((n) => {
                if ((this.hits === null || this.hits.includes(n.key)) && this.chipOk(n)) {
                    only[n.key] = true;
                }
            });
            tr.only = only;
        },
        clearFilter() {
            this.q = '';
            this.chip = 'all';
            this.fOpen = false;
            this.$nextTick(() => this.$root.querySelector('[data-cm-q]')?.focus());
        },
        toggleChips() {
            if (this.fOpen || this.chip !== 'all') {
                this.fOpen = false;
                this.chip = 'all';
            } else {
                this.fOpen = true;
            }
        },
        // The name in three parts around the first match, for the highlight.
        part(el, i) {
            void this.ver;
            const name = String(el.closest('[data-tree-row]')?.dataset.name ?? '').normalize('NFC');
            const q = fold(this.q.trim());
            const at = q ? fold(name).indexOf(q) : -1;
            if (at < 0) {
                return i === 0 ? name : '';
            }
            return [name.slice(0, at), name.slice(at, at + q.length), name.slice(at + q.length)][i];
        },

        // ---- the message line -------------------------------------------------------------------
        say(text, tone = 'success', o = {}) {
            this.seq++;
            actRun = o.run || null;
            this.toast = { text, tone, undo: !!o.undo, act: o.act || '', seq: this.seq };
        },
        toastClose(seq) {
            if (seq === undefined || (this.toast && this.toast.seq === seq)) {
                this.toast = null;
            }
        },
        toastUndo() {
            this.toast = null;
            this.undo();
        },
        toastAct() {
            const run = actRun;
            this.toast = null;
            if (run) {
                run();
            }
        },
        lost(retry) {
            this.say(t.netFail, 'error', { act: t.retry, run: retry });
        },
        refused(r) {
            if (r.reason === 'taxonomy_invalid' || r.reason === 'move_stale') {
                this.say(r.message, 'error', { act: t.reload, run: () => this.reload() });
                return;
            }
            this.say(r.message, 'error');
        },
        async reload() {
            try {
                await this.$wire.$refresh();
                this.refreshed();
                this.say(t.reloaded, 'info');
            } catch {
                this.lost(() => this.reload());
            }
        },

        // ---- moves: the tree hands each one here (host mode) ------------------------------------
        async treeMove(k, parent, pos) {
            if (this.filtering) {
                this.say(t.filtered, 'info');
                return false;
            }
            return this.doMove(k, parent, pos, false);
        },
        async doMove(k, parent, pos, confirm) {
            const n = this.node(k);
            if (!n) {
                return false;
            }
            const from = n.parent || null;
            let r;
            try {
                r = await this.$wire.move(cfg.section, k, parent, pos, confirm);
            } catch {
                this.lost(() => this.doMove(k, parent, pos, confirm));
                return false;
            }
            if (!r.ok) {
                this.refused(r);
                return r.message;
            }
            if (r.undo) {
                history.record(r.undo, 'do');
            }
            this.refreshed();
            const to = parent ? this.node(parent) : null;
            let text = fill(t.moved, { name: n.name });
            if ((parent || null) !== from) {
                text = to ? fill(to.locked ? t.movedUnder : t.movedInto, { name: n.name, parent: to.name }) : fill(t.movedOut, { name: n.name });
            }
            this.say(text, 'success', { undo: !!r.undo });
            this.$nextTick(() => this.$root.querySelector(`[data-tree-row][data-k="${k}"] [data-tree-name]`)?.focus());
            return null;
        },
        async undo() {
            const tok = history.takeUndo();
            if (!tok) {
                return;
            }
            let r;
            try {
                r = await this.$wire.undoMove(cfg.section, tok);
            } catch {
                history.giveBack(tok, 'undo');
                this.lost(() => this.undo());
                return;
            }
            if (!r.ok) {
                this.refused(r);
                return;
            }
            this.refreshed();
            this.say(t.undone, 'info');
        },
        redo() {
            // the board has no redo for a server move: Ctrl+Shift+Z does nothing here
        },

        // ---- "Chuyển đến…" ----------------------------------------------------------------------
        async openMv(k) {
            const n = this.node(k);
            if (!n) {
                return;
            }
            this.mv = { k, name: n.name, q: '', groups: null, items: 0, to: undefined, err: '' };
            await this.mvLoad();
            this.$nextTick(() => document.getElementById('cm-mv-q')?.focus());
        },
        async mvLoad() {
            const m = this.mv;
            try {
                const r = await this.$wire.moveTargets(cfg.section, m.k, m.q.trim());
                if (this.mv !== m) {
                    return;
                }
                if (!r.ok) {
                    m.err = r.message;
                    m.groups = [];
                    return;
                }
                m.groups = r.groups;
                m.items = r.items;
            } catch {
                m.err = t.netFail;
                m.groups = m.groups || [];
            }
        },
        mvQuery() {
            clearTimeout(mvTimer);
            mvTimer = setTimeout(() => this.mvLoad(), 250);
        },
        mvPick(o, g) {
            if (o.ok) {
                this.mv.to = Object.assign({}, o, { groupLabel: g.label });
            }
        },
        mvSub(o) {
            if (o.current) {
                return t.mvHere;
            }
            if (!o.ok) {
                return o.reason === 'depth_exceeded' ? fill(t.mvLockDepth, { n: cfg.limits.depth }) : fill(t.mvLock, { why: o.message });
            }
            return fill(t.mvLevel, { n: o.newLevel });
        },
        mvLabel(o, g) {
            return o.key === null ? t.mvTop : o.root ? fill(t.mvUnder, { section: g.label }) : '  '.repeat(Math.max(0, o.level - 1)) + (o.level > 1 ? '└ ' : '') + o.name;
        },
        get mvBtn() {
            const m = this.mv;
            if (!m || !m.to) {
                return t.mvPick;
            }
            const o = m.to;
            if (o.confirm && m.items) {
                return fill(t.cxOk, { n: m.items, section: m.to.groupLabel });
            }
            return o.key === null ? t.mvGoTop : o.root ? fill(t.mvGoUnder, { section: m.to.groupLabel }) : fill(t.mvGo, { name: o.name });
        },
        mvClose() {
            this.mv = null;
        },
        mvGo() {
            const m = this.mv;
            if (!m || !m.to) {
                return;
            }
            const o = m.to;
            const pos = o.key === null ? this.kidsOf(null).length : this.kidsOf(o.key).filter((c) => c.key !== m.k).length;
            this.mv = null;
            if (o.confirm) {
                this.askOpen({
                    title: fill(t.cxTitle, { name: m.name, section: o.groupLabel }),
                    lead: fill(t.cxText, { section: o.groupLabel }),
                    lines: m.items ? [fill(t.cxLine, { n: m.items, from: cfg.label, section: o.groupLabel })] : [],
                    ok: m.items ? fill(t.cxOk, { n: m.items, section: o.groupLabel }) : t.mvPick,
                }, () => this.doMove(m.k, o.key, pos, true));
                return;
            }
            this.doMove(m.k, o.key, pos, false);
        },

        // ---- the question dialog (section change, unsaved changes) ------------------------------
        askOpen(q, run, alt = null) {
            askRun = run;
            askAlt = alt;
            this.ask = Object.assign({ danger: false, lines: [], lead: '', alt: '' }, q);
        },
        askOk() {
            const run = askRun;
            this.ask = null;
            if (run) {
                run();
            }
        },
        askAltGo() {
            const run = askAlt;
            this.ask = null;
            if (run) {
                run();
            }
        },
        askClose() {
            this.ask = null;
        },

        // ---- delete and merge -------------------------------------------------------------------
        async openDel(k, merge = false) {
            const n = this.node(k);
            if (!n) {
                return;
            }
            this.del = { k, name: n.name, merge, children: 'lift', items: 'reassign', target: n.parent && !this.node(n.parent)?.locked ? n.parent : '', p: null, err: '', busy: false, parentName: n.parent ? this.node(n.parent)?.name || '' : '' };
            if (merge) {
                this.del.target = '';
            }
            await this.delLoad();
        },
        async delLoad() {
            const D = this.del;
            try {
                const r = await this.$wire.deletePreview(cfg.section, D.k, D.children, D.target || null, D.merge, D.items === 'detach' ? 'detach' : 'reassign');
                if (this.del !== D) {
                    return;
                }
                if (!r.ok) {
                    D.err = r.message;
                    return;
                }
                D.err = '';
                D.p = r;
                if (D.target && !r.targets.some((g) => g.options.some((o) => o.key === D.target && o.ok))) {
                    D.target = '';
                }
                if (r.governed && D.items === 'detach') {
                    D.items = 'reassign';
                }
            } catch {
                D.err = t.netFail;
            }
        },
        delPick(field, value) {
            this.del[field] = value;
            this.delLoad();
        },
        get delNoun() {
            return cfg.products ? t.nounProducts : t.nounPosts;
        },
        get delQ() {
            const D = this.del;
            if (!D) {
                return null;
            }
            return { title: fill(D.merge ? t.mergeTitle : t.delTitle, { name: D.name }), ok: this.delBtn, danger: true };
        },
        get delMove() {
            return this.del.merge || this.del.items !== 'detach';
        },
        get delTargetName() {
            const D = this.del;
            let name = '';
            (D.p?.targets || []).forEach((g) => g.options.forEach((o) => {
                if (o.key === D.target) {
                    name = o.root ? g.label : o.name;
                }
            }));
            return name;
        },
        get delLead() {
            const D = this.del;
            const p = D.p;
            if (!p) {
                return '';
            }
            if (D.merge) {
                return fill(t.mergeLead, { noun: this.delNoun, name: D.name });
            }
            const parts = [];
            if (p.children) {
                parts.push(fill(t.nKids, { n: p.children }));
            }
            if (p.items) {
                parts.push(p.items + ' ' + this.delNoun);
            }
            return parts.length ? fill(t.delLead, { what: parts.join(t.and) }) : fill(t.delLeadNone, { none: (cfg.products ? t.noProducts : t.noPosts).toLowerCase() });
        },
        get delFx() {
            const D = this.del;
            const p = D.p;
            if (!p) {
                return [];
            }
            const n = this.node(D.k);
            const slug = n ? n.slugs[cfg.main] || '' : '';
            const target = this.delMove && D.target ? this.node(D.target) : null;
            const parent = n && n.parent ? this.node(n.parent) : null;
            const to = target ? '/' + (target.slugs[cfg.main] || '') : parent ? '/' + (parent.slugs[cfg.main] || '') : t.home;
            const fx = [];
            fx.push(fill(p.categories > 1 ? t.fxPagesKids : t.fxPage, { slug, n: p.categories - 1, to }));
            if (p.items) {
                fx.push(this.delMove ? fill(t.fxItemsMove, { n: p.items, noun: this.delNoun, name: this.delTargetName || '…' }) : fill(t.fxItemsDetach, { n: p.items, noun: this.delNoun }));
            }
            fx.push(fill(t.fxRedirects, { n: p.categories }));
            if (p.menuLinks) {
                fx.push(fill(t.fxMenu, { n: p.menuLinks, name: this.delTargetName }));
            }
            if (!D.merge && D.children === 'lift' && p.children) {
                fx.push(fill(t.fxKidsUp, { n: p.children, parent: parent && !parent.locked ? '"' + parent.name + '"' : parent ? '"' + parent.name + '"' : t.outer }));
            }
            return fx;
        },
        get delBtn() {
            const D = this.del;
            const p = D?.p;
            if (!p) {
                return D && D.merge ? fill(t.mergeOk, { name: '…' }) : t.delOk;
            }
            if (D.merge) {
                return p.items ? fill(t.mergeOkItems, { n: p.items, noun: this.delNoun }) : fill(t.mergeOk, { name: this.delTargetName || '…' });
            }
            if (p.items) {
                const head = D.children === 'delete' && p.categories > 1 ? fill(t.delOkMany, { n: p.categories }) + t.andItems : t.delAnd;
                return head + fill(this.delMove ? t.delMoveItems : t.delDetachItems, { n: p.items, noun: this.delNoun });
            }
            return D.children === 'delete' && p.categories > 1 ? fill(t.delOkMany, { n: p.categories }) : t.delOk;
        },
        delClose() {
            this.del = null;
        },
        async delGo(confirm = false) {
            const D = this.del;
            if (!D || D.busy || !D.p) {
                return;
            }
            const p = D.p;
            if (this.delMove && (p.items || D.merge) && !D.target) {
                D.err = t.pickTarget;
                this.$nextTick(() => document.getElementById('cm-dt')?.focus());
                return;
            }
            if (p.sectionChange && !confirm) {
                const D2 = D;
                this.del = null;
                this.askOpen({
                    title: fill(t.cxLeaveTitle, { n: p.leavingItems }),
                    lines: [fill(t.cxLeaveLine, { n: p.leavingItems, from: cfg.label })],
                    ok: this.delBtnFor(D2),
                    danger: true,
                }, () => {
                    this.del = D2;
                    this.delGo(true);
                });
                return;
            }
            D.busy = true;
            const target = this.delMove && D.target ? D.target : null;
            const name = D.name;
            let r;
            try {
                r = D.merge
                    ? await this.$wire.merge(cfg.section, D.k, target, confirm)
                    : await this.$wire.delete(cfg.section, D.k, p.children ? (D.children === 'delete' ? 'delete' : 'lift') : null, p.items ? (this.delMove ? 'reassign' : 'detach') : null, target, confirm);
            } catch {
                D.busy = false;
                this.lost(() => this.delGo(confirm));
                return;
            }
            D.busy = false;
            if (!r.ok) {
                D.err = r.message;
                return;
            }
            const into = this.delTargetName;
            this.del = null;
            this.refreshed();
            let text = D.merge ? fill(t.merged, { name, into }) : fill(r.categories > 1 ? t.deletedKids : t.deleted, { name, n: r.categories - 1 });
            if (r.items) {
                text += ' ' + (target ? fill(D.merge ? t.movedItems : t.movedItemsTo, { n: r.items, noun: this.delNoun, into }) : fill(t.detachedItems, { n: r.items, noun: this.delNoun }));
            }
            this.say(text, 'success');
        },
        delBtnFor(D) {
            const keep = this.del;
            this.del = D;
            const label = this.delBtn;
            this.del = keep;
            return label;
        },

        // ---- the editor drawer ------------------------------------------------------------------
        openAdd(parent, child = false) {
            if (!cfg.canManage) {
                return;
            }
            const p = parent ? this.node(parent) : null;
            if (p && p.lv >= cfg.limits.depth) {
                this.say(fill(t.tooDeepAdd, { n: cfg.limits.depth }), 'error');
                return;
            }
            const d = blank();
            d.parent = parent || this.root()?.key || '';
            this.panOpen({ mode: 'add', key: null, child: child && !!p && !p.locked, d });
        },
        openEdit(k, lang = null) {
            const n = this.node(k);
            if (!n) {
                return;
            }
            const d = blank();
            ['names', 'slugs', 'descriptions', 'seoTitles', 'seoDescriptions'].forEach((f) => {
                d[f] = Object.assign({}, n[f]);
            });
            d.parent = n.parent || '';
            d.listTemplate = n.listTemplate || '';
            d.detailTemplate = n.detailTemplate || '';
            d.cover = n.cover;
            this.panOpen({ mode: 'edit', key: k, child: false, d, node: n, lang });
        },
        panOpen(o) {
            cfg.locales.forEach((c) => {
                o.d.auto[c] = o.mode === 'add' || !o.d.slugs[c];
                // The Google fields read back as text, so an absent one starts as '' and an untouched
                // editor never counts as changed.
                o.d.seoTitles[c] = String(o.d.seoTitles[c] ?? '');
                o.d.seoDescriptions[c] = String(o.d.seoDescriptions[c] ?? '');
            });
            this.pan = Object.assign({ lang: cfg.main, more: false, touched: false, saving: false, err: null }, o, { lang: o.lang || cfg.main });
            this.pushSeo();
            this.pullSeo();
            this.pan.base = JSON.stringify(this.pan.d);
            this.$nextTick(() => document.getElementById('cm-name')?.focus());
            this.parentsLoad();
        },
        get panTitle() {
            const P = this.pan;
            if (!P) {
                return '';
            }
            if (P.mode === 'edit') {
                return t.editTitle;
            }
            const p = P.child ? this.node(P.d.parent) : null;
            return p ? fill(t.addChildTitle, { name: p.name }) : t.addTitle;
        },
        get cur() {
            return this.pan ? this.pan.lang : cfg.main;
        },
        langSt(c) {
            const d = this.pan.d;
            if (!(d.names[c] || '').trim()) {
                return c === cfg.main ? 'miss' : 'empty';
            }
            return d.slugs[c] ? 'full' : 'miss';
        },
        get lineText() {
            const c = this.cur;
            if (c === cfg.main) {
                return t.lineSrc;
            }
            const st = this.langSt(c);
            return fill(st === 'full' ? t.lineFull : t.lineEmpty, { lang: cfg.langNames[c] || c });
        },
        pickLang(c) {
            this.pullSeo();
            this.pan.lang = c;
            this.pushSeo();
            this.$nextTick(() => document.getElementById('cm-name')?.focus());
        },
        // The Google fields are the shared <x-bs-admin::seo-fields>, bound to the component's
        // properties for the language in view: copied in when the language changes, read back before.
        pushSeo() {
            const d = this.pan.d;
            const c = this.pan.lang;
            this.$wire.seoName = d.names[c] || '';
            this.$wire.seoSlug = d.slugs[c] || '';
            this.$wire.seoTitle = d.seoTitles[c] || '';
            this.$wire.seoDescription = d.seoDescriptions[c] || '';
            this.$wire.address = { base: cfg.bases[c] || '', home: null, homeSlug: 'home' };
        },
        pullSeo() {
            if (!this.pan) {
                return;
            }
            const d = this.pan.d;
            const c = this.pan.lang;
            d.seoTitles[c] = String(this.$wire.seoTitle ?? '');
            d.seoDescriptions[c] = String(this.$wire.seoDescription ?? '');
        },
        get fName() {
            return this.pan.d.names[this.cur] || '';
        },
        set fName(v) {
            const d = this.pan.d;
            const c = this.cur;
            d.names[c] = v;
            if (d.auto[c]) {
                d.slugs[c] = slugify(v, cfg.limits.slug);
                this.$wire.seoSlug = d.slugs[c];
            }
            this.$wire.seoName = v;
        },
        get fSlug() {
            return this.pan.d.slugs[this.cur] || '';
        },
        set fSlug(v) {
            this.pan.d.slugs[this.cur] = v;
            this.pan.d.auto[this.cur] = false;
            this.$wire.seoSlug = v;
        },
        get nameBad() {
            return this.pan.touched && this.cur === cfg.main && !this.fName.trim();
        },
        get slugBad() {
            const s = this.fSlug;
            return s !== '' && !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(s);
        },
        get slugChanged() {
            const P = this.pan;
            return P.mode === 'edit' && !!P.node.slugs[this.cur] && this.fSlug !== P.node.slugs[this.cur];
        },
        get slugOk() {
            return !this.slugBad && this.fSlug !== '' && (this.pan.mode === 'add' || this.slugChanged);
        },
        get slugHint() {
            const base = cfg.bases[this.cur] || '';
            return (this.fSlug ? fill(t.slugPage, { url: base + this.fSlug }) + ' ' : '') + (this.pan.mode === 'add' ? t.slugAuto : t.slugKeep);
        },
        get slugRegen() {
            const c = this.cur;
            return !this.pan.d.auto[c] && !!this.fName.trim() && slugify(this.fName, cfg.limits.slug) !== this.fSlug;
        },
        regen() {
            const c = this.cur;
            this.pan.d.auto[c] = true;
            this.fSlug = slugify(this.fName, cfg.limits.slug);
            this.pan.d.auto[c] = true;
        },
        get slugWarn() {
            return fill(t.slugWarn, { old: this.pan.node.slugs[this.cur] });
        },
        get isRootNode() {
            return !!(this.pan && this.pan.node && this.pan.node.locked);
        },
        // The parent choices of a saved category: every place it may go, grouped by section, as the
        // server reads them ("Chuyển đến…"'s list). Until they come (or offline) the places in
        // this section stay; a new category always starts in this section.
        async parentsLoad() {
            const P = this.pan;
            if (!P || P.mode !== 'edit' || this.isRootNode) {
                return;
            }
            try {
                const r = await this.$wire.moveTargets(cfg.section, P.key, '');
                if (this.pan === P && r.ok) {
                    P.tgtItems = r.items;
                    P.tgt = r.groups;
                }
            } catch {
                // offline: the places in this section stay
            }
        },
        get parentGroups() {
            const P = this.pan;
            if (P.tgt) {
                return P.tgt.map((g) => ({
                    label: g.label,
                    options: g.options.filter((o) => o.reason !== 'depth_exceeded').map((o) => {
                        const off = !o.ok && !o.current;
                        const label = o.key === null ? t.outerOpt : o.root ? fill(t.mvUnder, { section: g.label }) : '  '.repeat(Math.max(0, o.level - 1)) + (o.level > 1 ? '└ ' : '') + o.name;
                        return { key: o.key === null ? '' : o.key, label: label + (off ? ' — ' + t.lockedOpt : ''), off, confirm: !!o.confirm, section: g.label };
                    }),
                })).filter((g) => g.options.length);
            }
            return [{ label: cfg.label, options: this.parents }];
        },
        // The parent picked moves the category's posts to another section: what Save will do.
        get panCx() {
            const P = this.pan;
            if (!P || !P.tgt || !P.tgtItems || (P.d.parent || '') === (P.node.parent || '')) {
                return null;
            }
            for (const g of P.tgt) {
                const o = g.options.find((x) => (x.key === null ? '' : x.key) === (P.d.parent || ''));
                if (o) {
                    return o.confirm ? { n: P.tgtItems, section: g.label } : null;
                }
            }
            return null;
        },
        get panCxLines() {
            const c = this.panCx;
            return c ? [fill(t.cxLine, { n: c.n, from: cfg.label, section: c.section })] : [];
        },
        get saveText() {
            const c = this.panCx;
            return c ? fill(t.saveMove, { n: c.n }) : t.save;
        },
        // Where the category may go in this section: every place with room for its branch.
        get parents() {
            void this.ver;
            const P = this.pan;
            const out = cfg.products ? [{ key: '', label: t.outerOpt, off: false }] : [];
            const skip = P.key ? this.branch(P.key) : [];
            const h = P.key ? this.height(P.key) : 1;
            this.all().forEach((n) => {
                if (skip.includes(n.key)) {
                    return;
                }
                out.push({ key: n.key, label: n.locked ? fill(t.mvUnder, { section: cfg.label }) : '  '.repeat(n.lv - 1) + (n.lv > 1 ? '└ ' : '') + n.name, off: n.lv + h > cfg.limits.depth });
            });
            return out.filter((o) => !o.off || o.key === P.d.parent);
        },
        get panDirty() {
            if (!this.pan) {
                return false;
            }
            this.pullSeo();
            return JSON.stringify(Object.assign({}, this.pan.d, { auto: this.pan.d.auto })) !== this.pan.base;
        },
        panClose() {
            if (!this.pan) {
                return;
            }
            if (this.panDirty) {
                this.askUnsaved(() => {});
                return;
            }
            this.pan = null;
        },
        // The unsaved-changes question: leave the drawer without saving (then go()), or save.
        askUnsaved(go) {
            this.askOpen({ title: t.unsavedTitle, lead: t.unsavedText, ok: t.unsavedDrop, alt: t.unsavedSave, danger: true }, () => {
                this.pan = null;
                go();
            }, () => this.save());
        },
        pickCover() {
            this.$wire.openImagePicker('gallery');
        },
        coverPicked(detail) {
            if (this.pan && detail && detail.key) {
                this.pan.d.cover = { key: detail.key, url: detail.url };
                this.pan.d.coverChanged = true;
            }
        },
        dropCover() {
            this.pan.d.cover = null;
            this.pan.d.coverChanged = true;
        },
        async save(again = false) {
            const P = this.pan;
            if (!P || P.saving) {
                return;
            }
            this.pullSeo();
            P.touched = true;
            const d = P.d;
            if (!(d.names[cfg.main] || '').trim()) {
                P.lang = cfg.main;
                this.pushSeo();
                this.$nextTick(() => document.getElementById('cm-name')?.focus());
                return;
            }
            const cx = P.mode === 'edit' ? this.panCx : null;
            if (cx && !P.confirmed) {
                // the posts change section: say so and ask first, as "Chuyển đến…" does
                this.askOpen({ title: fill(t.cxSaveTitle, { section: cx.section }), lead: t.onSave, lines: this.panCxLines, ok: fill(t.saveMove, { n: cx.n }) }, () => {
                    P.confirmed = true;
                    this.save(again);
                });
                return;
            }
            const langs = cfg.locales.filter((c) => (d.names[c] || '').trim());
            const pick = (map) => Object.fromEntries(langs.map((c) => [c, String(map[c] || '').trim()]));
            const data = {
                names: pick(d.names), slugs: Object.fromEntries(langs.filter((c) => d.slugs[c]).map((c) => [c, d.slugs[c]])),
                descriptions: pick(d.descriptions), seoTitles: pick(d.seoTitles), seoDescriptions: pick(d.seoDescriptions),
                listTemplate: d.listTemplate || null, detailTemplate: d.detailTemplate || null,
                cover: d.coverChanged ? (d.cover ? d.cover.key : '') : null,
            };
            P.saving = true;
            P.err = null;
            let r;
            try {
                r = await this.$wire.save(cfg.section, P.key, P.mode === 'add' ? d.parent || null : null, data);
            } catch {
                P.saving = false;
                P.err = 'network';
                P.again = again;
                return;
            }
            P.saving = false;
            if (!r.ok) {
                P.err = r.reason === 'taxonomy_invalid' ? 'gone' : 'refused';
                P.errText = r.message;
                return;
            }
            const name = data.names[cfg.main];
            this.refreshed();
            if (P.mode === 'edit' && (d.parent || null) !== (P.node.parent || null) && !this.isRootNode) {
                const pos = this.kidsOf(d.parent || null).filter((c) => c.key !== P.key).length;
                this.pan = null;
                await this.$nextTick();
                const moved = await this.doMove(P.key, d.parent || null, pos, !!P.confirmed);
                if (moved === null) {
                    this.say(fill(t.saved, { name }), 'success', { undo: true });
                }
                return;
            }
            this.say(fill(P.mode === 'add' ? t.added : t.saved, { name }), 'success');
            if (again) {
                const keep = d.parent;
                this.pan = null;
                this.$nextTick(() => {
                    this.openAdd(keep, P.child);
                });
                return;
            }
            this.pan = null;
        },
        panRetry() {
            const P = this.pan;
            if (P.err === 'gone') {
                this.pan = null;
                this.reload();
                return;
            }
            this.save(!!P.again);
        },

        // ---- a row's other actions --------------------------------------------------------------
        url(n) {
            return 'https://' + (cfg.bases[cfg.main] || '') + (n.slugs[cfg.main] || '');
        },
        visit(k) {
            const n = this.node(k);
            if (n) {
                window.open(this.url(n), '_blank', 'noopener');
            }
        },
        copyLink(k) {
            const n = this.node(k);
            if (!n) {
                return;
            }
            const url = this.url(n);
            const fail = () => {
                this.copyUrl = url;
                this.$nextTick(() => document.getElementById('cm-copy')?.select());
            };
            try {
                navigator.clipboard.writeText(url).then(() => this.say(fill(t.copied, { url }), 'success'), fail);
            } catch {
                fail();
            }
        },
        firstMissing(k) {
            const n = this.node(k);
            return n ? this.missing(n)[0] || null : null;
        },
        async addExample(name) {
            const r = await this.$wire.save(cfg.section, null, null, { names: { [cfg.main]: name }, slugs: {}, descriptions: {}, seoTitles: {}, seoDescriptions: {} }).catch(() => null);
            if (!r) {
                this.lost(() => this.addExample(name));
                return;
            }
            if (!r.ok) {
                this.refused(r);
                return;
            }
            this.refreshed();
            this.say(fill(t.example, { name }), 'success');
        },
    };
});
