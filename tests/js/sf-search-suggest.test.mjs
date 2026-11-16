// The search suggestions script (resources/js/sf-search-suggest.js): the wait after typing, the input
// method's composing, the stale answers, the answers the page already has, the combobox keys and the
// rows built from plain text; and the storefront script fetching it as soon as the visitor reaches for
// a search box. In a DOM without a browser: `node --test tests/js/sf-search-suggest.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;
const texts = { label: 'Gợi ý', one: '1 gợi ý', count: ':count gợi ý', none: 'Không có gợi ý', enter: 'Nhấn Enter để xem mọi kết quả', loading: 'Đang tìm…', all: 'Xem tất cả kết quả cho “:q”', all_short: 'Xem tất cả kết quả', select: 'chọn', open: 'mở', close: 'đóng' };
const rows = [
    { kind: 'group', label: 'Nhóm: Jig & Đồ gá', parts: [['Nhóm: Jig & ', false], ['Đồ gá', true]], group: 'Nhóm sản phẩm', icon: 'folder', url: '/jig-do-ga' },
    { kind: 'item', label: 'Đồ gá hàn', parts: [['Đồ gá', true], [' hàn', false]], group: 'Sản phẩm', icon: 'box', url: '/do-ga-han' },
    { kind: 'item', label: '<img src=x onerror=alert(1)>', parts: [['<img src=x onerror=alert(1)>', false]], group: 'Tin tức', icon: 'doc', url: '/tin' },
    { kind: 'item', label: 'Elsewhere', group: 'Khác', icon: 'doc', url: 'https://evil.test/' },
];

const tick = (ms = 0) => new Promise((r) => setTimeout(r, ms));

const echo = (url) => ({ q: url, items: [{ kind: 'item', label: decodeURIComponent(url.split('q=')[1]), group: 'Khác', url: '/x' }], all_url: '/tim-kiem' });

async function page({ answer = () => ({ q: 'do ga', items: rows, all_url: '/tim-kiem?q=do+ga' }), status = 200, lang = 'vi', wait = 20, styled = Promise.resolve() } = {}) {
    const dom = new JSDOM(`<!doctype html><html lang="${lang}"><body><header><div class="sf-spanel__box"><form action="/tim-kiem" data-suggest="/tim-kiem/goi-y" data-suggest-text='${JSON.stringify(texts)}'><input name="q" type="search"><button>Tìm</button></form><p data-search-quick>Tìm nhanh</p></div></header></body>`, { url: 'https://a.test/' });
    const doc = dom.window.document;
    globalThis.document = dom.window.document;
    const asked = [];
    const went = [];
    const mod = await import(`../../resources/js/sf-search-suggest.js?run=${++load}`);
    const form = doc.querySelector('form');
    delete form.dataset.suggestOn;
    form.parentNode.querySelectorAll('.sf-ssug, [role="status"]').forEach((n) => n.remove());
    const parts = mod.attach(form, {
        wait,
        slow: 30,
        styled,
        go: (url) => went.push(url),
        fetch: (url, init) => {
            asked.push(url);
            return new Promise((resolve, reject) => {
                init.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
                setTimeout(() => resolve({ ok: status === 200, json: async () => (status === 200 ? answer(url) : {}) }), 5);
            });
        },
    });
    parts.input.focus();
    const type = (value) => {
        parts.input.value = value;
        parts.input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
    };
    const key = (name) => {
        const event = new dom.window.KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true });
        parts.input.dispatchEvent(event);
        return event;
    };
    const labels = () => [...parts.list.querySelectorAll('[role="option"]')].map((o) => o.querySelector('.sf-ssug__t').firstChild.textContent);
    return { dom, doc, ...parts, asked, went, type, key, labels };
}

test('asks once, 130 ms (here 20) after typing stops, from two characters', async () => {
    const p = await page();
    p.type('d');
    await tick(40);
    assert.equal(p.asked.length, 0);
    p.type('do');
    p.type('do ');
    p.type('do g');
    await tick(10);
    assert.equal(p.asked.length, 0);
    await tick(40);
    assert.deepEqual(p.asked, ['/tim-kiem/goi-y?q=do%20g']);
});

test('waits 130 ms after typing stops unless told otherwise', async () => {
    const p = await page({ wait: null });
    p.type('do');
    await tick(100);
    assert.equal(p.asked.length, 0);
    await tick(60);
    assert.deepEqual(p.asked, ['/tim-kiem/goi-y?q=do']);
});

