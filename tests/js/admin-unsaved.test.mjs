// The shared unsaved-changes dialog (resources/js/admin-unsaved.js) in a DOM without a browser: when
// it asks, what each of its buttons does, and how text typed but not yet sent counts.
// `node --test tests/js/admin-unsaved.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://a.test/admin/settings/general', pretendToBeVisual: true });
globalThis.window = dom.window;
globalThis.document = dom.window.document;
window.HTMLCanvasElement.prototype.getContext = () => null; // no layout here: the row/stacked choice is tested through layoutOf
let factory = null;
const hooks = [];
window.Alpine = { data: (name, fn) => (name === 'bsUnsaved' ? (factory = fn) : null) };
const went = [];
window.Livewire = {
    navigate: (url) => went.push(url),
    hook: (name, cb) => {
        hooks.push(cb);
        return () => hooks.splice(hooks.indexOf(cb), 1);
    },
};
const { layoutOf, ORDER } = await import('../../resources/js/admin-unsaved.js');

const tick = () => new Promise((r) => setTimeout(r, 0));
const frame = () => new Promise((r) => window.requestAnimationFrame(() => r()));
let last = null;

// A settings page with one .blur field, its count and the dialog; `wire` answers its server calls.
function page(count = 0, wire = {}) {
    if (last) {
        last.destroy();
    }
    went.length = 0;
    document.body.innerHTML = `<div wire:id="c1"><input id="f" wire:model.live.blur="form.phone"><a id="link" href="/elsewhere">x</a>
        <div id="root"><span id="count" data-unsaved-count="${count}"></span><div class="sx-ud" id="box"><div id="foot">
        <button data-ud="p">Lưu</button><button data-ud="s">Ở lại</button><button data-ud="x">Bỏ</button></div></div></div></div>`;
    const calls = [];
    const setCount = (n) => document.getElementById('count').setAttribute('data-unsaved-count', String(n));
    const c = factory({ save: 'save', discard: 'resetChanges', sections: ['/admin/settings/general', '/admin/settings/seo'] });
    c.$root = document.getElementById('root');
    c.$refs = { count: document.getElementById('count'), foot: document.getElementById('foot'), box: document.getElementById('box') };
    c.$nextTick = (fn) => fn();
    c.$wire = {
        $id: 'c1',
        save: async () => {
            calls.push('save');
            (wire.save || (() => setCount(0)))(setCount);
        },
        resetChanges: async () => {
            calls.push('resetChanges');
            setCount(0);
        },
        $commit: async () => {
            calls.push('$commit');
            if (wire.commit) {
                wire.commit(setCount);
            }
        },
    };
    c.init();
    last = c;

    return { c, calls, setCount };
}

// A wire:navigate link to `path`: true when it went through untouched.
async function navigate(path) {
    const passed = document.dispatchEvent(new window.CustomEvent('livewire:navigate', { cancelable: true, detail: { url: new URL(path, 'https://a.test'), history: false } }));
    await tick();

    return passed;
}

test('a page with nothing unsaved lets every link and the tab close through', async () => {
    const { c } = page(0);
    assert.equal(await navigate('/admin/settings/seo'), true);
    assert.equal(c.open, false);
    const e = new window.Event('beforeunload', { cancelable: true });
    window.dispatchEvent(e);
    assert.equal(e.defaultPrevented, false);
});

test('with changes, a link asks first: another settings section says so, any other page reads "rời đi"', async () => {
    const { c } = page(2);
    assert.equal(await navigate('/admin/settings/seo'), false);
    assert.equal(c.open, true);
    assert.equal(c.section, true);
    assert.equal(document.activeElement, document.querySelector('[data-ud="s"]'), 'the focus starts on Ở lại');
    c.stay();
    assert.equal(await navigate('/admin/posts'), false);
    assert.equal(c.open, true);
    assert.equal(c.section, false);
    assert.deepEqual(went, []);
    const e = new window.Event('beforeunload', { cancelable: true });
    window.dispatchEvent(e);
    assert.equal(e.defaultPrevented, true, 'closing the tab asks the browser’s question');
});

test('a link to the section already open does nothing', async () => {
    const { c } = page(1);
    assert.equal(await navigate('/admin/settings/general'), false);
    assert.equal(c.open, false);
    assert.deepEqual(went, []);
});

test('Ở lại, Escape and the backdrop keep the page and put the focus back', async () => {
    const { c } = page(1);
    const link = document.getElementById('link');
    link.focus();
    await navigate('/admin/posts');
    assert.equal(c.open, true);
    c.key(new window.KeyboardEvent('keydown', { key: 'Escape', cancelable: true }));
    assert.equal(c.open, false);
    assert.equal(document.activeElement, link);
    assert.deepEqual(went, []);
});

