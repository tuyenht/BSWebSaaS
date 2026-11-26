// What the Trình đơn screen sends when "Lưu" is pressed, and the rows it hands the shared tree
// (resources/js/admin-menus.js), without a browser: `node --test tests/js/admin-menus.test.mjs`.
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import { buildDraft, descMaxOf, goneKeysOf, goneSpanOf, iconGroupsOf, autoIconOf, suggestIconOf, treeRowsOf } from '../../resources/js/admin-menus.js';

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

test('a large panel switched to a shared block is sent with the block it shows, or none chosen yet', () => {
    const picked = { ...A, options: opts({ mega: { type: 'block', block: '01JABCDEFGHJKMNPQRSTVWXYZ0' } }) };
    const none = { ...B, options: opts({ mega: { type: 'block', block: null } }) };
    const d = buildDraft([picked, A1, A2, A21, none], savedOf(all), 'v1');
    assert.deepEqual(d.updates, [
        { key: '01A', options: picked.options },
        { key: '01B', options: none.options },
    ]);
    // nothing changed once saved as the server hands it back
    assert.deepEqual(buildDraft([picked], savedOf([picked]), 'v2').updates, []);
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

const worded = [
    { group: 'Hay dùng', icons: [{ name: 'factory', label: 'Nhà máy', words: 'nhà máy xưởng sản xuất' }, { name: 'phone', label: 'Điện thoại', words: 'điện thoại gọi hotline' }] },
    { group: 'Liên hệ', icons: [{ name: 'phone', label: 'Điện thoại', words: 'điện thoại gọi hotline' }, { name: 'truck', label: 'Vận chuyển', words: 'vận chuyển giao hàng' }] },
];

test('the icon picker also finds an icon by its search words and lists a repeated icon once', () => {
    assert.deepEqual(iconGroupsOf(worded, 'XUONG', ':n khớp'), [{ group: '1 khớp', icons: [worded[0].icons[0]] }]);
    assert.deepEqual(iconGroupsOf(worded, 'hotline', ':n khớp')[0].icons.map((ic) => ic.name), ['phone']);
    assert.deepEqual(iconGroupsOf(worded, 'o', ':n khớp')[0].icons.map((ic) => ic.name), ['factory', 'phone', 'truck']);
});

test('the icon suggested for an item name is the longest run of its whole words in a search phrase, accents ignored', () => {
    assert.equal(suggestIconOf(worded, 'Nhà máy Bắc Ninh'), 'factory');
    assert.equal(suggestIconOf(worded, 'GIAO HANG nhanh'), 'truck');
    assert.equal(suggestIconOf(worded, 'Gọi hotline ngay'), 'phone');
    assert.equal(suggestIconOf(worded, 'Giao hàng và xưởng'), 'truck');
    assert.equal(suggestIconOf(worded, 'Xưởng'), 'factory');
    assert.equal(suggestIconOf(worded, 'In ấn'), '');
    assert.equal(suggestIconOf(worded, 'Liên hệ'), '');
    assert.equal(suggestIconOf(worded, '  '), '');
    assert.equal(suggestIconOf(groups, 'Điện thoại'), '');
});

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

test('the suggestion reads the real Vietnamese search words as whole words, not inside other words', () => {
    const vi = JSON.parse(readFileSync(new URL('../../lang/vi.json', import.meta.url), 'utf8'));
    const icons = Object.entries(vi)
        .filter(([key]) => key.startsWith('menus.icon_kw_'))
        .map(([key, words]) => ({ name: key.slice('menus.icon_kw_'.length), label: '', words }));
    const real = [{ group: 'all', icons }];
    const expected = {
        'Trang chủ': 'home', 'Giới thiệu': 'info', 'Đối tác': 'handshake', 'Chính sách bảo mật': 'privacy',
        'Cửa hàng': 'shopping-bag', 'Giỏ hàng': 'cart', 'Dự án': 'project', 'Tuyển dụng': 'briefcase', 'Tin tức': 'newspaper',
    };
    for (const [label, icon] of Object.entries(expected)) {
        assert.equal(suggestIconOf(real, label), icon, label);
    }
});

// The real keyword lists of every language, one text per language, in picker order: what the screen is sent.
const pickerGroups = () => {
    const root = new URL('../../', import.meta.url);
    // Every language file, as the PHP guesser reads them, so a new language is covered here too.
    const codes = readdirSync(new URL('lang/', root)).filter((f) => f.endsWith('.json')).map((f) => f.slice(0, -5)).sort();
    const langs = codes.map((l) => JSON.parse(readFileSync(new URL(`lang/${l}.json`, root), 'utf8')));
    const vi = langs[codes.indexOf('vi')];
    const src = readFileSync(new URL('app/Modules/Theme/Chrome/StorefrontIcons.php', root), 'utf8');
    const block = src.slice(src.indexOf('MENU_GROUPS = ['));
    const groups = [...block.slice(0, block.indexOf('];')).matchAll(/'[a-z]+' => \[([^\]]+)\]/g)];
    return groups.map((m) => ({
        group: 'g',
        icons: [...m[1].matchAll(/'([^']+)'/g)].map((n) => ({
            name: n[1], label: n[1], words: vi['menus.icon_kw_' + n[1]] || '',
            kw: langs.map((l) => l['menus.icon_kw_' + n[1]]).filter(Boolean),
        })),
    }));
};
const KINDS = { home: 'home', search: 'search', cart: 'cart', account: 'user', login: 'user', call: 'phone', zalo: 'zalo', mail: 'email', map: 'map', sitemap: 'sitemap' };

test('the icon the phone menu picks by itself follows the link kind first, then the words of the name', () => {
    assert.equal(autoIconOf(worded, KINDS, { type: 'special', key: 'cart' }, 'Nhà máy'), 'cart');
    assert.equal(autoIconOf(worded, KINDS, { type: 'special', key: 'account' }, 'Nhà máy'), 'user');
    assert.equal(autoIconOf(worded, KINDS, { type: 'special', key: 'group' }, 'x'), '');
    assert.equal(autoIconOf(worded, KINDS, { type: 'url', url: 'https://x.test' }, 'In ấn'), '');
    assert.equal(autoIconOf(worded, KINDS, null, ''), '');
});

test('the automatic icon is strict and answers as the phone does (same cases as MenuIconGuessTest)', () => {
    const cases = JSON.parse(readFileSync(new URL('../fixtures/menu-auto-icon-cases.json', import.meta.url), 'utf8'));
    const groups = pickerGroups();
    for (const label of cases.none) {
        assert.equal(autoIconOf(groups, KINDS, { type: 'url' }, label), '', label);
    }
    for (const [label, icon] of Object.entries(cases.icon)) {
        assert.equal(autoIconOf(groups, KINDS, { type: 'url' }, label), icon, label);
    }
    assert.equal(autoIconOf(groups, KINDS, { type: 'special', key: 'map' }, 'Chi nhánh'), 'map');
});

test('the description of a top-level item may run to 200 letters before the screen warns, one below it to 90', () => {
    assert.equal(descMaxOf(0), 200);
    assert.equal(descMaxOf(1), 90);
    assert.equal(descMaxOf(3), 90);
    assert.equal(descMaxOf(undefined), 90);
});
