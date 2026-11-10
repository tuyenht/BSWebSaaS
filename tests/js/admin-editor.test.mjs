// The product editor's save state (resources/js/admin-editor.js) without a browser: how changes are
// counted, which state shows over the server's one, the words of each, and the draft that saves
// itself 3 seconds after the last change while a published product waits for "Cập nhật".
// `node --test tests/js/admin-editor.test.mjs`.
import assert from 'node:assert/strict';
import { mock, test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://a.test/admin/catalog/new' });
globalThis.window = dom.window;
globalThis.document = dom.window.document;

let factory = null;
const effects = [];
window.Alpine = {
    data: (name, fn) => (name === 'bsEditorSave' ? (factory = fn) : null),
    effect: (fn) => {
        effects.push(fn);
        fn();

        return fn;
    },
    release: (fn) => effects.splice(effects.indexOf(fn), 1),
};
let intercept = null;
window.Livewire = {
    interceptMessage: (cb) => {
        intercept = cb;

        return () => {
            intercept = null;
        };
    },
};
const { valueText, countChanges, overState, stateText } = await import('../../resources/js/admin-editor.js');

const words = { offlineToast: 'Chưa lưu được vì mất kết nối.', offlineToastIcon: '<svg></svg>', dirty: ':n thay đổi chưa lưu', dirtyDraft: ':n thay đổi chưa lưu — tự lưu sau 3 giây', dirtyLive: ':n thay đổi chưa lưu — bấm Cập nhật để khách thấy', saving: 'Đang lưu…', offline: 'Chưa lưu được — mất kết nối' };
const rerun = () => effects.slice().forEach((fn) => fn());

// An editor whose server state is `wire` (values, saved, status); returns the part and its calls.
function editor(wire) {
    document.body.innerHTML = '<div wire:id="c1"><div id="bar"></div><input id="f"></div>';
    const calls = [];
    const state = { status: 'draft', saveFailed: false, saved: { name: '', summary: '' }, values: { name: '', summary: '' }, ...wire };
    const c = factory({ fields: ['name', 'summary'], words });
    c.$root = document.getElementById('bar');
    c.$wire = {
        $id: 'c1',
        $get: (f) => state.values[f],
        get saved() {
            return state.saved;
        },
        get status() {
            return state.status;
        },
        get saveFailed() {
            return state.saveFailed;
        },
        autosave: async () => calls.push('autosave'),
        save: async () => calls.push('save'),
        publish: async () => calls.push('publish'),
    };
    c.init();

    return { c, calls, state };
}

// One round trip of this component carrying `action`; `end` is 'success' | 'failure'.
function trip(action, end, id = 'c1') {
    const cb = { success: [], failure: [], error: [], finish: [] };
    intercept({
        message: { component: { id }, actions: new Set([{ name: action }]) },
        onSuccess: (f) => cb.success.push(f),
        onFailure: (f) => cb.failure.push(f),
        onError: (f) => cb.error.push(f),
        onFinish: (f) => cb.finish.push(f),
    });

    return () => {
        cb[end].forEach((f) => f());
        cb.finish.forEach((f) => f());
    };
}

test('values are compared the way the server writes them', () => {
    assert.equal(valueText(null), '');
    assert.equal(valueText(undefined), '');
    assert.equal(valueText(12), '12');
    assert.equal(valueText(['3', 3, '5']), '[3,5]');
    assert.equal(countChanges(['a', 'b', 'c'], (f) => ({ a: 'x', b: '', c: [1] })[f], { a: 'x', b: 'y', c: '[1]' }), 1);
});

test('saving and a lost connection show over everything; a failed save keeps its message until the next keystroke', () => {
    assert.equal(overState({ busy: true, offline: true, failed: true, typed: true, n: 2 }), 'saving');
    assert.equal(overState({ busy: false, offline: true, failed: false, typed: false, n: 0 }), 'offline');
    assert.equal(overState({ busy: false, offline: false, failed: true, typed: false, n: 2 }), null);
    assert.equal(overState({ busy: false, offline: false, failed: true, typed: true, n: 2 }), 'dirty');
    assert.equal(overState({ busy: false, offline: false, failed: false, typed: false, n: 0 }), null);
});

test('the changes line says what happens next for each status', () => {
    assert.equal(stateText('dirty', words, 2, 'draft'), '2 thay đổi chưa lưu — tự lưu sau 3 giây');
    assert.equal(stateText('dirty', words, 1, 'published'), '1 thay đổi chưa lưu — bấm Cập nhật để khách thấy');
    assert.equal(stateText('dirty', words, 3, 'archived'), '3 thay đổi chưa lưu');
    assert.equal(stateText('saving', words, 3, 'draft'), 'Đang lưu…');
});

test('a draft saves itself 3 seconds after the last change, not before', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c, calls, state } = editor();
    assert.equal(c.state(), null);
    state.values.name = 'Đồ gá';
    rerun();
    assert.equal(c.state(), 'dirty');
    assert.equal(c.text(), '1 thay đổi chưa lưu — tự lưu sau 3 giây');
    t.mock.timers.tick(2000);
    state.values.summary = 'Ngắn';
    rerun();   // a new change starts the wait again
    t.mock.timers.tick(2000);
    assert.deepEqual(calls, []);
    t.mock.timers.tick(1000);
    assert.deepEqual(calls, ['autosave']);
    c.destroy();
});

