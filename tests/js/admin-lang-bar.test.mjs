// The editors' shared language bar (resources/js/admin-lang-bar.js) in a DOM without a browser: the
// search without accents, the panel's keyboard, the focus after another version opens, and which
// pills give way to «+N» when they do not all fit (widths faked: jsdom has no layout).
// `node --test tests/js/admin-lang-bar.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://a.test/admin/content/01H/edit', pretendToBeVisual: true });
globalThis.window = dom.window;
globalThis.document = dom.window.document;
let factory = null;
window.Alpine = { data: (name, fn) => (name === 'bsLangBar' ? (factory = fn) : null) };
const { fold, matches, remember, arrived } = await import('../../resources/js/admin-lang-bar.js');

const frame = () => new Promise((r) => window.requestAnimationFrame(() => r()));
const LANGS = [
    { code: 'vi', label: 'Tiếng Việt' },
    { code: 'en', label: 'Tiếng Anh' },
    { code: 'ja', label: 'Tiếng Nhật' },
    { code: 'zh', label: 'Tiếng Trung' },
];

// A bar of four pills (vi source, en open) whose strip is `room` wide; each pill takes `each`.
function bar({ room = 1000, each = 100, compact = false, focus = 'entry-title' } = {}) {
    document.body.innerHTML = `<input id="entry-title"><div id="root">
        <div id="pills">${LANGS.map((l) => `<a href="/admin/content/01H/edit${l.code === 'vi' ? '' : '?locale=' + l.code}" data-lb-pill="${l.code}" ${l.code === 'vi' || l.code === 'en' ? 'data-keep' : ''}>${l.label}</a>`).join('')}</div>
        <div id="anchor"><button id="sel"></button><button id="more" hidden><span></span></button>
        <div id="panel"><div id="menu">${LANGS.map((l) => `<a role="menuitemradio" href="/x?locale=${l.code}" aria-checked="${l.code === 'en'}" tabindex="${l.code === 'en' ? 0 : -1}">${l.label}</a>`).join('')}</div></div></div></div>`;
    const pills = document.getElementById('pills');
    const shown = () => Array.from(pills.children).filter((p) => !p.hidden).length;
    Object.defineProperty(pills, 'scrollWidth', { get: () => shown() * each });
    Object.defineProperty(pills, 'clientWidth', { get: () => room });
    const root = document.getElementById('root');
    Object.defineProperty(root, 'clientWidth', { get: () => room + 200 });
    const c = factory({ focus, compact, more: 'Thêm __N__ ngôn ngữ', langs: LANGS });
    c.$root = root;
    c.$refs = { pills, more: document.getElementById('more'), sel: document.getElementById('sel'), anchor: document.getElementById('anchor'), panel: document.getElementById('panel'), menu: document.getElementById('menu') };
    c.$nextTick = (fn) => fn();
    c.init();

    return { c, root };
}

test('the search ignores accents and case, and finds a language by its code too', () => {
    assert.equal(fold('Tiếng Đức'), 'tieng duc');
    assert.equal(matches({ code: 'de', label: 'Tiếng Đức' }, 'duc'), true);
    assert.equal(matches({ code: 'de', label: 'Tiếng Đức' }, 'DE'), true);
    assert.equal(matches({ code: 'de', label: 'Tiếng Đức' }, 'nhật'), false);
    assert.equal(matches({ code: 'de', label: 'Tiếng Đức' }, '  '), true);
});

test('every pill shows when they fit; the last ones (never the source nor the open one) give way to «+N}', () => {
    const fits = bar({ room: 400, each: 100 });
    assert.deepEqual(fits.c.cut, []);
    assert.equal(fits.c.over, 0);
    assert.equal(fits.root.hasAttribute('data-over'), false);

    const tight = bar({ room: 250, each: 100 });
    assert.deepEqual(tight.c.cut, ['zh', 'ja']);
    assert.equal(tight.c.over, 2);
    assert.equal(tight.c.moreLabel(), 'Thêm 2 ngôn ngữ');
    assert.equal(tight.root.hasAttribute('data-over'), true, 'the state chip goes first');
    assert.equal(tight.root.hasAttribute('data-squeeze'), false);
    assert.equal(document.getElementById('more').hidden, false);
});

