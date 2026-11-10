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

const texts = new Proxy({}, { get: (o, k) => (k === 'saveMove' ? 'Lưu và chuyển :n bài' : k === 'cxLine' ? ':n bài sẽ chuyển từ :from sang :section' : String(k)) });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const node = (key, parent, name, more = {}) => ({
    key, parent, name, names: { vi: name }, slugs: { vi: key.toLowerCase() }, descriptions: {}, seoTitles: {}, seoDescriptions: {}, trail: [],
    listTemplate: null, detailTemplate: null, cover: null, items: 0, locked: false, editable: true, movable: true, ...more,
});

// The screen over rows [node, level]; `wire` answers its server calls.
function screen(rows, wire = {}) {
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
    const c = factory({ section: 'posts', label: 'Tin tức', main: 'vi', locales: ['vi'], langNames: { vi: 'Tiếng Việt' }, bases: { vi: 'web.vn/' }, layouts: ['grid'], products: false, canManage: true, limits: { depth: 3, slug: 120, name: 120 }, texts });
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
