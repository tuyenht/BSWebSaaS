// The «Đăng nhập nhanh» key form (resources/js/admin-social.js) without a browser: the keys' shapes
// as the board checks them, the quick paste (Google's JSON file, Facebook's passage), and what the
// form does with the server's answers. `node --test tests/js/admin-social.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.window = {};
const { badShape, findKeys, socialKeys } = await import('../../resources/js/admin-social.js');

const GOOGLE_ID = '812734-abcxyz.apps.googleusercontent.com';
const GOOGLE_SECRET = 'GOCSPX-9f8e7d6c5b4a3210';
const FB_ID = '1234567890123456';
const FB_SECRET = '0123456789abcdef0123456789ABCDEF';

const T = {
    idInvalid: 'id shape', secretInvalid: 'secret shape', pasteBoth: 'both', pasteOne: 'only :label', pasteNone: 'none',
    idLabel: 'Client ID', secretLabel: 'Client Secret',
};

function form(over = {}, answer = { ok: true }) {
    const calls = [];
    const f = socialKeys({ provider: 'google', id: '', saved: false, rows: [], t: T, ...over });
    f.$wire = {
        check: async (...a) => (calls.push(['check', ...a]), answer),
        useOwn: async (...a) => (calls.push(['useOwn', ...a]), answer.ok),
        copied: async (...a) => calls.push(['copied', ...a]),
    };
    f.$nextTick = (fn) => fn();
    f.$dispatch = (name) => calls.push(['dispatch', name]);
    f.$refs = {};

    return { f, calls };
}

test('judges each key by its provider shape and leaves empty ones alone', () => {
    assert.deepEqual(badShape('google', GOOGLE_ID, GOOGLE_SECRET), { id: false, secret: false });
    assert.deepEqual(badShape('google', 'abc', 'xyz'), { id: true, secret: true });
    assert.deepEqual(badShape('google', '', ''), { id: false, secret: false });
    assert.deepEqual(badShape('facebook', FB_ID, FB_SECRET), { id: false, secret: false });
    assert.deepEqual(badShape('facebook', '12ab', 'short'), { id: true, secret: true });
});

test('finds both keys in Google’s downloaded JSON file and in a copied passage', () => {
    const file = JSON.stringify({ web: { client_id: GOOGLE_ID, client_secret: GOOGLE_SECRET, project_id: 'x' } });
    assert.deepEqual(findKeys('google', file), { id: GOOGLE_ID, secret: GOOGLE_SECRET });
    assert.deepEqual(findKeys('google', `Client ID ${GOOGLE_ID}\nClient secret ${GOOGLE_SECRET}`), { id: GOOGLE_ID, secret: GOOGLE_SECRET });
    assert.deepEqual(findKeys('facebook', `App ID\n${FB_ID}\nApp secret\n${FB_SECRET}`), { id: FB_ID, secret: FB_SECRET });
    assert.deepEqual(findKeys('facebook', 'nothing here'), { id: '', secret: '' });
});

test('says what the paste found, fills the fields and opens the secret field when one is saved', () => {
    const { f } = form({ saved: true });
    f.pasted(`${GOOGLE_ID}`);
    assert.equal(f.pasteMsg.text, 'only Client ID');
    assert.equal(f.id, GOOGLE_ID);
    assert.equal(f.editingSecret, false);

    f.pasted(`${GOOGLE_ID} ${GOOGLE_SECRET}`);
    assert.deepEqual(f.pasteMsg, { ok: true, text: 'both' });
    assert.equal(f.secret, GOOGLE_SECRET);
    assert.equal(f.editingSecret, true);

    f.pasted('???');
    assert.equal(f.pasteMsg.text, 'none');
    f.pasted('  ');
    assert.equal(f.pasteMsg, null);
});

test('keeps «Kiểm tra & lưu» off until both keys are there and in shape; a saved secret may stay blank', () => {
    const { f } = form();
    assert.equal(f.testDisabled, true);
    f.typedId(GOOGLE_ID);
    assert.equal(f.testDisabled, true);
    f.typedSecret('nope');
    assert.equal(f.secretError, 'secret shape');
    assert.equal(f.testDisabled, true);
    f.typedSecret(GOOGLE_SECRET);
    assert.equal(f.testDisabled, false);

    const saved = form({ id: GOOGLE_ID, saved: true }).f;
    assert.equal(saved.testDisabled, false);
});

test('sends the keys with «Kiểm tra & lưu», then forgets the secret and closes the panel', async () => {
    const { f, calls } = form({ id: GOOGLE_ID });
    f.typedSecret(GOOGLE_SECRET);
    await f.test();
    assert.deepEqual(calls[0], ['check', 'google', GOOGLE_ID, GOOGLE_SECRET]);
    assert.deepEqual(calls[1], ['dispatch', 'bs-social-saved']);
    assert.equal(f.secret, '');
    assert.equal(f.saved, true);
    assert.equal(f.testing, false);
});

test('shows the server’s refusal under the field it names until that field changes', async () => {
    const { f } = form({ id: GOOGLE_ID }, { ok: false, secret: 'refused' });
    f.$refs.secret = { focus() { this.focused = true; } };
    f.typedSecret(GOOGLE_SECRET);
    await f.test();
    assert.equal(f.secretError, 'refused');
    assert.equal(f.$refs.secret.focused, true);
    assert.equal(f.testDisabled, true);
    f.typedSecret(GOOGLE_SECRET + 'x');
    assert.equal(f.secretError, '');
});

test('«Dùng khóa riêng» sends the keys and keeps none of the secret afterwards', async () => {
    const { f, calls } = form({ provider: 'facebook' });
    f.typedId(FB_ID);
    f.typedSecret(FB_SECRET);
    await f.own();
    assert.deepEqual(calls[0], ['useOwn', 'facebook', FB_ID, FB_SECRET]);
    assert.equal(f.secret, '');
    assert.equal(f.saved, true);
});
