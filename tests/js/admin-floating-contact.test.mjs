// The "Nút liên hệ nổi" screen's script (resources/js/admin-floating-contact.js) in a DOM without a
// browser: the browser's own check of a channel's icon picture (PNG or WebP, at most 50 KB), what each
// answer of the library's upload becomes (its own reason when the page words it), the hand-over to the page, and when the preview may open
// the dial or the "Thêm" sheet again after a redraw (only when it still shows a channel).
// (resources/js/admin-floating-contact-icon.js holds the picture's part, loaded on first use.)
// `node --test tests/js/admin-floating-contact.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><head><meta name="csrf-token" content="tok-1"></head><body></body></html>', { url: 'https://a.test/admin/settings/floating-contact' });
globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.FormData = dom.window.FormData;
globalThis.Blob = dom.window.Blob;
globalThis.File = dom.window.File;

const { iconRefusal, uploadIcon, pickIcon, ICON_MAX_BYTES } = await import('../../resources/js/admin-floating-contact-icon.js');
const screen = await import('../../resources/js/admin-floating-contact.js');
const { reopenable } = screen;

const file = (type, size, name = 'logo') => new File([new Uint8Array(size)], name, { type });
const reply = (status, body) => async () => ({ status, ok: status >= 200 && status < 300, json: async () => body });

test('lets a PNG or a WebP of at most 50 KB go, and names why anything else is refused', () => {
    assert.equal(ICON_MAX_BYTES, 51200);
    assert.equal(iconRefusal(file('image/png', 51200)), null);
    assert.equal(iconRefusal(file('image/webp', 10)), null);
    assert.equal(iconRefusal(file('image/png', 51201)), 'size');
    for (const type of ['image/jpeg', 'image/gif', 'image/svg+xml', 'application/x-php']) {
        assert.equal(iconRefusal(file(type, 10)), 'type', type);
    }
    assert.equal(iconRefusal(null), null);
});

test('lets a file the browser cannot type go up, so the library sniffs its content, but still keeps the size', () => {
    assert.equal(iconRefusal(file('', 10, 'logo')), null);
    assert.equal(iconRefusal(file('', 51201, 'logo')), 'size');
});

test('posts the file to the library with the page token and reads the picture back, or why not', async () => {
    let sent = null;
    const ok = await uploadIcon(file('image/png', 10, 'a.png'), '/admin/media/upload', {
        token: 'tok-1',
        fetchFn: async (url, init) => {
            sent = [url, init];
            return { status: 200, ok: true, json: async () => ({ ulid: '01JC0000000000000000000000' }) };
        },
    });
    assert.deepEqual(ok, { ulid: '01JC0000000000000000000000' });
    assert.equal(sent[0], '/admin/media/upload');
    assert.equal(sent[1].method, 'POST');
    assert.equal(sent[1].headers['X-CSRF-TOKEN'], 'tok-1');
    assert.equal(sent[1].body.get('file').name, 'a.png');

    const f = file('image/png', 10);
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: reply(403, {}) }), { error: 'denied' });
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: reply(401, {}) }), { error: 'denied' });
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: reply(422, { message: 'x' }) }), { error: 'rejected' });
    // The library's own reason travels when the page has its own sentence for it; any other stays 'rejected'.
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: reply(422, { message: 'x', reason: 'in_trash' }) }), { error: 'in_trash' });
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: reply(422, { message: 'x', reason: 'unsupported_type' }) }), { error: 'unsupported_type' });
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: reply(422, { message: 'x', reason: 'storage_failed' }) }), { error: 'rejected' });
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: reply(422, { message: 'x', reason: ['in_trash'] }) }), { error: 'rejected' });
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: async () => ({ status: 422, ok: false, json: async () => { throw new SyntaxError('html'); } }) }), { error: 'rejected' });
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: reply(500, {}) }), { error: 'upload' });
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: reply(200, { ulid: '' }) }), { error: 'upload' });
    assert.deepEqual(await uploadIcon(f, '/u', { token: 't', fetchFn: async () => { throw new TypeError('offline'); } }), { error: 'upload' });
});

