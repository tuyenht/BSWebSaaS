// The storefront tabs script (resources/js/sf-tabs.js): the server prints plain stacked panels, the script
// adds the tab roles, ids, selection and keys, in a DOM without a browser:
// `node --test tests/js/sf-tabs.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;

const block = (n) => `<div class="sf-tabs" data-sf-tabs>
    <div class="sf-tabs__bar" data-label="Tin ${n}" hidden>
        <button type="button" data-sf-tab>A</button><button type="button" data-sf-tab>B</button><button type="button" data-sf-tab>C</button>
    </div>
    <div data-sf-tabpanel>a</div><div data-sf-tabpanel>b</div><div data-sf-tabpanel>c</div>
</div>`;

// The page, and a start() that runs a fresh copy of the script (it keeps a block counter) against it.
function page(html) {
    const dom = new JSDOM(`<!doctype html><body>${html}</body>`);
    const doc = dom.window.document;
    return {
        doc,
        window: dom.window,
        start: async () => {
            globalThis.document = doc;
            await import(`../../resources/js/sf-tabs.js?run=${++load}`);
        },
    };
}

const key = (win, el, name) => el.dispatchEvent(new win.KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true }));

test('before the script runs there are no roles or ids', () => {
    const { doc } = page(block(1));
    assert.equal(doc.querySelectorAll('[role]').length, 0);
    assert.equal(doc.querySelectorAll('[id]').length, 0);
    assert.equal(doc.querySelector('.sf-tabs__bar').hidden, true);
});

test('two blocks get unique ids and the tablist, tab and tabpanel wiring', async () => {
    const { doc, start } = page(block(1) + block(2));
    await start();
    [...doc.querySelectorAll('[data-sf-tabs]')].forEach((root, b) => {
        const bar = root.querySelector('.sf-tabs__bar');
        assert.equal(bar.getAttribute('role'), 'tablist');
        assert.equal(bar.getAttribute('aria-label'), `Tin ${b + 1}`);
        assert.equal(bar.hidden, false);
        assert.equal(root.dataset.ready, '');
        root.querySelectorAll('[data-sf-tab]').forEach((tab, i) => {
            const panel = root.querySelectorAll('[data-sf-tabpanel]')[i];
            assert.equal(tab.getAttribute('role'), 'tab');
            assert.equal(tab.id, `sf-tab-${b + 1}-${i + 1}`);
            assert.equal(tab.getAttribute('aria-controls'), `sf-tabpanel-${b + 1}-${i + 1}`);
            assert.equal(panel.getAttribute('role'), 'tabpanel');
            assert.equal(panel.id, `sf-tabpanel-${b + 1}-${i + 1}`);
            assert.equal(panel.getAttribute('aria-labelledby'), tab.id);
            assert.equal(tab.getAttribute('aria-selected'), i === 0 ? 'true' : 'false');
            assert.equal(tab.tabIndex, i === 0 ? 0 : -1);
            assert.equal(panel.hidden, i !== 0);
        });
    });
    assert.equal(new Set([...doc.querySelectorAll('[id]')].map((e) => e.id)).size, 12);
});

test('a click selects its tab and shows only its panel', async () => {
    const { doc, start } = page(block(1));
    await start();
    const tabs = doc.querySelectorAll('[data-sf-tab]');
    tabs[2].click();
    assert.deepEqual([...tabs].map((t) => t.getAttribute('aria-selected')), ['false', 'false', 'true']);
    assert.deepEqual([...doc.querySelectorAll('[data-sf-tabpanel]')].map((p) => p.hidden), [true, true, false]);
});

test('Arrow, Home and End move the selection and the focus, wrapping at the ends', async () => {
    const { doc, window, start } = page(block(1));
    await start();
    const tabs = [...doc.querySelectorAll('[data-sf-tab]')];
    const selected = () => tabs.findIndex((t) => t.getAttribute('aria-selected') === 'true');

    key(window, tabs[0], 'ArrowRight');
    assert.equal(selected(), 1);
    assert.equal(doc.activeElement, tabs[1]);
    key(window, tabs[1], 'End');
    assert.equal(selected(), 2);
    key(window, tabs[2], 'ArrowRight');
    assert.equal(selected(), 0);
    key(window, tabs[0], 'ArrowLeft');
    assert.equal(selected(), 2);
    key(window, tabs[2], 'Home');
    assert.equal(selected(), 0);
    assert.equal(doc.activeElement, tabs[0]);

    const other = new window.KeyboardEvent('keydown', { key: 'a', bubbles: true, cancelable: true });
    tabs[0].dispatchEvent(other);
    assert.equal(other.defaultPrevented, false);
    assert.equal(selected(), 0);
});

test('a block without a bar is skipped and the later blocks are still set up', async () => {
    const { doc, start } = page(block(1).replace('sf-tabs__bar', 'sf-tabs__nobar') + block(2));
    await start();
    const [first, second] = doc.querySelectorAll('[data-sf-tabs]');
    assert.equal(first.querySelectorAll('[role]').length, 0);
    assert.equal(first.dataset.ready, undefined);
    assert.equal(second.querySelector('.sf-tabs__bar').getAttribute('role'), 'tablist');
    assert.equal(second.querySelector('[data-sf-tab]').id, 'sf-tab-1-1');
});
