// The phone's full-screen search panel in the storefront script (resources/js/storefront.js): one history
// entry while it is open, the Back it makes itself on closing (and the pop that answers it), a reopen
// made before that pop, a page restored from the back/forward cache, and a page opened from the sheet
// taking the entry's place. In a DOM without a browser: `node --test tests/js/sf-search-sheet.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;
const tick = (ms = 30) => new Promise((r) => setTimeout(r, ms));

async function start({ phone = true } = {}) {
    const dom = new JSDOM(`<!doctype html><html lang="vi"><body><header><a id="open" href="/tim-kiem" data-search-open aria-controls="sf-search-panel">Tìm</a>
        <div id="sf-search-panel" role="dialog" data-search-panel hidden><div class="sf-spanel__box"><form id="f" action="/tim-kiem"><input name="q" data-search-input><button id="go">Tìm</button><button type="button" id="x" data-search-close>✕</button></form>
        <ul data-search-quick><li><a id="chip" href="/san-pham">Sản phẩm</a></li></ul></div></div></header><main><a id="behind" href="/">Trang</a></main></body></html>`, { url: 'https://a.test/', pretendToBeVisual: true });
    const w = dom.window;
    w.matchMedia = (q) => ({ matches: phone && q.includes('639.98'), addEventListener() {}, removeEventListener() {} });
    w.requestIdleCallback = () => {};
    w.HTMLElement.prototype.getClientRects = function () {
        return this.closest('[hidden]') ? [] : [{}];
    };
    const log = { pushes: 0, backs: 0, replaced: [] };
    const push = w.history.pushState.bind(w.history);
    const back = w.history.back.bind(w.history);
    w.history.pushState = (...args) => (log.pushes++, push(...args));
    w.history.back = () => (log.backs++, back());
    // jsdom cannot navigate: the page's own window, with a location that only remembers where it was sent
    const seen = new Proxy(w, {
        get: (target, key) => (key === 'location' ? { replace: (href) => log.replaced.push(href), assign() {}, href: target.location.href } : typeof target[key] === 'function' ? target[key].bind(target) : target[key]),
    });
    for (const name of ['window', 'document', 'location', 'navigator', 'CustomEvent', 'matchMedia', 'localStorage', 'getSelection', 'Element', 'FormData']) {
        Object.defineProperty(globalThis, name, { value: name === 'window' ? seen : w[name], configurable: true, writable: true });
    }
    await import(`../../resources/js/storefront.js?run=${++load}`);
    const d = w.document;
    return { w, d, log, panel: d.getElementById('sf-search-panel'), open: () => d.getElementById('open').click(), close: () => d.getElementById('x').click(), esc: () => d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })) };
}

const isOpen = (p) => !p.panel.hidden && p.d.documentElement.classList.contains('sf-search-open');

test('on a phone opening pushes exactly one entry, and nothing is pushed again while it stays open', async () => {
    const p = await start();
    p.open();
    assert.equal(p.log.pushes, 1);
    assert.equal(p.w.history.state?.sfSearch, 1);
    p.d.getElementById('go').focus();
    p.d.dispatchEvent(new p.w.KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
    assert.equal(p.log.pushes, 1);
    assert.equal(isOpen(p), true);
});

test('on a computer it never touches the history, closing by Esc or the ✕', async () => {
    const p = await start({ phone: false });
    p.open();
    p.esc();
    p.open();
    p.close();
    await tick();
    assert.deepEqual([p.log.pushes, p.log.backs], [0, 0]);
    assert.equal(p.d.documentElement.classList.contains('sf-search-open'), false);
});

test('Esc and the ✕ each take the entry back with one history.back(), and the class goes', async () => {
    const p = await start();
    p.open();
    p.esc();
    assert.equal(p.log.backs, 1);
    assert.equal(p.panel.hidden, true);
    assert.equal(p.d.documentElement.classList.contains('sf-search-open'), false);
    await tick();
    assert.equal(p.log.backs, 1, 'the pop that answers our own back does not back again');
    assert.equal(p.w.history.state, null);
    p.open();
    p.close();
    await tick();
    assert.equal(p.log.backs, 2);
    assert.equal(p.d.documentElement.classList.contains('sf-search-open'), false);
});

test('the phone’s own Back closes the panel without a second back, and the next opening pushes again', async () => {
    const p = await start();
    p.open();
    p.w.history.back();   // the visitor's Back (counted once here)
    await tick();
    assert.equal(p.log.backs, 1);
    assert.equal(p.panel.hidden, true);
    assert.equal(p.d.documentElement.classList.contains('sf-search-open'), false);
    p.open();
    assert.equal(p.log.pushes, 2);
});

test('closing and reopening in one task: the reopened panel is not popped by the queued back, and gets its own entry once that settles', async () => {
    const p = await start();
    p.open();
    p.close();
    p.open();
    assert.equal(p.log.pushes, 1, 'no push while our back is still on its way');
    await tick();
    assert.equal(isOpen(p), true);
    assert.equal(p.log.pushes, 2);
    assert.equal(p.w.history.state?.sfSearch, 1);
    assert.equal(p.panel.hasAttribute('aria-modal'), true);
    assert.equal(p.panel.hasAttribute('data-search-sheet'), true);
    // and that entry is the one Back removes
    p.w.history.back();
    await tick();
    assert.equal(p.panel.hidden, true);
    assert.equal(p.log.backs, 2);
});

test('a page restored from the back/forward cache with the sheet open closes it and steps back off its entry', async () => {
    const p = await start();
    p.open();
    const shown = new p.w.Event('pageshow');
    Object.defineProperty(shown, 'persisted', { value: true });
    p.w.dispatchEvent(shown);
    assert.equal(p.panel.hidden, true);
    assert.equal(p.log.backs, 1);
    await tick();
    assert.equal(p.w.history.state, null, 'no duplicate entry is left');
    assert.equal(p.log.backs, 1);
    assert.equal(p.d.documentElement.classList.contains('sf-search-open'), false);
    // a restore that is not from the cache changes nothing
    p.open();
    const fresh = new p.w.Event('pageshow');
    Object.defineProperty(fresh, 'persisted', { value: false });
    p.w.dispatchEvent(fresh);
    assert.equal(isOpen(p), true);
});

test('a link or a search sent from the sheet replaces its entry (no back); a modified click is left to the browser', async () => {
    const p = await start();
    p.open();
    const click = (init) => {
        const event = new p.w.MouseEvent('click', { bubbles: true, cancelable: true, ...init });
        p.d.getElementById('chip').dispatchEvent(event);
        return event;
    };
    assert.equal(click({ ctrlKey: true }).defaultPrevented, false);
    assert.deepEqual(p.log.replaced, []);
    assert.equal(click({}).defaultPrevented, true);
    assert.deepEqual(p.log.replaced, ['https://a.test/san-pham']);
    assert.equal(p.log.backs, 0);

    const s = await start();
    s.open();
    s.d.querySelector('[data-search-input]').value = 'đồ gá';
    const submit = new s.w.Event('submit', { bubbles: true, cancelable: true });
    s.d.getElementById('f').dispatchEvent(submit);
    assert.equal(submit.defaultPrevented, true);
    assert.deepEqual(s.log.replaced, ['https://a.test/tim-kiem?q=%C4%91%E1%BB%93+g%C3%A1']);
    assert.equal(s.log.backs, 0);
});
