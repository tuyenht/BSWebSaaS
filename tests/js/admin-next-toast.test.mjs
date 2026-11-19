// Leaving an editor whose record is gone (resources/js/admin-next-toast.js leaveTo / takeNextToast): the
// device's copy is forgotten, the guard is let go, the toast waits for the next screen and is shown once.
// `node --test tests/js/admin-next-toast.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';
import { leaveTo, takeNextToast } from '../../resources/js/admin-next-toast.js';

function browser(storage = true) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://a.test/admin/content/01J/edit' });
    const win = dom.window;
    const seen = [];
    const went = [];
    win.addEventListener('admin-editor-saved', () => seen.push('saved'));
    win.addEventListener('admin-editor-leave', () => seen.push('leave'));
    win.Livewire = { navigate: (url) => went.push({ url, seen: [...seen] }) };
    if (!storage) {
        Object.defineProperty(win, 'sessionStorage', { get() { throw new Error('blocked'); } });
    }

    return { win, seen, went };
}

test('leaves for the list after forgetting the kept copy and letting the guard go; the toast waits for the next screen', () => {
    const { win, went } = browser();
    const shown = [];
    leaveTo('/admin/content?section=posts', { text: 'Đã chuyển “A” vào Thùng rác' }, { win, show: (t) => shown.push(t) });
    assert.deepEqual(went, [{ url: '/admin/content?section=posts', seen: ['saved', 'leave'] }]);
    assert.deepEqual(shown, []);
    assert.deepEqual(takeNextToast(win.sessionStorage), { text: 'Đã chuyển “A” vào Thùng rác' });
    assert.equal(takeNextToast(win.sessionStorage), null, 'shown once');
});

test('where the browser keeps nothing, the toast shows at once and the leave still happens', () => {
    const { win, went } = browser(false);
    const shown = [];
    leaveTo('/admin/catalog', { text: 'Đã chuyển' }, { win, show: (t) => shown.push(t) });
    assert.deepEqual(shown, [{ text: 'Đã chuyển' }]);
    assert.equal(went.length, 1);
});

test('a stored value that is not a toast is dropped', () => {
    const { win } = browser();
    win.sessionStorage.setItem('bs-toast-next', '{not json');
    assert.equal(takeNextToast(win.sessionStorage), null);
    win.sessionStorage.setItem('bs-toast-next', JSON.stringify({ text: 5 }));
    assert.equal(takeNextToast(win.sessionStorage), null);
    assert.equal(win.sessionStorage.getItem('bs-toast-next'), null);
});