test('hands the uploaded picture to the page, or the reason to show, and is busy only meanwhile', async () => {
    const calls = [];
    const wire = { useIcon: async (...a) => calls.push(['useIcon', ...a]), iconRefused: async (...a) => calls.push(['iconRefused', ...a]) };
    const input = (f) => ({ target: { files: [f], value: 'C:\\fakepath\\logo.png' } });
    const state = { busy: false };
    let busyDuring = null;
    const deps = { token: 't', fetchFn: async () => { busyDuring = state.busy; return { status: 200, ok: true, json: async () => ({ ulid: 'U1' }) }; } };

    const ev = input(file('image/png', 10));
    await pickIcon(ev, wire, 'zalo', '/u', state, deps);
    assert.deepEqual(calls.at(-1), ['useIcon', 'zalo', 'U1']);
    assert.equal(busyDuring, true);
    assert.equal(state.busy, false);
    assert.equal(ev.target.value, ''); // the same file can be picked again

    let fetched = false;
    await pickIcon(input(file('image/jpeg', 10)), wire, 'zalo', '/u', state, { token: 't', fetchFn: async () => { fetched = true; } });
    assert.deepEqual(calls.at(-1), ['iconRefused', 'type']);
    await pickIcon(input(file('image/webp', 60000)), wire, 'zalo', '/u', state, { token: 't', fetchFn: async () => { fetched = true; } });
    assert.deepEqual(calls.at(-1), ['iconRefused', 'size']);
    assert.equal(fetched, false); // a refused file never reaches the library

    await pickIcon(input(file('image/png', 10)), wire, 'zalo', '/u', state, { token: 't', fetchFn: reply(422, {}) });
    assert.deepEqual(calls.at(-1), ['iconRefused', 'rejected']);
    await pickIcon(input(file('image/png', 10)), wire, 'zalo', '/u', state, { token: 't', fetchFn: reply(422, { reason: 'in_trash' }) });
    assert.deepEqual(calls.at(-1), ['iconRefused', 'in_trash']);
    assert.equal(state.busy, false);

    const n = calls.length;
    await pickIcon(input(file('image/png', 10)), wire, 'zalo', '/u', { busy: true }, deps); // a second pick while one uploads
    assert.equal(calls.length, n);
});

test('opens the "Thêm" sheet again after a redraw only when it still has a channel to show', () => {
    const frame = new JSDOM(`<!doctype html><html><head><style>.sf-fc-sheet__cell--fits { display: none; }</style></head><body>
        <ul id="empty"></ul>
        <ul id="all-on-bar"><li class="sf-fc-sheet__cell--fits">Gọi</li><li class="sf-fc-sheet__cell--fits">Zalo</li></ul>
        <ul id="one-left"><li class="sf-fc-sheet__cell--fits">Gọi</li><li>Email</li></ul>
    </body></html>`);
    const d = frame.window.document;
    assert.equal(reopenable(d.getElementById('empty'), frame.window), false);
    assert.equal(reopenable(d.getElementById('all-on-bar'), frame.window), false);
    assert.equal(reopenable(d.getElementById('one-left'), frame.window), true);
    assert.equal(reopenable(null, frame.window), false);
    assert.equal(reopenable(d.getElementById('one-left'), null), false);
});

test('the screen loads the picture code on first use and passes the pick through', async () => {
    assert.equal(window.bsFloatingContact.pickIcon, screen.pickIcon);
    const calls = [];
    const wire = { useIcon: async (...a) => calls.push(['useIcon', ...a]), iconRefused: async (...a) => calls.push(['iconRefused', ...a]) };
    await screen.pickIcon({ target: { files: [file('image/gif', 10)], value: 'x' } }, wire, 'zalo', '/u', { busy: false });
    assert.deepEqual(calls, [['iconRefused', 'type']]);
});
