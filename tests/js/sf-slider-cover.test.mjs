// The hero slider (resources/js/sf-slider.js) holds still while the search panel covers the page
// (html.sf-search-open, announced by sf:cover) and runs on when it is gone.
// In a DOM without a browser: `node --test tests/js/sf-slider-cover.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const tick = (ms) => new Promise((r) => setTimeout(r, ms));

test('the slider does not advance while html.sf-search-open is set, and rotates again when it is removed', async () => {
    const dom = new JSDOM(`<!doctype html><html><body><div data-sf-slider data-interval="60"><div class="sf-slider__viewport"><div class="sf-slide is-active"></div><div class="sf-slide"></div><div class="sf-slide"></div></div><div class="sf-slider__controls" hidden></div></div></body></html>`, { pretendToBeVisual: true });
    const w = dom.window;
    w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    for (const name of ['window', 'document', 'navigator', 'CustomEvent', 'matchMedia']) {
        Object.defineProperty(globalThis, name, { value: name === 'window' ? w : w[name], configurable: true, writable: true });
    }
    const d = w.document;
    const active = () => [...d.querySelectorAll('.sf-slide')].findIndex((s) => s.classList.contains('is-active'));
    d.documentElement.classList.add('sf-search-open');
    await import('../../resources/js/sf-slider.js?cover');
    w.dispatchEvent(new w.Event('keydown'));   // the visitor is here
    await tick(250);
    assert.equal(active(), 0, 'covered: nothing rotates');

    d.documentElement.classList.remove('sf-search-open');
    d.dispatchEvent(new w.CustomEvent('sf:cover'));
    await tick(250);
    assert.notEqual(active(), 0, 'uncovered: it rotates');

    d.documentElement.classList.add('sf-search-open');
    d.dispatchEvent(new w.CustomEvent('sf:cover'));
    await tick(30);
    const held = active();
    await tick(250);
    assert.equal(active(), held, 'covered again: it holds where it stood');
});