test('on a page written with letters, a keyboard composing the word still asks (a phone composes every word), once', async () => {
    const p = await page({ answer: echo });
    p.input.dispatchEvent(new p.dom.window.CompositionEvent('compositionstart'));
    p.type('đô');
    await tick(40);
    assert.deepEqual(p.asked, ['/tim-kiem/goi-y?q=%C4%91%C3%B4']);
    assert.deepEqual(p.labels(), ['đô']);
    p.input.dispatchEvent(new p.dom.window.CompositionEvent('compositionend'));
    await tick(40);
    assert.equal(p.asked.length, 1);
    assert.deepEqual(p.labels(), ['đô']);
});

test('on a Japanese, Chinese or Korean page, waits while the input method composes, and asks once it is done', async () => {
    const p = await page({ lang: 'ja' });
    p.input.dispatchEvent(new p.dom.window.CompositionEvent('compositionstart'));
    p.type('đô');
    await tick(40);
    assert.equal(p.asked.length, 0);
    p.input.dispatchEvent(new p.dom.window.CompositionEvent('compositionend'));
    await tick(40);
    assert.deepEqual(p.asked, ['/tim-kiem/goi-y?q=%C4%91%C3%B4']);
});

test('draws the rows from plain text, only addresses of the site, with a combobox wiring', async () => {
    const p = await page();
    p.type('do ga');
    await tick(60);
    const options = [...p.list.querySelectorAll('[role="option"]')];
    assert.equal(options.length, 3);
    assert.equal(p.box.hidden, false);
    assert.equal(p.input.getAttribute('role'), 'combobox');
    assert.equal(p.input.getAttribute('aria-controls'), p.list.id);
    assert.equal(p.input.getAttribute('aria-expanded'), 'true');
    assert.equal(p.list.getAttribute('role'), 'listbox');
    assert.equal(options[0].querySelector('b').textContent, 'Đồ gá');
    assert.equal(options[0].querySelector('.sf-ssug__g').textContent, 'Nhóm sản phẩm');
    // text that looks like HTML stays text
    assert.equal(p.doc.querySelector('img'), null);
    assert.equal(options[2].querySelector('.sf-ssug__t').firstChild.textContent, '<img src=x onerror=alert(1)>');
    assert.equal(p.status.textContent, '3 gợi ý');
    assert.equal(p.box.querySelector('.sf-ssug__all').getAttribute('href'), 'https://a.test/tim-kiem?q=do+ga');
    assert.equal(p.box.querySelector('.sf-ssug__long').textContent, 'Xem tất cả kết quả cho “do ga”');
    // the header panel's quick links give way to the list
    assert.equal(p.doc.querySelector('[data-search-quick]').hidden, true);
});

test('↓ ↓ Enter opens the second row, ↑ wraps, Esc closes the list and keeps the Esc from the panel', async () => {
    const p = await page();
    p.type('do ga');
    await tick(60);
    p.key('ArrowDown');
    p.key('ArrowDown');
    const options = [...p.list.querySelectorAll('[role="option"]')];
    assert.equal(p.input.getAttribute('aria-activedescendant'), options[1].id);
    assert.equal(options[1].getAttribute('aria-selected'), 'true');
    assert.equal(options[0].getAttribute('aria-selected'), 'false');
    assert.equal(p.key('Enter').defaultPrevented, true);
    assert.deepEqual(p.went, ['https://a.test/do-ga-han']);
    p.key('ArrowUp');
    p.key('ArrowUp');
    assert.equal(p.input.getAttribute('aria-activedescendant'), options[2].id);
    let reached = false;
    p.doc.addEventListener('keydown', () => (reached = true));
    p.key('Escape');
    assert.equal(reached, false);
    assert.equal(p.box.hidden, true);
    assert.equal(p.input.getAttribute('aria-expanded'), 'false');
    assert.equal(p.input.hasAttribute('aria-activedescendant'), false);
    // with the list closed, Esc goes on to the panel; Enter with no row chosen submits the form
    p.key('Escape');
    assert.equal(reached, true);
    assert.equal(p.key('Enter').defaultPrevented, false);
    assert.equal(p.doc.activeElement, p.input);
});

test('a click opens its row without taking the focus from the field', async () => {
    const p = await page();
    p.type('do ga');
    await tick(60);
    const row = p.list.querySelectorAll('[role="option"]')[0];
    const down = new p.dom.window.MouseEvent('mousedown', { bubbles: true, cancelable: true });
    row.querySelector('b').dispatchEvent(down);
    assert.equal(down.defaultPrevented, true);
    row.querySelector('b').dispatchEvent(new p.dom.window.MouseEvent('click', { bubbles: true }));
    assert.deepEqual(p.went, ['https://a.test/jig-do-ga']);
});

