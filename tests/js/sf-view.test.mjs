// The view count beacon (resources/js/sf-view.js): one beacon per page shown, none for a page the
// browser prepared ahead of a click and never showed: `node --test tests/js/sf-view.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;

async function run({ prerendering = false, meta = true } = {}) {
    const dom = new JSDOM(`<!doctype html><head>${meta ? '<meta name="sf-view" content="/_view/abc">' : ''}</head><body></body>`);
    const doc = dom.window.document;
    Object.defineProperty(doc, 'prerendering', { configurable: true, get: () => prerendering });
    const sent = [];
    globalThis.document = doc;
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { sendBeacon: (url) => sent.push(url) } });
    await import(`../../resources/js/sf-view.js?run=${++load}`);
    return { doc, win: dom.window, sent, show: () => { prerendering = false; doc.dispatchEvent(new dom.window.Event('prerenderingchange')); } };
}

test('a page shown at once sends one view', async () => {
    const { sent } = await run();
    assert.deepEqual(sent, ['/_view/abc']);
});

test('a prerendered page sends nothing until it is shown, then exactly one view', async () => {
    const { sent, show, doc, win } = await run({ prerendering: true });
    assert.deepEqual(sent, []);
    show();
    doc.dispatchEvent(new win.Event('prerenderingchange'));
    assert.deepEqual(sent, ['/_view/abc']);
});

test('a page without the view address sends nothing', async () => {
    const { sent } = await run({ meta: false });
    assert.deepEqual(sent, []);
});
