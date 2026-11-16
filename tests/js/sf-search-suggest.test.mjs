// The search suggestions script (resources/js/sf-search-suggest.js): the wait after typing, the input
// method's composing, the stale answers, the answers the page already has, the combobox keys and the
// rows built from plain text; and the storefront script fetching it as soon as the visitor reaches for
// a search box. In a DOM without a browser: `node --test tests/js/sf-search-suggest.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;
const texts = { label: 'Gợi ý', one: '1 gợi ý', count: ':count gợi ý', loading: 'Đang tìm “:q”…', all: 'Xem tất cả kết quả cho “:q”', all_short: 'Xem tất cả kết quả', all_n: 'Xem tất cả :count kết quả cho “:q”', all_n_one: 'Xem 1 kết quả cho “:q”', all_n_short: 'Xem tất cả :count kết quả', all_n_short_one: 'Xem 1 kết quả', none_title: 'Chưa có gợi ý cho “:q”', none_hint: 'Bấm :go để xem trang kết quả đầy đủ, hoặc mở một nhóm:', none_hint_solo: 'Bấm :go để xem trang kết quả đầy đủ.', go: 'Tìm', select: 'chọn', open: 'mở', close: 'đóng' };
const rows = [
    { kind: 'group', label: 'Jig & Đồ gá', parts: [['Jig & ', false], ['Đồ gá', true]], group: 'Nhóm', group_key: 'nhom', icon: 'folder', url: '/jig-do-ga', thumb: null, meta: 'Sản phẩm' },
    { kind: 'item', label: 'Đồ gá hàn', parts: [['Đồ gá', true], [' hàn', false]], group: 'Sản phẩm', group_key: 'sp', icon: 'box', url: '/do-ga-han', thumb: 'https://files.test/site/do-ga-han-0123456789ab.jpg?w=96', meta: 'Mã AV-DG-01' },
    { kind: 'item', label: '<img src=x onerror=alert(1)>', parts: [['<img src=x onerror=alert(1)>', false]], group: 'Tin tức', group_key: 'c:tin-tuc', icon: 'doc', url: '/tin', thumb: 'javascript:alert(1)', meta: '12/10/2026' },
    { kind: 'item', label: 'Elsewhere', group: 'Khác', icon: 'doc', url: 'https://evil.test/' },
];

const tick = (ms = 0) => new Promise((r) => setTimeout(r, ms));

const echo = (url) => ({ q: url, items: [{ kind: 'item', label: decodeURIComponent(url.split('q=')[1]), group: 'Khác', url: '/x' }], all_url: '/tim-kiem' });

async function page({ answer = () => ({ q: 'do ga', items: rows, all_url: '/tim-kiem?q=do+ga' }), status = 200, lang = 'vi', wait = 20, styled = Promise.resolve() } = {}) {
    const dom = new JSDOM(`<!doctype html><html lang="${lang}"><body><header><div class="sf-spanel__box"><form action="/tim-kiem" data-suggest="/tim-kiem/goi-y" data-suggest-text='${JSON.stringify(texts)}'><input name="q" type="search"><button>Tìm</button></form><p data-search-quick>Tìm nhanh</p><ul data-search-quick><li><a href="/san-pham">Sản phẩm</a></li></ul></div></header></body>`, { url: 'https://a.test/' });
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
    // each group under its heading, named by the group it holds; never "Nhóm: …" in the row
    const groups = [...p.list.querySelectorAll(':scope > li[role="presentation"]')];
    assert.deepEqual(groups.map((g) => g.querySelector('.sf-ssug__gh').textContent), ['Nhóm', 'Sản phẩm', 'Tin tức']);
    groups.forEach((g) => {
        const ul = g.querySelector('ul[role="group"]');
        assert.equal(ul.getAttribute('aria-labelledby'), g.querySelector('.sf-ssug__gh').id);
    });
    assert.equal(options[0].querySelector('.sf-ssug__t').textContent, 'Jig & Đồ gá');
    // a picture from an http(s) address, no text for a screen reader; none (or another scheme): the tile
    const img = options[1].querySelector('img.sf-ssug__th');
    assert.equal(img.getAttribute('src'), 'https://files.test/site/do-ga-han-0123456789ab.jpg?w=96');
    assert.equal(img.getAttribute('alt'), '');
    assert.equal(options[0].querySelector('img'), null);
    assert.equal(options[0].querySelector('.sf-ssug__tile svg') !== null, true);
    assert.equal(options[2].querySelector('img'), null);
    // the meta line: the code, the day
    assert.deepEqual(options.map((o) => o.querySelector('.sf-ssug__m')?.textContent), ['Sản phẩm', 'Mã AV-DG-01', '12/10/2026']);
    // text that looks like HTML stays text
    assert.equal(p.doc.querySelector('img[src="x"]'), null);
    assert.equal(options[2].querySelector('.sf-ssug__t').firstChild.textContent, '<img src=x onerror=alert(1)>');
    assert.equal(p.status.textContent, '3 gợi ý');
    assert.equal(p.box.querySelector('.sf-ssug__all').getAttribute('href'), 'https://a.test/tim-kiem?q=do+ga');
    // no count in the answer: the link without a number
    assert.equal(p.box.querySelector('.sf-ssug__long').textContent, 'Xem tất cả kết quả cho “do ga”');
    // the header panel's quick links give way to the list
    assert.equal(p.doc.querySelector('p[data-search-quick]').hidden, true);
    assert.equal(p.doc.querySelector('ul[data-search-quick]').hidden, true);
});