test('only the answer to the latest words is drawn', async () => {
    const p = await page({ answer: (url) => ({ q: url, items: [{ kind: 'item', label: decodeURIComponent(url.split('q=')[1]), group: 'Khác', url: '/x' }], all_url: '/tim-kiem' }) });
    p.type('kh');
    await tick(22);   // asked, the answer still on its way
    p.type('khuon');
    await tick(60);
    assert.equal(p.asked.length, 2);
    assert.deepEqual([...p.list.querySelectorAll('[role="option"]')].map((o) => o.textContent.startsWith('khuon')), [true]);
});

test('no row, or a refused answer: one row saying Enter shows every result, no footer', async () => {
    for (const p of [await page({ answer: () => ({ q: 'zz', items: [], all_url: '/tim-kiem?q=zz' }) }), await page({ status: 429 })]) {
        p.type('zz');
        await tick(60);
        const only = p.list.querySelectorAll('[role="option"]');
        assert.equal(only.length, 1);
        assert.equal(only[0].getAttribute('aria-disabled'), 'true');
        assert.equal(only[0].textContent, 'Nhấn Enter để xem mọi kết quả');
        assert.equal(p.box.querySelector('.sf-ssug__foot').hidden, true);
        assert.equal(p.status.textContent, 'Không có gợi ý');
        p.key('ArrowDown');
        assert.equal(p.input.hasAttribute('aria-activedescendant'), false);
        assert.equal(p.key('Enter').defaultPrevented, false);
    }
});

test('loading: the bar at once, the grey rows only past the wait, the screen reader told', async () => {
    const p = await page();
    p.type('do');
    await tick(22);
    assert.equal(p.box.hidden, false);
    assert.equal(p.box.querySelector('.sf-ssug__bar').hidden, false);
    assert.equal(p.box.querySelector('.sf-ssug__sk').hidden, false);   // nothing drawn yet: grey rows
    assert.equal(p.input.getAttribute('aria-expanded'), 'false');
    assert.equal(p.status.textContent, 'Đang tìm…');
    await tick(40);
    assert.equal(p.box.querySelector('.sf-ssug__bar').hidden, true);
    assert.equal(p.box.querySelector('.sf-ssug__sk').hidden, true);
});

test('a row or the “all” link whose address leaves the site is never drawn, even with a tab or a line break hidden in it', async () => {
    const sneaky = ['/\t/evil.test/', '/\n/evil.test/', '/\r\n/evil.test/x', '/\t\\evil.test/', ' //evil.test/', 'javascript:alert(1)'];
    const p = await page({ answer: () => ({ q: 'do ga', items: [...sneaky.map((url) => ({ kind: 'item', label: 'Lạ', group: 'Khác', url })), rows[1]], all_url: '/\t/evil.test/all' }) });
    p.type('do ga');
    await tick(60);
    const options = [...p.list.querySelectorAll('[role="option"]')];
    assert.deepEqual(options.map((o) => o.dataset.url), ['https://a.test/do-ga-han']);
    // the link falls back to the search page of this site
    assert.equal(new URL(p.box.querySelector('.sf-ssug__all').href).origin, 'https://a.test');
    p.key('ArrowDown');
    p.key('Enter');
    assert.deepEqual(p.went, ['https://a.test/do-ga-han']);
});

test('leaving the field while an answer is on its way stops the loading bar; coming back shows no running bar', async () => {
    const p = await page();
    const other = p.doc.body.appendChild(p.doc.createElement('button'));
    p.type('do ga');
    await tick(60);
    // asked again, then Tab out of the box before the answer
    p.type('do gal');
    await tick(22);
    assert.equal(p.box.querySelector('.sf-ssug__bar').hidden, false);
    other.focus();
    await tick(40);
    assert.equal(p.box.hidden, true);
    assert.equal(p.box.querySelector('.sf-ssug__bar').hidden, true);
    assert.equal(p.box.querySelector('.sf-ssug__sk').hidden, true);
    p.input.focus();
    p.key('ArrowDown');
    assert.equal(p.box.querySelector('.sf-ssug__bar').hidden, true);
    assert.equal(p.box.querySelector('.sf-ssug__sk').hidden, true);

    // the focus on the form's own button: the answer is not drawn, and the bar stops too
    p.type('do ga');
    await tick(22);
    p.doc.querySelector('form button').focus();
    await tick(40);
    assert.equal(p.box.querySelector('.sf-ssug__bar').hidden, true);
    assert.equal(p.box.querySelector('.sf-ssug__sk').hidden, true);
});

