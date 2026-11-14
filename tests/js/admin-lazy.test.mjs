// The shared lazy loader (resources/js/admin-lazy.js): a screen part that starts once its module has
// downloaded, and the failed download's "Thử lại", in a DOM without a browser:
// `node --test tests/js/admin-lazy.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';
import { makeLazy } from '../../resources/js/admin-lazy.js';

const tick = () => new Promise((r) => setTimeout(r, 0));

function page() {
    const dom = new JSDOM(`<!doctype html><body><div x-ignore data-bs-lazy="part">
        <div data-bs-lazy-wait>Đang tải chuyên mục…</div>
        <div data-bs-lazy-error><button type="button" data-bs-lazy-retry>Thử lại</button></div>
    </div></body>`);
    const doc = dom.window.document;
    return { doc, root: doc.querySelector('[data-bs-lazy]'), retry: doc.querySelector('[data-bs-lazy-retry]') };
}

function loader(answers) {
    const seen = { loads: 0, started: [], reloads: 0 };
    const lazy = makeLazy({
        parts: { part: () => (seen.loads++, answers.shift() ? Promise.resolve() : Promise.reject(new Error('lost'))) },
        alpine: () => ({ initTree: (el) => seen.started.push(el) }),
        reload: () => seen.reloads++,
    });
    return { lazy, seen };
}

test('a part that downloads starts once, without its x-ignore', async () => {
    const { doc, root } = page();
    const { lazy, seen } = loader([true]);
    lazy.scan(doc);
    lazy.scan(doc); // a second scan while it loads starts nothing more
    await tick();
    assert.equal(seen.loads, 1);
    assert.deepEqual(seen.started, [root]);
    assert.equal(root.hasAttribute('x-ignore'), false);
    assert.equal(root.hasAttribute('data-bs-lazy-failed'), false);
});

test('a lost download marks the root, so the error and "Thử lại" show without Alpine', async () => {
    const { doc, root } = page();
    const { lazy, seen } = loader([false]);
    lazy.scan(doc);
    await tick();
    assert.equal(root.hasAttribute('data-bs-lazy-failed'), true);
    assert.equal(root.hasAttribute('x-ignore'), true);
    assert.deepEqual(seen.started, []);
});

test('"Thử lại" downloads again and starts the part, clearing the failed mark meanwhile', async () => {
    const { doc, root, retry } = page();
    const { lazy, seen } = loader([false, true]);
    lazy.scan(doc);
    await tick();
    lazy.click({ target: retry });
    assert.equal(root.hasAttribute('data-bs-lazy-failed'), false, 'the waiting block shows again while it loads');
    await tick();
    assert.equal(seen.loads, 2);
    assert.deepEqual(seen.started, [root]);
    assert.equal(seen.reloads, 0);
});

test('when the browser keeps the failed download, "Thử lại" reloads the page', async () => {
    const { doc, retry } = page();
    const { lazy, seen } = loader([false, false]);
    lazy.scan(doc);
    await tick();
    lazy.click({ target: retry });
    await tick();
    assert.equal(seen.reloads, 1);
});

test('a click elsewhere, or on a root that has not failed, does nothing', async () => {
    const { doc, root, retry } = page();
    const { lazy, seen } = loader([true]);
    lazy.click({ target: retry });
    lazy.click({ target: doc.body });
    await tick();
    assert.equal(seen.loads, 0);
    assert.equal(root.hasAttribute('x-ignore'), true);
});

test('a Livewire redraw of a started part takes the server root without x-ignore; a waiting part keeps it', () => {
    const { doc, root } = page();
    const { lazy } = loader([]);
    const incoming = () => {
        const el = doc.createElement('div');
        el.setAttribute('x-ignore', '');
        el.setAttribute('data-bs-lazy', 'part');
        return el;
    };

    const waiting = incoming();
    lazy.keepStarted(root, waiting); // not started yet: Alpine must keep skipping it
    assert.equal(waiting.hasAttribute('x-ignore'), true);

    root.removeAttribute('x-ignore'); // what start() does once the part runs
    const started = incoming();
    lazy.keepStarted(root, started);
    assert.equal(started.hasAttribute('x-ignore'), false);

    const other = doc.createElement('div'); // any other element of the redraw is left alone
    const otherTo = incoming();
    lazy.keepStarted(other, otherTo);
    assert.equal(otherTo.hasAttribute('x-ignore'), true);
});
