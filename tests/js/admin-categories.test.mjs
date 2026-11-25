// The Chuyên mục screen's browser part (resources/js/admin-categories.js) — its editor drawer, which
// keeps its state in Alpine, the leave guard and the dialogs' texts — in a DOM without a browser:
// `node --test tests/js/admin-categories.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>');
globalThis.window = dom.window;
globalThis.document = dom.window.document;
let factory = null;
window.Alpine = { data: (name, fn) => (factory = fn), $data: () => null };
const went = [];
window.Livewire = { navigate: (url) => went.push(url) };
await import('../../resources/js/admin-categories.js');

const texts = new Proxy({}, { get: (o, k) => (k === 'saveMove' ? 'Lưu và chuyển :n bài' : k === 'cxLine' ? ':n bài sẽ chuyển từ :from sang :section' : k === 'langProg' ? ':done/:total phiên bản đủ' : k === 'bandItems' ? ':n ô' : String(k)) });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const node = (key, parent, name, more = {}) => ({
    key, parent, name, names: { vi: name }, slugs: { vi: key.toLowerCase() }, descriptions: {}, seoTitles: {}, seoDescriptions: {}, trail: [],
    listTemplate: null, detailTemplate: null, cover: null, items: 0, locked: false, editable: true, movable: true, ...more,
});

// The screen over rows [node, level]; `wire` answers its server calls.
function screen(rows, wire = {}, more = {}) {
    document.body.innerHTML = '';
    const root = document.createElement('div');
    rows.forEach(([n, lv]) => {
        const el = document.createElement('div');
        el.setAttribute('data-tree-row', '');
        el.dataset.k = n.key;
        el.dataset.lv = String(lv);
        el.dataset.node = JSON.stringify(n);
        root.append(el);
    });
    document.body.append(root);
    const calls = [];
    const c = factory({ section: 'posts', label: 'Tin tức', main: 'vi', locales: ['vi'], langNames: { vi: 'Tiếng Việt' }, bases: { vi: 'web.vn/' }, layouts: ['grid'], products: false, canManage: true, limits: { depth: 3, slug: 120, name: 120 }, texts, ...more });
    const $wire = new Proxy({}, {
        get: (o, k) => (k in o ? o[k] : async (...a) => {
            calls.push([k, ...a]);
            return wire[k] ? wire[k](...a) : { ok: true };
        }),
    });
    Object.assign(c, { $root: root, $el: root, $wire, $watch: () => {}, $nextTick: (fn) => Promise.resolve().then(fn) });
    c.init();
    return { c, calls };
}

const R = node('01R', null, 'Tin tức', { locked: true });
const A = node('01A', '01R', 'Tin công ty', { items: 4 });
const B = node('01B', '01R', 'Kiến thức');

test('a dirty drawer stops a link inside the admin and asks first; leaving then goes on', async () => {
    went.length = 0;
    const { c } = screen([[R, 1], [A, 2], [B, 2]]);
    c.openEdit('01A');
    c.fName = 'Tin công ty mới';
    const e = new window.CustomEvent('livewire:navigate', { cancelable: true, detail: { url: new URL('https://web.vn/admin/pages') } });
    document.dispatchEvent(e);
    assert.equal(e.defaultPrevented, true);
    assert.equal(c.ask.title, 'unsavedTitle');
    c.askOk();
    assert.equal(c.pan, null);
    assert.deepEqual(went, ['https://web.vn/admin/pages']);
    c.destroy();
});

test('a dirty drawer makes closing the tab ask; a clean one, a history move or a gone screen do not', () => {
    const { c } = screen([[R, 1], [A, 2]]);
    c.openEdit('01A');
    const unload = () => {
        const e = new window.Event('beforeunload', { cancelable: true });
        window.dispatchEvent(e);
        return e.defaultPrevented;
    };
    assert.equal(unload(), false, 'nothing changed yet');
    c.fName = 'Tin công ty mới';
    assert.equal(unload(), true);
    const back = new window.CustomEvent('livewire:navigate', { cancelable: true, detail: { url: 'https://web.vn/x', history: true } });
    document.dispatchEvent(back);
    assert.equal(back.defaultPrevented, false);
    c.destroy();
    assert.equal(unload(), false, 'the guard goes with the screen');
});