test('a published or archived product never saves itself', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    for (const status of ['published', 'archived']) {
        const { c, calls, state } = editor({ status });
        state.values.name = 'Đồ gá mới';
        rerun();
        t.mock.timers.tick(10000);
        assert.deepEqual(calls, [], status);
        c.destroy();
    }
});

test('while a save is on its way the line reads Đang lưu…, and the draft waits for it', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c, calls, state } = editor();
    state.values.name = 'Đồ gá';
    rerun();
    const end = trip('save', 'success');
    assert.equal(c.state(), 'saving');
    t.mock.timers.tick(5000);
    assert.deepEqual(calls, [], 'the button\'s save cancels the wait');
    state.saved = { name: 'Đồ gá', summary: '' };
    end();
    rerun();
    assert.equal(c.state(), null);
    c.destroy();
});

test('a save that does not reach the server shows Thử lại, which sends the same save again', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c, calls, state } = editor();
    state.values.name = 'Đồ gá';
    rerun();
    trip('publish', 'failure')();
    assert.equal(c.state(), 'offline');
    assert.equal(c.text(), 'Chưa lưu được — mất kết nối');
    c.retry();
    assert.deepEqual(calls, ['publish']);
    trip('publish', 'success')();
    assert.notEqual(c.state(), 'offline');
    c.destroy();
});

test('other components\' round trips and other actions are ignored', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c } = editor();
    trip('save', 'failure', 'c2')();
    trip('removeImage', 'failure')();
    assert.equal(c.state(), null);
    c.destroy();
});

test('taken off with the page: no timer, effect or listener is left behind', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c, calls, state } = editor();
    state.values.name = 'Đồ gá';
    rerun();
    c.destroy();
    t.mock.timers.tick(5000);
    assert.deepEqual(calls, []);
    assert.equal(effects.length, 0);
    assert.equal(intercept, null);
});

mock.reset();

test('a save that does not reach the server also raises the shared toast with the warning icon', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const shown = [];
    window.bsToast = (o) => shown.push(o);
    const { c } = editor();
    trip('save', 'failure')();
    assert.deepEqual(shown, [{ text: 'Chưa lưu được vì mất kết nối.', icon: '<svg></svg>' }]);
    delete window.bsToast;
    c.destroy();
});

test('the offline toast is raised once when the state turns offline, not on every failed save while offline', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const shown = [];
    window.bsToast = (o) => shown.push(o);
    const { c } = editor();
    trip('save', 'failure')();
    trip('save', 'failure')();
    assert.equal(shown.length, 1);
    trip('save', 'success')();
    trip('save', 'failure')();
    assert.equal(shown.length, 2, 'a new loss after coming back raises it again');
    delete window.bsToast;
    c.destroy();
});
