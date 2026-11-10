// The branch map script (resources/js/sf-branch-map.js): the server prints the first branch with plain links and
// a hidden chooser; the script adds the tab roles, roving tabindex and keys, swaps the map and the card among
// the values printed on the buttons, and copies the address. Run against a DOM without a browser:
// `node --test tests/js/sf-branch-map.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;

const tab = (n, approx) => `<button type="button" class="sf-bmap__tab" data-sf-bmap-tab data-embed="https://www.google.com/maps?q=${n}&amp;output=embed" data-dir="https://www.google.com/maps/dir/?api=1&amp;destination=${n}" data-open="https://www.google.com/maps/search/?api=1&amp;query=${n}" data-name="Nhà máy ${n}" data-meta="${n}00 m²" data-addr="${n === 3 ? '' : `Địa chỉ ${n}`}" data-approx="${approx ? '1' : ''}" data-title="Bản đồ ${n}"><span>Nhà máy ${n}</span></button>`;

const block = (count = 3) => `<section data-sf-bmap data-copy-label="Sao chép" data-copied-label="Đã chép ✓" data-copy-failed="Không sao chép được">
    <div data-sf-bmap-tabs data-label="Chọn nhà máy" hidden>${[1, 2, 3].slice(0, count).map((n) => tab(n, n === 2)).join('')}</div>
    <div data-sf-bmap-panel>
        <iframe data-sf-bmap-frame src="https://www.google.com/maps?q=1&amp;output=embed" title="Bản đồ 1"></iframe>
        <h3 data-sf-bmap-name>Nhà máy 1</h3><span data-sf-bmap-meta>100 m²</span>
        <p data-sf-bmap-addr>Địa chỉ 1</p><p data-sf-bmap-approx hidden>Gần đúng</p>
        <a data-sf-bmap-dir href="d1">Chỉ đường</a><a data-sf-bmap-open href="o1">Mở Maps</a>
        <button type="button" data-sf-bmap-copy hidden>Sao chép</button>
        <p data-sf-bmap-status role="status" aria-live="polite"></p>
    </div>
</section>`;

// The page, and a start() that runs a fresh copy of the script (it keeps a block counter) against it.
function page(html, clipboard) {
    const dom = new JSDOM(`<!doctype html><body>${html}</body>`, { url: 'https://example.test/' });
    const doc = dom.window.document;
    return {
        doc,
        window: dom.window,
        start: async () => {
            globalThis.document = doc;
            globalThis.window = dom.window;
            Object.defineProperty(globalThis, 'navigator', { value: clipboard ? { clipboard } : {}, configurable: true });
            await import(`../../resources/js/sf-branch-map.js?run=${++load}`);
        },
    };
}

const key = (win, el, name) => el.dispatchEvent(new win.KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true }));
const text = (doc, name) => doc.querySelector(`[data-sf-bmap-${name}]`).textContent;

test('before the script runs there are no roles, the chooser is hidden and the buttons are plain links', () => {
    const { doc } = page(block());
    assert.equal(doc.querySelectorAll('[role="tab"], [role="tablist"], [role="tabpanel"]').length, 0);
    assert.equal(doc.querySelector('[data-sf-bmap-tabs]').hidden, true);
    assert.equal(doc.querySelector('[data-sf-bmap-copy]').hidden, true);
    assert.equal(doc.querySelector('[data-sf-bmap-dir]').getAttribute('href'), 'd1');
});

test('the chooser is revealed with tab roles, one tab stop and the first branch selected', async () => {
    const { doc, start } = page(block());
    await start();
    const bar = doc.querySelector('[data-sf-bmap-tabs]');
    const tabs = [...doc.querySelectorAll('[data-sf-bmap-tab]')];
    const panel = doc.querySelector('[data-sf-bmap-panel]');

    assert.equal(bar.hidden, false);
    assert.equal(bar.getAttribute('role'), 'tablist');
    assert.equal(bar.getAttribute('aria-label'), 'Chọn nhà máy');
    assert.deepEqual(tabs.map((t) => t.getAttribute('role')), ['tab', 'tab', 'tab']);
    assert.deepEqual(tabs.map((t) => t.getAttribute('aria-selected')), ['true', 'false', 'false']);
    assert.deepEqual(tabs.map((t) => t.tabIndex), [0, -1, -1]);
    assert.equal(new Set(tabs.map((t) => t.id)).size, 3);
    assert.equal(tabs[0].getAttribute('aria-controls'), panel.id);
    assert.equal(panel.getAttribute('role'), 'tabpanel');
    assert.equal(panel.getAttribute('aria-labelledby'), tabs[0].id);
    assert.equal(doc.querySelector('[data-sf-bmap-copy]').hidden, false);
});