test('a template the theme no longer has stays visible as its own choice', () => {
    const { c } = screen([[R, 1], [node('01G', '01R', 'Cũ', { listTemplate: 'old-list', detailTemplate: 'grid' }), 2]]);
    c.openEdit('01G');
    assert.equal(c.goneTpl('listTemplate'), 'old-list');
    assert.equal(c.goneTpl('detailTemplate'), null);
    assert.equal(c.pan.d.listTemplate, 'old-list');
});

test('a parent in another section is asked about before Save, then moved with the consent', async () => {
    const targets = {
        ok: true, items: 4, groups: [
            { section: 'posts', label: 'Tin tức', current: true, locked: false, options: [{ key: '01R', name: '', level: 1, root: true, current: true, ok: false, confirm: false }] },
            { section: 'services', label: 'Dịch vụ', current: false, locked: false, options: [
                { key: '01S', name: '', level: 1, root: true, current: false, ok: true, confirm: true },
                { key: '01T', name: 'Sâu', level: 3, root: false, current: false, ok: false, reason: 'depth_exceeded', confirm: false },
            ] },
        ],
    };
    const { c, calls } = screen([[R, 1], [A, 2]], { moveTargets: () => targets, save: () => ({ ok: true }), move: () => ({ ok: true, undo: 'u1' }) });
    c.openEdit('01A');
    await wait(5);
    assert.deepEqual(c.parentGroups.map((g) => [g.label, g.options.map((o) => o.key)]), [['Tin tức', ['01R']], ['Dịch vụ', ['01S']]]);
    c.pan.d.parent = '01S';
    assert.deepEqual(c.panCxLines, ['4 bài sẽ chuyển từ Tin tức sang Dịch vụ']);
    assert.equal(c.saveText, 'Lưu và chuyển 4 bài');
    await c.save();
    assert.equal(calls.filter((x) => x[0] === 'save').length, 0, 'nothing is saved before the answer');
    assert.equal(c.ask.ok, 'Lưu và chuyển 4 bài');
    c.askOk();
    await wait(10);
    const move = calls.find((x) => x[0] === 'move');
    assert.deepEqual(move.slice(1, 3), ['posts', '01A']);
    assert.equal(move[3], '01S');
    assert.equal(move[5], true, 'the move carries the consent');
});

test('the search and "Chuyển đến…" wait on their own timers', async () => {
    const { c, calls } = screen([[R, 1], [A, 2], [B, 2]], { search: () => ({ ok: true, keys: ['01A'] }), moveTargets: () => ({ ok: true, items: 0, groups: [] }) });
    c.q = 'tin';
    c.search();
    await c.openMv('01B');
    c.mv.q = 'ki';
    c.mvQuery();
    await wait(320);
    assert.equal(calls.filter((x) => x[0] === 'search').length, 1, 'the search still ran');
    assert.equal(calls.filter((x) => x[0] === 'moveTargets').length, 2);
});

test('typed text is put into a sentence as typed', () => {
    const { c } = screen([[R, 1]]);
    assert.equal(c.fill('Không có chuyên mục nào khớp ":q"', { q: "$& và $'" }), 'Không có chuyên mục nào khớp "$& và $\'"');
});

test('an emptied slug is made again from the name when the field is left; a name the browser cannot read stays blank for the server', () => {
    const { c } = screen([[R, 1], [A, 2]]);
    c.openEdit('01A');
    c.fSlug = '';
    c.slugBlur();
    assert.equal(c.fSlug, 'tin-cong-ty');
    assert.equal(c.slugBad, false);
    c.fSlug = '';
    c.fName = '!!!';
    c.fSlug = '';
    c.slugBlur();
    assert.equal(c.fSlug, '');
    assert.equal(c.slugBad, false);
    c.destroy();
});

