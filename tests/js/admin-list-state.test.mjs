// Which failed requests the shared list takes over (resources/js/admin-list-state.js), with a stub Livewire:
// `node --test tests/js/admin-list-state.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body><input type="search" id="top"><div id="card" wire:id="lw1"><input type="search" id="s"><div id="root"><div role="table"></div></div></div></body></html>');
const { window } = dom;
globalThis.window = window;
globalThis.document = window.document;
globalThis.CustomEvent = window.CustomEvent;
let factory = null;
window.Alpine = { data: (n, f) => { factory = f; } };
let handler = null;
window.Livewire = { interceptMessage: (h) => { handler = h; return () => {}; } };
await import('../../resources/js/admin-list-state.js');
window.document.dispatchEvent(new window.Event('alpine:init'));

function setup() {
    const cmp = factory(['search', 'tab', 'sortBy', '$refresh']);
    cmp.$wire = { $id: 'c1' };
    cmp.$el = window.document.getElementById('root');
    cmp.$watch = () => {};
    cmp.init();
    return cmp;
}

// drives one message through the handler; `fn` gets the registered callbacks
function run(cmp, payload, fn) {
    const cb = {};
    const reg = (n) => (f) => { cb[n] = f; };
    handler({ message: { component: { id: 'c1' } }, onSend: reg('send'), onFailure: reg('failure'), onError: reg('error'), onSuccess: reg('success') });
    cb.send({ payload });
    let prevented = false;
    cb.prevented = () => prevented;
    cb.errorWith = (status) => cb.error({ response: { status }, preventDefault: () => { prevented = true; } });
    fn(cb);
    return cb;
}

test('a dropped connection on a list request shows the error state', () => {
    const c = setup();
    run(c, { updates: { search: 'x' }, calls: [] }, (cb) => cb.failure());
    assert.equal(c.failed, true);
});

test('5xx and 429 on a list request are taken over', () => {
    for (const status of [500, 503, 429]) {
        const c = setup();
        const cb = run(c, { updates: {}, calls: [{ method: 'sortBy', params: ['name'] }] }, (k) => k.errorWith(status));
        assert.equal(c.failed, true, String(status));
        assert.equal(cb.prevented(), true);
        assert.deepEqual(c.again, [{ method: 'sortBy', params: ['name'] }]);
    }
});

test('403, 404, 419 and an empty 200 stay with Livewire', () => {
    for (const status of [403, 404, 419, 422, 200]) {
        const c = setup();
        const cb = run(c, { updates: { search: 'x' }, calls: [] }, (k) => k.errorWith(status));
        assert.equal(c.failed, false, String(status));
        assert.equal(cb.prevented(), false);
    }
});

test('a request that also carries a call that is not the list\'s own is never taken over', () => {
    const c = setup();
    const cb = run(c, { updates: { search: 'x' }, calls: [{ method: 'sortBy', params: [] }, { method: 'bulkTrash', params: [] }] }, (k) => { k.errorWith(500); k.failure(); });
    assert.equal(c.failed, false);
    assert.equal(cb.prevented(), false);
});

test('a request with no list update and no list call is not the list\'s', () => {
    const c = setup();
    run(c, { updates: { selected: [1] }, calls: [] }, (k) => k.failure());
    assert.equal(c.failed, false);
});

test('a success clears the error state', () => {
    const c = setup();
    run(c, { updates: {}, calls: [{ method: '$refresh', params: [] }] }, (k) => k.failure());
    assert.equal(c.failed, true);
    run(c, { updates: {}, calls: [{ method: '$refresh', params: [] }] }, (k) => k.success({}));
    assert.equal(c.failed, false);
});

test('after the retry succeeds the focus goes to the search field, else the table', () => {
    const c = setup();
    window.document.body.focus();
    run(c, { updates: {}, calls: [{ method: '$refresh', params: [] }] }, (k) => k.failure());
    run(c, { updates: {}, calls: [{ method: '$refresh', params: [] }] }, (k) => k.success({}));
    assert.equal(window.document.activeElement.id, 's');
    window.document.getElementById('s').remove();
    window.document.activeElement.blur?.();
    c.failed = true;
    run(c, { updates: {}, calls: [{ method: '$refresh', params: [] }] }, (k) => { c.failed = true; k.success({}); });
    const t = window.document.querySelector('[role="table"]');
    assert.equal(t.getAttribute('tabindex'), '-1');
    assert.equal(window.document.activeElement, t);
});