test('Tab stays inside the dialog', async () => {
    const { c } = page(1);
    await navigate('/admin/posts');
    const box = document.getElementById('box');
    const [first, , lastBtn] = box.querySelectorAll('button');
    lastBtn.focus();
    const tab = new window.KeyboardEvent('keydown', { key: 'Tab', cancelable: true });
    c.key(tab);
    assert.equal(tab.defaultPrevented, true);
    assert.equal(document.activeElement, first);
    const back = new window.KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, cancelable: true });
    c.key(back);
    assert.equal(document.activeElement, lastBtn);
    // focus fallen to the page (a click on the dialog's text): Tab comes back into the dialog
    document.body.focus();
    document.activeElement.blur();
    c.key(new window.KeyboardEvent('keydown', { key: 'Tab', cancelable: true }));
    assert.equal(document.activeElement, first);
    // a closed dialog leaves keys alone
    c.stay();
    const esc = new window.KeyboardEvent('keydown', { key: 'Escape', cancelable: true });
    c.key(esc);
    assert.equal(esc.defaultPrevented, false);
});

test('"Lưu và …" saves, then goes on once nothing is unsaved', async () => {
    const { c, calls } = page(1);
    await navigate('/admin/settings/seo');
    await c.saveGo();
    assert.deepEqual(calls, ['save']);
    assert.equal(c.open, false);
    assert.deepEqual(went, ['https://a.test/admin/settings/seo']);
});

test('a save that fails stays on the page with its errors and the typed text', async () => {
    const { c, calls } = page(1, {
        save: () => document.getElementById('f').setAttribute('aria-invalid', 'true'),
    });
    const field = document.getElementById('f');
    field.value = 'typed';
    await navigate('/admin/posts');
    await c.saveGo();
    await frame();
    assert.deepEqual(calls, ['save']);
    assert.equal(c.open, false);
    assert.deepEqual(went, []);
    assert.equal(field.value, 'typed');
    // a save refused without a field in error (the settings were busy) stays too
    const busy = page(1, { save: () => {} });
    await navigate('/admin/posts');
    await busy.c.saveGo();
    assert.deepEqual(went, []);
});

test('"Bỏ thay đổi và …" drops the draft through the page, then goes', async () => {
    const { c, calls } = page(3);
    await navigate('/admin/posts');
    await c.discardGo();
    assert.deepEqual(calls, ['resetChanges']);
    assert.deepEqual(went, ['https://a.test/admin/posts']);
});

test('text typed but not yet sent counts: the draft is sent first and the count decides', async () => {
    const reverted = page(0);
    const f = document.getElementById('f');
    f.focus();
    f.dispatchEvent(new window.Event('input', { bubbles: true }));
    assert.equal(reverted.c.dirty(), true);
    const e = new window.Event('beforeunload', { cancelable: true });
    window.dispatchEvent(e);
    assert.equal(e.defaultPrevented, true);
    // the text typed matches what was saved: no question, the link goes on
    assert.equal(await navigate('/admin/posts'), false);
    await tick();
    assert.deepEqual(reverted.calls, ['$commit']);
    assert.equal(reverted.c.open, false);
    assert.deepEqual(went, ['https://a.test/admin/posts']);

    const changed = page(0, { commit: (setCount) => setCount(1) });
    const g = document.getElementById('f');
    g.focus();
    g.dispatchEvent(new window.Event('input', { bubbles: true }));
    await navigate('/admin/posts');
    await tick();
    assert.equal(changed.c.open, true);
    assert.deepEqual(went, []);
});

test('a round trip that carried the typed text clears it; one sent while the field is still being typed in does not', () => {
    const { c } = page(0);
    const f = document.getElementById('f');
    f.focus();
    f.dispatchEvent(new window.Event('input', { bubbles: true }));
    const commit = () => hooks.forEach((h) => h({ component: { id: 'c1' }, succeed: (cb) => cb() }));
    commit();
    assert.equal(c.dirty(), true, 'the .blur field still holds the focus: not sent yet');
    f.blur();
    commit();
    assert.equal(c.dirty(), false);
    // another component's round trip changes nothing
    f.focus();
    f.dispatchEvent(new window.Event('input', { bubbles: true }));
    f.blur();
    hooks.forEach((h) => h({ component: { id: 'other' }, succeed: (cb) => cb() }));
    assert.equal(c.dirty(), true);
});

test('taken off with the page: no guard is left behind', async () => {
    const { c } = page(1);
    c.destroy();
    last = null;
    assert.equal(await navigate('/admin/posts'), true);
    assert.equal(hooks.length, 0);
});

test('the buttons sit on one row only when all three fit; otherwise stacked, the main one first', () => {
    assert.equal(layoutOf(520, [150, 70, 200]), 'row');
    assert.equal(layoutOf(400, [150, 70, 200]), 'col');
    assert.equal(layoutOf(0, [150, 70, 200]), null);
    assert.deepEqual(ORDER.row, ['x', 's', 'p']);
    assert.deepEqual(ORDER.col, ['p', 's', 'x']);
});
