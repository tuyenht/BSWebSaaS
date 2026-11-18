// The shared name-column rule of table-style lists (resources/js/admin-list-fold.js) and the Alpine pass
// the posts list runs with it (foldCols in entries/index.blade.php), in a DOM without a browser:
// `node --test tests/js/admin-list-fold.test.mjs`.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { pretendToBeVisual: true });
const { window } = dom;
for (const k of ['document', 'getComputedStyle']) {
    globalThis[k] = window[k];
}
globalThis.window = window;
await import('../../resources/js/admin-list-fold.js');
const fold = window.bsListFold;

test('the name keeps 350px plus 14px per pixel of text-size boost', () => {
    assert.equal(fold.nameMin(), 350);
    assert.equal(fold.nameMin(4), 406);
});

test('nothing folds while the name has its room', () => {
    assert.deepEqual(fold.nameFold(400, [['cat', 120], ['au', 100]]), []);
});

test('the category folds before the author, and only as many as the name needs', () => {
    assert.deepEqual(fold.nameFold(300, [['cat', 120], ['au', 100]]), ['cat']);
    assert.deepEqual(fold.nameFold(200, [['cat', 120], ['au', 100]]), ['cat', 'au']);
    assert.deepEqual(fold.nameFold(100, [['cat', 120], ['au', 100]]), ['cat', 'au']);
});

test('a column that is not drawn frees nothing and is skipped', () => {
    assert.deepEqual(fold.nameFold(300, [['cat', 0], ['au', 100]]), ['au']);
});

test('a bigger text size folds sooner (the re-measure with a new boost)', () => {
    const cols = [['cat', 120], ['au', 100]];
    assert.deepEqual(fold.nameFold(360, cols, 0), []);
    assert.deepEqual(fold.nameFold(360, cols, 2), ['cat']);
});

test('measuring again after the list widens gives the columns back', () => {
    const cols = [['cat', 120], ['au', 100]];
    assert.deepEqual(fold.nameFold(180, cols), ['cat', 'au']);
    assert.deepEqual(fold.nameFold(180 + 220, cols), []);
});

test('boostPx reads the shared token in rem, 16px each', () => {
    document.documentElement.style.setProperty('--user-text-boost', '0.25');
    assert.equal(fold.boostPx(), 4);
    document.documentElement.style.removeProperty('--user-text-boost');
    assert.equal(fold.boostPx(), 0);
});

test('the posts list holds its rows unseen until its first fold, which releases them', () => {
    const src = readFileSync(new URL('../../app/Modules/Admin/resources/views/livewire/entries/index.blade.php', import.meta.url), 'utf8');
    assert.match(src, /x-ref="rows" data-fold-wait/);
    assert.match(src, /removeAttribute\('data-fold-wait'\)/);
});