test('the “all” link says how many: the number, «50+» past 50, «N+» when the count stopped early, one result in the singular', async () => {
    for (const [total, more, long, short] of [
        [22, false, 'Xem tất cả 22 kết quả cho “do ga”', 'Xem tất cả 22 kết quả'],
        [51, false, 'Xem tất cả 50+ kết quả cho “do ga”', 'Xem tất cả 50+ kết quả'],
        [30, true, 'Xem tất cả 30+ kết quả cho “do ga”', 'Xem tất cả 30+ kết quả'],
        [1, false, 'Xem 1 kết quả cho “do ga”', 'Xem 1 kết quả'],
    ]) {
        const p = await page({ answer: () => ({ q: 'do ga', items: rows, all_url: '/tim-kiem?q=do+ga', total, total_more: more }) });
        p.type('do ga');
        await tick(60);
        assert.equal(p.box.querySelector('.sf-ssug__long').textContent, long);
        assert.equal(p.box.querySelector('.sf-ssug__short').textContent, short);
    }
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

test('a keyboard ending its composition on the words already drawn keeps the rows, so a tap on one still opens it', async () => {
    const p = await page();
    p.input.dispatchEvent(new p.dom.window.CompositionEvent('compositionstart'));
    p.type('do ga');
    await tick(60);
    const row = p.list.querySelectorAll('[role="option"]')[0];
    row.querySelector('b').dispatchEvent(new p.dom.window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
    p.input.dispatchEvent(new p.dom.window.CompositionEvent('compositionend'));
    await tick(20);
    assert.equal(row.isConnected, true);
    row.querySelector('b').dispatchEvent(new p.dom.window.MouseEvent('click', { bubbles: true }));
    assert.deepEqual(p.went, ['https://a.test/jig-do-ga']);
    assert.equal(p.asked.length, 1);
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

test('no row, or a refused answer: a sentence saying so and how to go on, the panel’s quick links again, no footer', async () => {
    for (const p of [await page({ answer: () => ({ q: 'zz', items: [], all_url: '/tim-kiem?q=zz' }) }), await page({ status: 429 })]) {
        p.type('zz');
        await tick(60);
        assert.equal(p.list.querySelectorAll('[role="option"]').length, 0);
        const none = p.box.querySelector('.sf-ssug__nr');
        assert.equal(none.hidden, false);
        assert.equal(none.querySelector('.sf-ssug__nrt').textContent, 'Chưa có gợi ý cho “zz”');
        assert.equal(none.querySelector('.sf-ssug__nrd').textContent, 'Bấm Tìm để xem trang kết quả đầy đủ, hoặc mở một nhóm:');
        assert.equal(none.querySelector('.sf-ssug__nrd b').textContent, 'Tìm');
        // the group links come back under the sentence, their label does not
        assert.equal(p.doc.querySelector('ul[data-search-quick]').hidden, false);
        assert.equal(p.doc.querySelector('p[data-search-quick]').hidden, true);
        assert.equal(p.box.querySelector('.sf-ssug__foot').hidden, true);
        assert.equal(p.status.textContent, 'Chưa có gợi ý cho “zz”');
        assert.equal(p.input.getAttribute('aria-expanded'), 'false');
        p.key('ArrowDown');
        assert.equal(p.input.hasAttribute('aria-activedescendant'), false);
        assert.equal(p.key('Enter').defaultPrevented, false);
        // Tab to a group link: the sentence stays above it
        p.doc.querySelector('ul[data-search-quick] a').focus();
        await tick(5);
        assert.equal(p.box.hidden, false);
        assert.equal(none.hidden, false);
        p.input.focus();
        // typing on: the sentence gives way to the loading line
        p.type('zzz');
        await tick(22);
        assert.equal(none.hidden, true);
    }
});

test('no row on the search page (no quick links): the sentence says only to press Tìm', async () => {
    const p = await page({ answer: () => ({ q: 'zz', items: [], all_url: '/tim-kiem?q=zz' }) });
    // a second box away from the header panel
    const form = p.doc.createElement('form');
    form.action = '/tim-kiem';
    form.dataset.suggest = '/tim-kiem/goi-y';
    form.dataset.suggestText = JSON.stringify(texts);
    form.innerHTML = '<input name="q">';
    p.doc.body.append(form);
    const mod = await import(`../../resources/js/sf-search-suggest.js?run=${++load}`);
    delete form.dataset.suggestOn;
    p.doc.body.querySelectorAll(':scope > .sf-ssug, :scope > [role="status"]').forEach((n) => n.remove());
    const parts = mod.attach(form, { wait: 5, slow: 30, fetch: async () => ({ ok: true, json: async () => ({ q: 'zz', items: [] }) }) });
    parts.input.focus();
    parts.input.value = 'zz';
    parts.input.dispatchEvent(new p.dom.window.Event('input', { bubbles: true }));
    await tick(40);
    assert.equal(parts.box.classList.contains('sf-ssug--float'), true);
    assert.equal(parts.box.querySelector('.sf-ssug__nrd').textContent, 'Bấm Tìm để xem trang kết quả đầy đủ.');
});

test('loading: the bar and “Đang tìm “…”…” at once, the grey rows only past the wait, the screen reader told', async () => {
    const p = await page();
    p.type('do');
    await tick(22);
    assert.equal(p.box.hidden, false);
    assert.equal(p.box.querySelector('.sf-ssug__load').hidden, false);
    // the words are text, not the bar's motion: they stay when motion is reduced
    assert.equal(p.box.querySelector('.sf-ssug__lt').textContent, 'Đang tìm “do”…');
    assert.equal(p.box.querySelector('.sf-ssug__sk').hidden, false);   // nothing drawn yet: grey rows
    assert.equal(p.box.querySelectorAll('.sf-ssug__skrow .sf-ssug__th').length, 4);
    assert.equal(p.input.getAttribute('aria-expanded'), 'false');
    assert.equal(p.status.textContent, 'Đang tìm “do”…');
    await tick(40);
    assert.equal(p.box.querySelector('.sf-ssug__load').hidden, true);
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
    assert.equal(p.box.querySelector('.sf-ssug__load').hidden, false);
    other.focus();
    await tick(40);
    assert.equal(p.box.hidden, true);
    assert.equal(p.box.querySelector('.sf-ssug__load').hidden, true);
    assert.equal(p.box.querySelector('.sf-ssug__sk').hidden, true);
    p.input.focus();
    p.key('ArrowDown');
    assert.equal(p.box.querySelector('.sf-ssug__load').hidden, true);
    assert.equal(p.box.querySelector('.sf-ssug__sk').hidden, true);

    // the focus on the form's own button: the answer is not drawn, and the bar stops too
    p.type('do ga');
    await tick(22);
    p.doc.querySelector('form button').focus();
    await tick(40);
    assert.equal(p.box.querySelector('.sf-ssug__load').hidden, true);
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
    assert.equal(p.box.querySelector('.sf-ssug__load').hidden, true);
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

test('on a phone the header’s panel covers the screen: Back closes it, Tab stays inside, the slideshows are told', async () => {
    const dom = new JSDOM(`<!doctype html><html lang="vi"><body><header><a id="open" href="/tim-kiem" data-search-open aria-controls="sf-search-panel">Tìm</a>
        <div id="sf-search-panel" role="dialog" data-search-panel hidden><div class="sf-spanel__box"><form action="/tim-kiem"><input name="q" data-search-input><button id="go">Tìm</button><button type="button" id="x" data-search-close>✕</button></form>
        <ul data-search-quick><li><a id="chip" href="/san-pham">Sản phẩm</a></li><li hidden><a id="gone" href="/an">Ẩn</a></li></ul></div></div></header><main><a id="behind" href="/">Trang</a></main></body></html>`, { url: 'https://a.test/', pretendToBeVisual: true });
    const w = dom.window;
    w.matchMedia = (q) => ({ matches: q.includes('639.98'), addEventListener() {}, removeEventListener() {} });
    w.requestIdleCallback = () => {};
    // no layout in this DOM: what [hidden] covers has no box
    w.HTMLElement.prototype.getClientRects = function () {
        return this.closest('[hidden]') ? [] : [{}];
    };
    for (const name of ['window', 'document', 'location', 'navigator', 'CustomEvent', 'matchMedia', 'localStorage', 'getSelection', 'Element']) {
        Object.defineProperty(globalThis, name, { value: name === 'window' ? w : w[name], configurable: true, writable: true });
    }
    await import(`../../resources/js/storefront.js?run=${++load}`);
    const d = w.document;
    const panel = d.getElementById('sf-search-panel');
    let covers = 0;
    d.addEventListener('sf:cover', () => covers++);
    const before = w.history.length;
    d.getElementById('open').click();
    assert.equal(panel.hidden, false);
    assert.equal(panel.hasAttribute('aria-modal'), true);
    assert.equal(panel.hasAttribute('data-search-sheet'), true);
    assert.equal(d.documentElement.classList.contains('sf-search-open'), true);
    assert.equal(covers, 1);
    assert.equal(w.history.length, before + 1);
    assert.equal(d.activeElement, d.querySelector('[data-search-input]'));

    // Tab from the last control comes back to the field, Shift+Tab from the field goes to the last one
    const tab = (shiftKey = false) => {
        const event = new w.KeyboardEvent('keydown', { key: 'Tab', shiftKey, bubbles: true, cancelable: true });
        d.activeElement.dispatchEvent(event);
        return event;
    };
    d.getElementById('chip').focus();
    assert.equal(tab().defaultPrevented, true);
    assert.equal(d.activeElement, d.querySelector('[data-search-input]'));
    tab(true);
    assert.equal(d.activeElement, d.getElementById('chip'));
    // inside, Tab is the browser's own
    d.getElementById('go').focus();
    assert.equal(tab().defaultPrevented, false);

    // the phone's Back closes it; focus goes back to the magnifier
    w.history.back();
    await tick(30);
    assert.equal(panel.hidden, true);
    assert.equal(d.documentElement.classList.contains('sf-search-open'), false);
    assert.equal(covers, 2);
    assert.equal(d.activeElement, d.getElementById('open'));

    // the ✕ closes it and takes back the history entry it added
    d.getElementById('open').click();
    assert.equal(w.history.state?.sfSearch, 1);
    d.getElementById('x').click();
    await tick(30);
    assert.equal(panel.hidden, true);
    assert.equal(w.history.state, null);
    assert.equal(panel.hasAttribute('aria-modal'), false);
});

test('on a computer the panel stays a drop-down: no history entry, no trap, still tells the slideshows', async () => {
    const dom = new JSDOM(`<!doctype html><html lang="vi"><body><header><a id="open" href="/tim-kiem" data-search-open>Tìm</a>
        <div id="sf-search-panel" data-search-panel hidden><div class="sf-spanel__box"><form action="/tim-kiem"><input name="q" data-search-input></form></div></div></header></body></html>`, { url: 'https://a.test/', pretendToBeVisual: true });
    const w = dom.window;
    w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    w.requestIdleCallback = () => {};
    for (const name of ['window', 'document', 'location', 'navigator', 'CustomEvent', 'matchMedia', 'localStorage', 'getSelection', 'Element']) {
        Object.defineProperty(globalThis, name, { value: name === 'window' ? w : w[name], configurable: true, writable: true });
    }
    await import(`../../resources/js/storefront.js?run=${++load}`);
    const d = w.document;
    const before = w.history.length;
    d.getElementById('open').click();
    const panel = d.getElementById('sf-search-panel');
    assert.equal(panel.hidden, false);
    assert.equal(panel.hasAttribute('aria-modal'), false);
    assert.equal(w.history.length, before);
    assert.equal(d.documentElement.classList.contains('sf-search-open'), true);
    d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(panel.hidden, true);
    assert.equal(d.documentElement.classList.contains('sf-search-open'), false);
});