test('a space after the last word keeps the request on its way: asked once', async () => {
    const p = await page({ answer: echo });
    p.type('do');
    await tick(22);   // asked, the answer still on its way
    p.type('do ');
    await tick(40);
    assert.deepEqual(p.asked, ['/tim-kiem/goi-y?q=do']);
    assert.deepEqual(p.labels(), ['do']);
});

test('words asked before are drawn at once from the answers the page already has, with no loading', async () => {
    const p = await page({ answer: echo });
    p.type('do');
    await tick(60);
    p.type('do g');
    await tick(60);
    assert.equal(p.asked.length, 2);
    p.type('do');
    await tick(2);
    assert.equal(p.asked.length, 2);
    assert.deepEqual(p.labels(), ['do']);
    assert.equal(p.box.querySelector('.sf-ssug__bar').hidden, true);
});

test('a refused answer is not kept: the same words are asked again', async () => {
    const p = await page({ status: 429 });
    p.type('zz');
    await tick(60);
    p.type('z');
    await tick(60);
    p.type('zz');
    await tick(60);
    assert.equal(p.asked.length, 2);
});

test('asks while the stylesheet is still on its way, and draws once it is there', async () => {
    let ready;
    const p = await page({ answer: echo, styled: new Promise((done) => (ready = done)) });
    p.type('do');
    await tick(40);
    assert.deepEqual(p.asked, ['/tim-kiem/goi-y?q=do']);
    assert.equal(p.box.hidden, true);
    ready();
    await tick(2);
    assert.deepEqual(p.labels(), ['do']);
});

test('an input method starting to compose drops the answer already on its way (a Japanese page)', async () => {
    const p = await page({ lang: 'ja' });
    p.type('do');
    await tick(22);   // asked, the answer still on its way
    assert.equal(p.asked.length, 1);
    p.input.dispatchEvent(new p.dom.window.CompositionEvent('compositionstart'));
    await tick(40);
    assert.equal(p.list.querySelectorAll('[role="option"]').length, 0);
    p.type('do gá');
    p.input.dispatchEvent(new p.dom.window.CompositionEvent('compositionend'));
    await tick(60);
    assert.equal(p.asked.length, 2);
    assert.equal(p.list.querySelectorAll('[role="option"]').length, 3);
});

test('the storefront script fetches the suggestions code at the first press on the header’s magnifier, before the panel opens', async () => {
    const chunk = `data:text/javascript,globalThis.sfSuggestWarm=(globalThis.sfSuggestWarm||0)+1;export{}`;
    const dom = new JSDOM(`<!doctype html><html lang="vi"><body><header><a id="open" href="/tim-kiem" data-search-open aria-controls="sf-search-panel">Tìm</a>
        <div id="sf-search-panel" data-search-panel hidden><div class="sf-spanel__box"><form action="/tim-kiem" data-suggest="/tim-kiem/goi-y" data-suggest-js="${chunk}"><input name="q" data-search-input></form></div></div></header>
        <main><form id="page-form" action="/tim-kiem" data-suggest="/tim-kiem/goi-y" data-suggest-js="${chunk}"><input name="q"></form><p id="text">Chữ</p></main></body></html>`, { url: 'https://a.test/', pretendToBeVisual: true });
    const w = dom.window;
    w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    w.requestIdleCallback = () => {};
    for (const name of ['window', 'document', 'location', 'navigator', 'CustomEvent', 'matchMedia', 'localStorage', 'getSelection', 'Element']) {
        Object.defineProperty(globalThis, name, { value: name === 'window' ? w : w[name], configurable: true, writable: true });
    }
    globalThis.sfSuggestWarm = 0;
    await import(`../../resources/js/storefront.js?run=${++load}`);
    const press = (el) => el.dispatchEvent(new w.Event('pointerdown', { bubbles: true }));

    press(w.document.getElementById('text'));
    await tick(20);
    assert.equal(globalThis.sfSuggestWarm, 0, 'a press elsewhere fetches nothing');
    press(w.document.getElementById('open'));
    await tick(20);
    assert.equal(globalThis.sfSuggestWarm, 1);
    assert.equal(w.document.getElementById('sf-search-panel').hidden, true, 'fetched on the press, before the click opens the panel');
    // the same code serves every box: a press on the page's own box asks for the same (already fetched) module
    press(w.document.querySelector('#page-form input'));
    w.document.querySelector('#page-form input').focus();
    await tick(20);
    assert.equal(globalThis.sfSuggestWarm, 1);
});
