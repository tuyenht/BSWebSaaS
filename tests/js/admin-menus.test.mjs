// What the Trình đơn screen sends when "Lưu" is pressed, and the rows it hands the shared tree
// (resources/js/admin-menus.js), without a browser: `node --test tests/js/admin-menus.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildDraft, goneKeysOf, goneSpanOf, iconGroupsOf, treeRowsOf } from '../../resources/js/admin-menus.js';

const opts = (more = {}) => ({ hidden: false, new_tab: false, icon: null, description: {}, cta: false, mega: null, badge: null, device: 'all', ...more });
const row = (key, parent, labels, more = {}) => ({ key, parent, link: { type: 'url', url: '/' + key }, labels, options: opts(more) });
const sig = (it) => JSON.stringify([it.parent || null, it.link, it.labels, it.options]);
const savedOf = (items) => Object.fromEntries(items.map((it) => [it.key, sig(it)]));

const A = row('01A', null, { vi: 'Sản phẩm', en: 'Products' });
const A1 = row('01A1', '01A', { vi: 'Máy tiện' });
const A2 = row('01A2', '01A', { vi: 'Máy phay' });
const A21 = row('01A21', '01A2', { vi: 'Phay đứng' });
const B = row('01B', null, { vi: 'Liên hệ' });
const all = [A, A1, A2, A21, B];

test('the tree names every live row once, in display order, with its parent', () => {
    const d = buildDraft(all, savedOf(all), 'v1');
    assert.equal(d.version, 'v1');
    assert.deepEqual(d.tree, [
        { key: '01A', parent: null },
        { key: '01A1', parent: '01A' },
        { key: '01A2', parent: '01A' },
        { key: '01A21', parent: '01A2' },
        { key: '01B', parent: null },
    ]);
    assert.equal(new Set(d.tree.map((n) => n.key)).size, d.tree.length);
    assert.deepEqual([d.creates, d.updates, d.removes], [[], [], []]);
});

test('a removed row is sent with every row under it', () => {
    const left = [A, A1, B];
    const d = buildDraft(left, savedOf(all), 'v1');
    assert.deepEqual(d.removes.sort(), ['01A2', '01A21']);
    assert.deepEqual(d.tree.map((n) => n.key), ['01A', '01A1', '01B']);
    const none = buildDraft([B], savedOf(all), 'v1');
    assert.deepEqual(none.removes.sort(), ['01A', '01A1', '01A2', '01A21']);
});

test('a label cleared in one language is sent as an empty text so the server clears it', () => {
    const cleared = { ...A, labels: { vi: 'Sản phẩm' } };
    const d = buildDraft([cleared, A1, A2, A21, B], savedOf(all), 'v1');
    assert.deepEqual(d.updates, [{ key: '01A', labels: { vi: 'Sản phẩm', en: '' } }]);
    const emptied = { ...B, labels: { vi: '' } };
    const e = buildDraft([A, A1, A2, A21, emptied], savedOf(all), 'v1');
    assert.deepEqual(e.updates, [{ key: '01B', labels: { vi: '' } }]);
});

test('only what changed is sent for a saved row, and a moved row only through the tree', () => {
    const hidden = { ...A1, options: opts({ hidden: true }) };
    const moved = { ...B, parent: '01A' };
    const d = buildDraft([A, hidden, A2, A21, moved], savedOf(all), 'v1');
    assert.deepEqual(d.updates, [{ key: '01A1', options: hidden.options }]);
    assert.deepEqual(d.tree.at(-1), { key: '01B', parent: '01A' });
});

test('a row not saved yet keeps its new: key, in the tree and as a create', () => {
    const fresh = row('new:1', '01A', { vi: 'Ứng dụng' });
    const child = row('new:2', 'new:1', { vi: 'Ô tô' });
    const d = buildDraft([A, fresh, child, A1, A2, A21, B], savedOf(all), 'v1');
    assert.deepEqual(d.tree.slice(1, 3), [{ key: 'new:1', parent: '01A' }, { key: 'new:2', parent: 'new:1' }]);
    assert.deepEqual(d.creates.map((c) => c.key), ['new:1', 'new:2']);
    assert.deepEqual(d.creates[0], { key: 'new:1', link: fresh.link, labels: fresh.labels, options: fresh.options });
    assert.deepEqual(d.removes, []);
});