test('the drawer shows the source and the open version as pills; «+N» lists every version with its state, finds one by name or code and opens it', async () => {
    const langs = { locales: ['vi', 'en', 'ja', 'zh'], langNames: { vi: 'Tiếng Việt', en: 'Tiếng Anh', ja: 'Tiếng Nhật', zh: 'Tiếng Trung' }, bases: { vi: 'web.vn/', en: 'web.vn/en/', ja: 'web.vn/ja/', zh: 'web.vn/zh/' } };
    const both = node('01M', '01R', 'Tin công ty', { names: { vi: 'Tin công ty', en: 'Company news', ja: 'ニュース' }, slugs: { vi: 'tin-cong-ty', en: 'company-news' } });
    const { c } = screen([[R, 1], [both, 2]], {}, langs);
    c.openEdit('01M');
    assert.deepEqual(langs.locales.filter((l) => c.langShown(l)), ['vi'], 'the source alone while it is open');
    assert.equal(c.langMore, 3);
    assert.equal(c.langProg, '2/4 phiên bản đủ', 'the line is the text with its numbers');
    assert.deepEqual(langs.locales.map((l) => c.langState(l)), ['stFull', 'stFull', 'stMiss', 'stEmpty'], 'a name without its link still lacks a field');
    assert.equal(c.lineWarn, false, 'the source never warns');

    c.langToggle();
    assert.equal(c.langOpen, true);
    const group = document.createElement('div');
    const inside = document.createElement('button');
    group.append(inside);
    document.body.append(group);
    c.langLeave({ relatedTarget: inside, currentTarget: group });
    assert.equal(c.langOpen, true, 'focus moving within the group keeps the panel');
    c.langLeave({ relatedTarget: null, currentTarget: group });
    assert.equal(c.langOpen, true, 'focus leaving the window keeps it too');
    c.langLeave({ relatedTarget: document.body, currentTarget: group });
    assert.equal(c.langOpen, false, 'focus moving out of the group shuts the panel');
    c.langToggle();
    c.langQ = 'trung';
    assert.deepEqual(langs.locales.filter((l) => c.langHit(l)), ['zh'], 'by name, without its accents');
    c.langQ = 'JA';
    assert.deepEqual(langs.locales.filter((l) => c.langHit(l)), ['ja'], 'or by code');
    assert.equal(c.langAnyHit(), true, 'a match keeps the panel without the empty message');
    c.langQ = 'khong co';
    assert.equal(c.langAnyHit(), false, 'nothing matches: the panel shows the shared no-result message');
    c.langQ = 'JA';
    c.langGo('zh');
    assert.equal(c.langOpen, false);
    assert.equal(c.langQ, '', 'the search starts empty next time');
    assert.equal(c.cur, 'zh');
    assert.deepEqual(langs.locales.filter((l) => c.langShown(l)), ['vi', 'zh'], 'the version opened joins the source');
    assert.equal(c.langMore, 2);
    assert.equal(c.lineWarn, true, 'a version without its name warns');
    c.fName = 'Xin wen';
    c.slugBlur();
    assert.equal(c.langState('zh'), 'stFull');
    assert.equal(c.lineWarn, false);

    c.langToggle();
    c.panClose();
    c.pan = null;
    c.openEdit('01M');
    assert.equal(c.langOpen, false, 'a drawer opened again starts with the panel shut');
    c.destroy();
});

test('the rows are read from the document, so a read made from inside the drawer (whose own root holds no rows) still finds them', () => {
    const { c } = screen([[R, 1], [A, 2]]);
    c.$root = document.createElement('div');
    c.$el = c.$root;
    assert.deepEqual(c.all().map((n) => n.key), ['01R', '01A']);
    c.destroy();
});

// ---- «Khối trên / dưới danh sách» --------------------------------------------------------------
const bands = {
    before_list: { mode: 'own', own: { vi: [{ name: 'Vì sao chọn An Vinh?', kind: 'Tính năng', items: 4 }, { name: 'Kêu gọi', kind: 'Kêu gọi', items: 0 }] } },
    after_list: { mode: 'default', own: {} },
};
const site = { before_list: {}, after_list: { vi: [{ name: 'Chưa thấy vị trí phù hợp?', kind: 'Kêu gọi' }] } };
const J = node('01J', '01R', 'Tuyển dụng', { bands });
const bandCfg = { bandSite: site, bandUrl: 'https://web.vn/admin/taxonomies/__key__/bands/__slot__' };

