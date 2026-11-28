// What the top bar's bell and the "Thông báo" page share (resources/js/admin-notice-wire.js): a
// notice's link click-through and the lost-request "Thử lại", on a stand-in Livewire $wire:
// `node --test tests/js/admin-notice-wire.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { catchLost, followNotice, tryAgain } from '../../resources/js/admin-notice-wire.js';

function part() {
    const calls = [];
    const hooks = [];
    const ctx = {
        failed: '',
        retry: null,
        $wire: {
            open: (ulid) => calls.push(['open', ulid]),
            load: () => calls.push(['load']),
            read: (...args) => calls.push(['read', ...args]),
            $intercept: (fn) => {
                hooks.push(fn);
                return () => hooks.splice(hooks.indexOf(fn), 1);
            },
        },
    };
    // One request of `name` through every interceptor, answered by `answer` (failure · error · success).
    const send = (name, params, answer) => {
        const handlers = {};
        const on = (key) => (fn) => {
            handlers[key] = fn;
        };
        hooks.forEach((fn) => fn({ action: { name, params }, onError: on('error'), onFailure: on('failure'), onSuccess: on('success') }));
        let prevented = false;
        if (answer.failure) {
            handlers.failure?.();
        } else if (answer.status) {
            handlers.error?.({ response: { status: answer.status }, preventDefault: () => (prevented = true) });
        } else {
            handlers.success?.();
        }

        return { prevented };
    };

    return { ctx, calls, send, hooks };
}

function click(over = {}) {
    let prevented = false;
    return { event: { ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, button: 0, preventDefault: () => (prevented = true), ...over }, prevented: () => prevented };
}

test('a plain click on a notice opens it through the server', () => {
    const { ctx, calls } = part();
    const c = click();
    followNotice(ctx, c.event, '01HX');
    assert.equal(c.prevented(), true);
    assert.deepEqual(calls, [['open', '01HX']]);
});

test('a modified or middle click keeps the browser\'s own tab and leaves the notice unread', () => {
    for (const over of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
        const { ctx, calls } = part();
        const c = click(over);
        followNotice(ctx, c.event, '01HX');
        assert.equal(c.prevented(), false, JSON.stringify(over));
        assert.deepEqual(calls, []);
    }
});

test('a lost request shows the error and "Thử lại" sends the same request again', () => {
    const { ctx, calls, send } = part();
    catchLost(ctx, (name) => (name === 'load' ? 'load' : name === 'read' ? 'save' : ''));

    send('read', ['01HX', true], { failure: true });
    assert.equal(ctx.failed, 'save');
    tryAgain(ctx);
    assert.equal(ctx.failed, '');
    assert.equal(ctx.retry, null);
    assert.deepEqual(calls, [['read', '01HX', true]]);

    // a server error counts as lost, and its own page is kept from showing
    const { prevented } = send('load', [], { status: 503 });
    assert.equal(prevented, true);
    assert.equal(ctx.failed, 'load');
});

test('a refusal is left to Livewire, an action the part does not watch is ignored, an answer clears the error', () => {
    const { ctx, send } = part();
    let done = 0;
    catchLost(ctx, (name) => (name === 'load' ? 'load' : ''), () => done++);

    const { prevented } = send('load', [], { status: 419 });
    assert.equal(prevented, false);
    assert.equal(ctx.failed, '');

    send('other', [], { failure: true });
    assert.equal(ctx.failed, '');

    send('load', [], { failure: true });
    assert.equal(ctx.failed, 'load');
    send('load', [], {});
    assert.equal(ctx.failed, '');
    assert.equal(ctx.retry, null);
    assert.equal(done, 1);
});

test('"Thử lại" with nothing kept runs the fallback, and the stop function unhooks', () => {
    const { ctx, calls, hooks } = part();
    tryAgain(ctx, () => ctx.$wire.load());
    assert.deepEqual(calls, [['load']]);
    tryAgain(ctx); // no request kept and no fallback: nothing happens
    assert.deepEqual(calls, [['load']]);

    const stop = catchLost(ctx, () => 'load');
    assert.equal(hooks.length, 1);
    stop();
    assert.equal(hooks.length, 0);
});