test('arrow keys, Home and End move the focus and the tab stop without choosing', async () => {
    const { doc, window, start } = page(block());
    await start();
    const tabs = [...doc.querySelectorAll('[data-sf-bmap-tab]')];
    tabs[0].focus();

    key(window, tabs[0], 'ArrowRight');
    assert.equal(doc.activeElement, tabs[1]);
    assert.deepEqual(tabs.map((t) => t.tabIndex), [-1, 0, -1]);
    assert.equal(tabs[0].getAttribute('aria-selected'), 'true');
    assert.equal(doc.querySelector('[data-sf-bmap-frame]').src, 'https://www.google.com/maps?q=1&output=embed');

    key(window, tabs[1], 'ArrowLeft');
    key(window, tabs[0], 'ArrowLeft');
    assert.equal(doc.activeElement, tabs[2]);
    key(window, tabs[2], 'ArrowRight');
    assert.equal(doc.activeElement, tabs[0]);
    key(window, tabs[0], 'End');
    assert.equal(doc.activeElement, tabs[2]);
    key(window, tabs[2], 'Home');
    assert.equal(doc.activeElement, tabs[0]);
});

test('a pick swaps the map, the links and the card text, and shows the approximate line and the copy button as the data says', async () => {
    const { doc, start } = page(block());
    await start();
    const tabs = [...doc.querySelectorAll('[data-sf-bmap-tab]')];

    tabs[1].click();
    assert.equal(doc.querySelector('[data-sf-bmap-frame]').src, 'https://www.google.com/maps?q=2&output=embed');
    assert.equal(doc.querySelector('[data-sf-bmap-frame]').title, 'Bản đồ 2');
    assert.equal(doc.querySelector('[data-sf-bmap-dir]').getAttribute('href'), 'https://www.google.com/maps/dir/?api=1&destination=2');
    assert.equal(doc.querySelector('[data-sf-bmap-open]').getAttribute('href'), 'https://www.google.com/maps/search/?api=1&query=2');
    assert.equal(text(doc, 'name'), 'Nhà máy 2');
    assert.equal(text(doc, 'meta'), '200 m²');
    assert.equal(text(doc, 'addr'), 'Địa chỉ 2');
    assert.equal(doc.querySelector('[data-sf-bmap-approx]').hidden, false);
    assert.deepEqual(tabs.map((t) => t.getAttribute('aria-selected')), ['false', 'true', 'false']);
    assert.equal(doc.querySelector('[data-sf-bmap-panel]').getAttribute('aria-labelledby'), tabs[1].id);

    // a branch with no address hides the address and the copy button
    tabs[2].click();
    assert.equal(doc.querySelector('[data-sf-bmap-addr]').hidden, true);
    assert.equal(doc.querySelector('[data-sf-bmap-copy]').hidden, true);
    assert.equal(doc.querySelector('[data-sf-bmap-approx]').hidden, true);
});

test('leaving the chooser puts the tab stop back on the chosen branch', async () => {
    const { doc, window, start } = page(block());
    await start();
    const tabs = [...doc.querySelectorAll('[data-sf-bmap-tab]')];
    tabs[1].click();
    tabs[1].focus();
    key(window, tabs[1], 'ArrowRight');
    assert.deepEqual(tabs.map((t) => t.tabIndex), [-1, -1, 0]);

    tabs[2].dispatchEvent(new window.FocusEvent('focusout', { bubbles: true, relatedTarget: doc.body }));
    assert.deepEqual(tabs.map((t) => t.tabIndex), [-1, 0, -1]);
});

test('a single branch keeps its chooser hidden and the card as printed, but still copies', async () => {
    const { doc, start } = page(block(1));
    await start();
    assert.equal(doc.querySelector('[data-sf-bmap-tabs]').hidden, true);
    assert.equal(doc.querySelectorAll('[role="tab"]').length, 0);
    assert.equal(doc.querySelector('[data-sf-bmap-copy]').hidden, false);
});

test('copy writes the address with the clipboard and announces it politely', async () => {
    const written = [];
    const { doc, start } = page(block(), { writeText: async (value) => { written.push(value); } });
    Object.defineProperty(globalThis, 'isSecureContext', { value: true, configurable: true });
    await start();
    globalThis.window.isSecureContext = true;
    const copy = doc.querySelector('[data-sf-bmap-copy]');

    copy.click();
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.deepEqual(written, ['Địa chỉ 1']);
    assert.equal(copy.textContent, 'Đã chép ✓');
    assert.equal(text(doc, 'status'), 'Đã chép ✓');
});

test('copy falls back to a hidden text box and says so when nothing works', async () => {
    const copied = page(block());
    await copied.start();
    copied.doc.execCommand = () => true;
    copied.doc.querySelector('[data-sf-bmap-copy]').click();
    assert.equal(text(copied.doc, 'status'), 'Đã chép ✓');
    assert.equal(copied.doc.querySelector('textarea'), null);

    const failed = page(block());
    await failed.start();
    failed.doc.execCommand = () => false;
    failed.doc.querySelector('[data-sf-bmap-copy]').click();
    assert.equal(text(failed.doc, 'status'), 'Không sao chép được');
    assert.equal(failed.doc.querySelector('[data-sf-bmap-copy]').textContent, 'Không sao chép được');
});