test('each band says what it shows now and where it comes from, and warns before its own blocks are dropped', () => {
    const { c } = screen([[R, 1], [J, 2]], {}, bandCfg);
    c.openEdit('01J');
    const top = c.bandView('before_list');
    assert.deepEqual([top.kind, top.nowTitle, top.list, top.canEdit, top.editHint, top.dropWarn], ['own', 'bandOwnT', ['Vì sao chọn An Vinh? · Tính năng · 4 ô', 'Kêu gọi · Kêu gọi'], true, 'bandHintOwnHas', false]);
    const bot = c.bandView('after_list');
    assert.deepEqual([bot.kind, bot.nowSub, bot.list, bot.editHint, bot.defSub], ['default', 'bandDefS', ['Chưa thấy vị trí phù hợp? · Kêu gọi'], 'bandHintDef', 'bandOptDefHas']);

    c.pan.d.bands.before_list = 'none';
    const hidden = c.bandView('before_list');
    assert.deepEqual([hidden.kind, hidden.canEdit, hidden.list, hidden.dropWarn, hidden.short], ['none', false, [], true, 'bandShortNone']);
    c.pan.d.bands.before_list = 'default';
    assert.deepEqual([c.bandView('before_list').kind, c.bandView('before_list').nowTitle, c.bandView('before_list').defSub], ['empty', 'bandEmptyDef', 'bandOptDefNone']);
    c.pan.d.bands.after_list = 'own';
    assert.deepEqual([c.bandView('after_list').kind, c.bandView('after_list').nowTitle, c.bandView('after_list').editHint], ['empty', 'bandEmptyOwn', 'bandHintOwnEmpty']);
});

test('Save sends only the bands whose choice moved, with the mode it read; a new category starts on the website\'s default', async () => {
    const { c, calls } = screen([[R, 1], [J, 2]], {}, bandCfg);
    c.openEdit('01J');
    c.pan.d.bands.after_list = 'none';
    await c.save();
    assert.deepEqual(calls.filter((x) => x[0] === 'save').at(-1)[4].bands, { after_list: { mode: 'none', read: 'default' } });
    c.openEdit('01J');
    await c.save();
    assert.deepEqual(calls.filter((x) => x[0] === 'save').at(-1)[4].bands, {});
    c.openAdd('01R');
    assert.deepEqual(c.pan.d.bands, { before_list: 'default', after_list: 'default' });
    c.pan.d.names.vi = 'Dự án mới';
    c.pan.d.bands.before_list = 'none';
    await c.save();
    assert.deepEqual(calls.filter((x) => x[0] === 'save').at(-1)[4].bands, { before_list: { mode: 'none', read: 'default' } });
});

test('«Sửa trong Trình dựng trang» opens the band in the drawer\'s language; a new category is saved first, unsaved changes are asked about', async () => {
    went.length = 0;
    const { c, calls } = screen([[R, 1], [J, 2]], { save: () => ({ ok: true, key: '01N' }) }, bandCfg);
    c.openEdit('01J');
    c.editBand('after_list');
    assert.deepEqual(went, ['https://web.vn/admin/taxonomies/01J/bands/after_list?locale=vi']);
    assert.equal(c.toast.text, 'bandOpening');

    c.openEdit('01J');
    c.fName = 'Tuyển dụng mới';
    c.editBand('before_list');
    assert.equal(c.ask.title, 'unsavedTitle');
    c.askOk();
    assert.equal(c.pan, null);
    assert.equal(went.at(-1), 'https://web.vn/admin/taxonomies/01J/bands/before_list?locale=vi');
    assert.equal(calls.filter((x) => x[0] === 'save').length, 0, 'leaving without saving saves nothing');

    c.openAdd('01R');
    c.fName = 'Dự án';
    c.editBand('after_list');
    await wait(0);
    assert.equal(calls.filter((x) => x[0] === 'save').length, 1);
    assert.equal(went.at(-1), 'https://web.vn/admin/taxonomies/01N/bands/after_list?locale=vi');
    c.destroy();
});

test('back from the builder, the category\'s drawer opens again at the bands with the message', () => {
    const { c } = screen([[R, 1], [J, 2]], {}, { ...bandCfg, open: '01J', flash: 'Đã lưu' });
    assert.equal(c.pan.key, '01J');
    assert.equal(c.pan.more, true);
    assert.deepEqual([c.toast.text, c.toast.tone], ['Đã lưu', 'success']);
    const none = screen([[R, 1]], {}, { ...bandCfg, open: '01X', flash: 'Đã lưu' }).c;
    assert.equal(none.pan, null, 'a category gone meanwhile opens nothing');
});
