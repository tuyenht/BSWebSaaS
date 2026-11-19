// Text the entry editor keeps on this device (resources/js/admin-kept.js) without a browser: one
// record per key with its time, a week's limit, a full store that says so, and the time shown.
// `node --test tests/js/admin-kept.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://a.test/admin/entries/x/edit' });
globalThis.window = dom.window;
globalThis.document = dom.window.document;

const kept = await import('../../resources/js/admin-kept.js');
const store = window.localStorage;

test('a record is written under its own key and read back with its time', () => {
    store.clear();
    const rec = kept.write('bs-entry-kept:m1:e1:vi', { title: 'Mới' });
    assert.ok(rec);
    assert.deepEqual(kept.read('bs-entry-kept:m1:e1:vi').values, { title: 'Mới' });
    assert.equal(kept.read('bs-entry-kept:m2:e1:vi'), null);
});

test('records older than a week go, newer ones and other keys stay', () => {
    store.clear();
    const old = new Date(Date.now() - 8 * 24 * 3600 * 1000).toISOString();
    store.setItem('bs-entry-kept:m1:e1:vi', JSON.stringify({ at: old, values: {} }));
    store.setItem('bs-entry-kept:m1:e2:vi', JSON.stringify({ at: new Date().toISOString(), values: {} }));
    store.setItem('bs-entry-kept:m1:e3:vi', 'not json');
    store.setItem('other', 'x');
    kept.prune();
    assert.equal(store.getItem('bs-entry-kept:m1:e1:vi'), null);
    assert.notEqual(store.getItem('bs-entry-kept:m1:e2:vi'), null);
    assert.equal(store.getItem('bs-entry-kept:m1:e3:vi'), null);
    assert.equal(store.getItem('other'), 'x');
});

test('clearAll removes only the kept records', () => {
    store.clear();
    kept.write('bs-entry-kept:m1:e1:vi', {});
    kept.write('bs-entry-kept:m2:e9:en', {});
    store.setItem('bs-admin-activity', '1');
    kept.clearAll();
    assert.deepEqual(Object.keys(store).sort(), ['bs-admin-activity']);
});

test('a store that refuses the write answers null, so the caller keeps the dialog open', () => {
    const real = window.Storage.prototype.setItem;
    window.Storage.prototype.setItem = () => { throw new Error('QuotaExceededError'); };
    try {
        assert.equal(kept.write('bs-entry-kept:m1:e1:vi', { title: 'x' }), null);
    } finally {
        window.Storage.prototype.setItem = real;
    }
});

test('the time shows alone today and with the day otherwise', () => {
    const now = new Date().toISOString();
    assert.match(kept.when(now, { zone: 'Asia/Ho_Chi_Minh', format: 'dmy' }), /^\d{2}:\d{2}$/);
    assert.equal(kept.when('2026-01-02T03:04:00Z', { zone: 'Asia/Ho_Chi_Minh', format: 'dmy' }), '02/01/2026 10:04');
    assert.equal(kept.when('2026-01-02T03:04:00Z', { zone: 'Asia/Ho_Chi_Minh', format: 'iso' }), '2026-01-02 10:04');
    assert.equal(kept.when('nope', {}), '');
});