test('the tree rows carry level, place among siblings, child count and guide lines', () => {
    const r = treeRowsOf(all);
    assert.deepEqual(r.map((x) => [x.k, x.parent, x.lv, x.pos, x.size, x.kids]), [
        ['01A', null, 1, 1, 2, 2],
        ['01A1', '01A', 2, 1, 2, 0],
        ['01A2', '01A', 2, 2, 2, 1],
        ['01A21', '01A2', 3, 1, 1, 0],
        ['01B', null, 1, 2, 2, 0],
    ]);
    // the same lines TreeRows::guides draws on the server: └ ├ and the start of a line under a parent
    assert.deepEqual(r.map((x) => x.guides), [
        [{ d: 1, k: 'down' }],
        [{ d: 1, k: 't' }],
        [{ d: 1, k: 'l' }, { d: 2, k: 'down' }],
        [{ d: 2, k: 'l' }],
        [],
    ]);
    assert.equal(r[0].it, A);
});

test('while a filter is on the matches stand flat and in order', () => {
    const r = treeRowsOf([A1, A21], true);
    assert.deepEqual(r.map((x) => [x.k, x.parent, x.lv, x.pos, x.size, x.kids, x.guides.length]), [
        ['01A1', null, 1, 1, 2, 0, 0],
        ['01A21', null, 1, 2, 2, 0, 0],
    ]);
});

const groups = [
    { group: 'Hay dùng', icons: [{ name: 'phone', label: 'Điện thoại' }, { name: 'address', label: 'Địa chỉ' }] },
    { group: 'Nội dung', icons: [] },
    { group: 'Liên hệ', icons: [{ name: 'zalo', label: 'Zalo' }, { name: 'clock', label: 'Giờ mở cửa' }] },
];

test('the icon picker shows every group with icons, or one group of the matches', () => {
    assert.deepEqual(iconGroupsOf(groups, '  ', ':n khớp').map((g) => g.group), ['Hay dùng', 'Liên hệ']);
    const hit = iconGroupsOf(groups, 'DIA CHI', ':n khớp');
    assert.deepEqual(hit, [{ group: '1 khớp', icons: [{ name: 'address', label: 'Địa chỉ' }] }]);
    assert.deepEqual(iconGroupsOf(groups, 'o', ':n khớp')[0].icons.map((ic) => ic.name), ['phone', 'zalo', 'clock']);
    assert.deepEqual(iconGroupsOf(groups, 'không có', ':n khớp'), []);
});

test('only rows whose source is gone for good are offered for the bulk removal', () => {
    const src = (state) => ({ kind: 'page', ref: 'r', title: 't', slug: null, state, untranslated: [] });
    const items = [
        { ...row('g1', null, {}), source: src('gone') },
        { ...row('t1', null, {}), source: src('trashed') },
        { ...row('u1', null, {}), source: src('unpublished') },
        { ...row('l1', null, {}), source: src('live') },
        row('url', null, {}),
        { ...row('g2', 'l1', {}), source: src('gone') },
    ];
    assert.deepEqual(goneKeysOf(items), ['g1', 'g2']);
});

test('a bulk remove of gone rows takes the rows under them once, nested gone rows included', () => {
    const src = (state) => ({ kind: 'page', state });
    const items = [
        { key: 'g1', parent: null, source: src('gone') },
        { key: 'a', parent: 'g1', source: src('live') },
        { key: 'g2', parent: 'a', source: src('gone') },
        { key: 'b', parent: 'g2', source: src('trashed') },
        { key: 'l1', parent: null, source: src('live') },
        { key: 'c', parent: 'l1', source: src('unpublished') },
        { key: 'g3', parent: 'l1', source: src('gone') },
    ];
    const span = goneSpanOf(items);
    assert.deepEqual(span, ['g1', 'a', 'g2', 'b', 'g3']);
    assert.equal(span.length - goneKeysOf(items).length, 2); // a and b ride along; l1 and c stay
});