test('when even the source and the open version do not fit, the bar draws the selector instead', () => {
    const { c, root } = bar({ room: 150, each: 100 });
    assert.equal(root.hasAttribute('data-squeeze'), true);
    assert.deepEqual(c.cut, []);
    assert.equal(c.over, 0);
    assert.equal(document.getElementById('more').hidden, true);
});

test('a bar of 700px or less draws the selector, with «+N» beside it counting the other versions', () => {
    const { c, root } = bar({ room: 250, each: 100 });
    // the stylesheet's container query hides the pills on a narrow bar (jsdom has none: set by hand)
    document.getElementById('pills').style.display = 'none';
    c.fit(true);
    assert.equal(c.over, 3);
    assert.deepEqual(c.cut, [], 'no pill is cut: the selector stands for all of them');
    assert.equal(c.moreLabel(), 'Thêm 3 ngôn ngữ');
    assert.equal(document.getElementById('more').hidden, false);
    assert.equal(document.getElementById('more').querySelector('span').textContent, '+3');
    assert.equal(root.hasAttribute('data-squeeze'), false);

    // wide again: back to the pills, «+N» only for the pills that do not fit
    document.getElementById('pills').style.display = '';
    c.fit(true);
    assert.equal(c.over, 2);
    assert.deepEqual(c.cut, ['zh', 'ja']);
});

test('a narrow bar of more than ten languages keeps the selector alone, with no «+N»', () => {
    const { c } = bar({ room: 250, each: 100, compact: true });
    document.getElementById('pills').style.display = 'none';
    c.fit(true);
    assert.equal(c.over, 0);
    assert.equal(document.getElementById('more').hidden, true);
});

test('more than ten languages: nothing is measured, the selector stands alone', () => {
    const { c, root } = bar({ room: 100, each: 100, compact: true });
    assert.deepEqual(c.cut, []);
    assert.equal(root.hasAttribute('data-squeeze'), false);
});

test('the panel opens on the open version, moves with ↑ ↓ Home End, and Escape gives the focus back', () => {
    const { c } = bar();
    const sel = document.getElementById('sel');
    sel.focus();
    c.show(sel);
    const rows = Array.from(document.querySelectorAll('[role=menuitemradio]'));
    assert.equal(c.open, true);
    assert.equal(document.activeElement, rows[1], 'the open version first');
    c.step(1);
    assert.equal(document.activeElement, rows[2]);
    assert.equal(rows[2].tabIndex, 0);
    assert.equal(rows[1].tabIndex, -1, 'only the focused row is in the Tab order');
    c.edge(true);
    assert.equal(document.activeElement, rows[3]);
    c.step(1);
    assert.equal(document.activeElement, rows[0], '↓ on the last row wraps to the first');
    c.step(-1);
    assert.equal(document.activeElement, rows[3]);
    c.edge(false);
    assert.equal(document.activeElement, rows[0]);
    c.close(true);
    assert.equal(c.open, false);
    assert.equal(document.activeElement, sel);
});

test('the focus leaving the selector and the panel closes it; moving inside does not', () => {
    const { c } = bar();
    const sel = document.getElementById('sel');
    c.show(sel);
    const rows = document.querySelectorAll('[role=menuitemradio]');
    c.leave({ relatedTarget: rows[2] });
    assert.equal(c.open, true);
    c.leave({ relatedTarget: document.getElementById('entry-title') });
    assert.equal(c.open, false);
});

test('another version opened from the bar puts the focus in its title, once and only on that page', async () => {
    remember('/admin/content/01H/edit?locale=ja', Date.now());
    assert.equal(arrived('https://a.test/admin/content/01H/edit?locale=en'), false, 'another page: no');
    remember('/admin/content/01H/edit?locale=ja', Date.now() - 60000);
    assert.equal(arrived('https://a.test/admin/content/01H/edit?locale=ja'), false, 'too long ago: no');
    remember('/admin/content/01H/edit?locale=ja', Date.now());
    assert.equal(arrived('https://a.test/admin/content/01H/edit?locale=ja'), true);
    assert.equal(arrived('https://a.test/admin/content/01H/edit?locale=ja'), false, 'read once');

    const { c } = bar();
    const pill = document.querySelector('[data-lb-pill="ja"]');
    c.pick(pill);
    window.history.replaceState(null, '', '/admin/content/01H/edit?locale=ja');
    bar();
    await frame();
    assert.equal(document.activeElement, document.getElementById('entry-title'));
    window.history.replaceState(null, '', '/admin/content/01H/edit');
});
