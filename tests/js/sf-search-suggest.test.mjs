// The search suggestions script (resources/js/sf-search-suggest.js): the wait after typing, the input
// method's composing, the stale answers, the combobox keys and the rows built from plain text, in a DOM
// without a browser: `node --test tests/js/sf-search-suggest.test.mjs`.
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

async function page({ answer = () => ({ q: 'do ga', items: rows, all_url: '/tim-kiem?q=do+ga' }), status = 200 } = {}) {
    const dom = new JSDOM(`<!doctype html><body><header><div class="sf-spanel__box"><form action="/tim-kiem" data-suggest="/tim-kiem/goi-y" data-suggest-text='${JSON.stringify(texts)}'><input name="q" type="search"><button>Tìm</button></form><p data-search-quick>Tìm nhanh</p></div></header></body>`, { url: 'https://a.test/' });
    const doc = dom.window.document;
    globalThis.document = dom.window.document;
    const asked = [];
    const went = [];
    const mod = await import(`../../resources/js/sf-search-suggest.js?run=${++load}`);
    const form = doc.querySelector('form');
    delete form.dataset.suggestOn;
    form.parentNode.querySelectorAll('.sf-ssug, [role="status"]').forEach((n) => n.remove());
    const parts = mod.attach(form, {
        wait: 20,
        slow: 30,
        styled: Promise.resolve(),
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
    return { dom, doc, ...parts, asked, went, type, key };
}

test('asks once, 250 ms (here 20) after typing stops, from two characters', async () => {
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

test('waits while an input method composes, and asks once it is done', async () => {
    const p = await page();
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

test('an input method starting to compose drops the answer already on its way', async () => {
    const p = await page();
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
