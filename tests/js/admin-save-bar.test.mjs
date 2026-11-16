// The shared settings save bar (resources/js/admin-save-bar.js) in a DOM without a browser: when its
// buttons act, the one press "Lưu" takes, typed text the server has not seen, and the first error.
// `node --test tests/js/admin-save-bar.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://a.test/admin/settings/general', pretendToBeVisual: true });
globalThis.window = dom.window;
globalThis.document = dom.window.document;
let factory = null;
window.Alpine = { data: (name, fn) => (name === 'bsSaveBar' ? (factory = fn) : null) };
const { clock } = await import('../../resources/js/admin-save-bar.js');

const tick = () => new Promise((r) => setTimeout(r, 0));
const words = { changes: ':count thay đổi chưa lưu', clean: 'Chưa có thay đổi', savedAt: 'Đã lưu :time', errs: ':count lỗi' };
let last = null;

// A settings section with `changes` unsaved fields and `invalid` in error; `wire` answers its calls.
function bar({ changes = 0, invalid = 0, live = true, firstError = null, wire = {} } = {}) {
    if (last) {
        last.destroy();
    }
    document.body.innerHTML = `<div data-set-shell><input id="bad" aria-invalid="${invalid > 0}">
        <div id="root"><button id="discard" data-sb-discard></button><button id="save" data-sb-save></button><span id="state" data-changes="${changes}" data-invalid="${invalid}" data-first-error='{"group":"contact"}'></span></div></div>`;
    const state = document.getElementById('state');
    const set = (c, i = 0) => {
        state.setAttribute('data-changes', String(c));
        state.setAttribute('data-invalid', String(i));
    };
    const calls = [];
    const c = factory({ save: 'save', discard: 'resetChanges', live, firstError, ...words });
    c.$root = document.getElementById('root');
    c.$refs = { state };
    c.$wire = {
        save: async () => {
            calls.push('save');
            await tick();
            // like the page's save method: true once it wrote
            return (wire.save || ((s) => (s(0), true)))(set);
        },
        resetChanges: async () => {
            calls.push('resetChanges');
            set(0);
        },
    };
    c.init();
    last = c;

    return { c, calls, set };
}

test('nothing to save: both buttons do nothing and the status says so', async () => {
    const { c, calls } = bar();
    assert.equal(c.dirty, false);
    assert.equal(c.blocked, true);
    assert.equal(c.cleanText, 'Chưa có thay đổi');
    await c.go();
    await c.discard();
    assert.deepEqual(calls, []);
});

test('a change turns the bar on, and "Lưu" saves once however often it is pressed', async () => {
    const { c, calls } = bar({ changes: 2 });
    assert.equal(c.dirty, true);
    assert.equal(c.blocked, false);
    assert.equal(c.changesText, '2 thay đổi chưa lưu');
    const first = c.go();
    assert.equal(c.busy, 'save'); // busy at once, before the server answers
    await c.go();
    await first;
    assert.deepEqual(calls, ['save']);
    assert.equal(c.busy, '');
    assert.equal(c.dirty, false);
    assert.match(c.cleanText, /^Đã lưu \d\d:\d\d$/);
});

test('a save that wrote nothing (the page answers false) never says "Đã lưu"', async () => {
    const { c, calls } = bar({ changes: 1, wire: { save: (set) => (set(0), false) } });
    await c.go();
    assert.deepEqual(calls, ['save']);
    assert.equal(c.dirty, false);
    assert.equal(c.cleanText, 'Chưa có thay đổi');
});

test('a lost save request leaves the status as it was', async () => {
    const { c } = bar({ changes: 1, wire: { save: () => { throw new Error('lost'); } } });
    await c.go();
    assert.equal(c.busy, '');
    assert.equal(c.dirty, true);
    assert.equal(c.cleanText, 'Chưa có thay đổi');
});

test('a second "Lưu" while the first save runs (Enter pressed twice) saves once', async () => {
    const { c, calls, set } = bar();
    await c.go();
    assert.deepEqual(calls, [], 'nothing to save: does nothing');
    set(1);
    await tick();
    const first = c.go();
    await c.go();
    await first;
    assert.deepEqual(calls, ['save']);
    assert.match(c.cleanText, /^Đã lưu \d\d:\d\d$/);
});

test('the server redrawing its count is followed without a press', async () => {
    const { c, set } = bar();
    set(3);
    await tick();
    assert.equal(c.count, 3);
    assert.equal(c.dirty, true);
});

test('"Bỏ thay đổi" asks the page to put the saved values back', async () => {
    const { c, calls } = bar({ changes: 1 });
    await c.discard();
    assert.deepEqual(calls, ['resetChanges']);
    assert.equal(c.dirty, false);
    assert.equal(c.cleanText, 'Chưa có thay đổi'); // a discard is not a save
});

test('"Bỏ thay đổi" pressed from the keyboard leaves the focus in the bar, on "Lưu"', async () => {
    const { c } = bar({ changes: 1 });
    const discard = document.getElementById('discard');
    discard.focus();
    const done = c.discard();
    discard.blur(); // the button turns itself off at once, and the browser drops its focus
    discard.disabled = true;
    await done;
    assert.equal(document.activeElement, document.getElementById('save'));
});

test('a discard pressed elsewhere does not pull the focus to the bar', async () => {
    const { c } = bar({ changes: 1 });
    document.getElementById('bad').focus();
    await c.discard();
    assert.equal(document.activeElement, document.getElementById('bad'));
});

test('text typed but not sent yet counts as a change, as the leave dialog says', () => {
    const { c } = bar();
    window.dispatchEvent(new window.CustomEvent('bs-unsaved-typed', { detail: { on: true } }));
    assert.equal(c.dirty, true);
    assert.equal(c.changesText, '1 thay đổi chưa lưu');
    window.dispatchEvent(new window.CustomEvent('bs-unsaved-typed', { detail: { on: false } }));
    assert.equal(c.dirty, false);
});

test('a live-checked section sends "Lưu" to its first error instead of saving', async () => {
    const opened = [];
    window.addEventListener('gs-first-error', (e) => opened.push(e.detail));
    const { c, calls } = bar({ changes: 1, invalid: 2, firstError: 'gs-first-error' });
    assert.equal(c.blocked, true);
    assert.equal(c.errsText, '2 lỗi');
    await c.go();
    assert.deepEqual(calls, []);
    assert.deepEqual(opened, [{ group: 'contact' }]);
});

test('without the page event the first field in error gets the focus', () => {
    const { c } = bar({ changes: 1, invalid: 1 });
    document.getElementById('bad').getClientRects = () => [{}];
    c.firstError();
    assert.equal(document.activeElement, document.getElementById('bad'));
});

test('a section checked on save lets "Lưu" go to the server despite old errors', async () => {
    const { c, calls } = bar({ changes: 1, invalid: 1, live: false });
    assert.equal(c.blocked, false);
    await c.go();
    assert.deepEqual(calls, ['save']);
});

test('clock writes hours and minutes with two digits', () => {
    assert.equal(clock(new Date(2026, 10, 16, 7, 5)), '07:05');
});
