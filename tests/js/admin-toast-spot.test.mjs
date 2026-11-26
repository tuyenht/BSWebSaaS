// Where a toast stands while a dialog is open (resources/js/admin-toast-spot.js toastSpot): never over the
// dialog — its usual spot, above the dialog, beside it, or under it (over a drawer: stays in sight).
// `node --test tests/js/admin-toast-spot.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { toastSpot } from '../../resources/js/admin-toast-spot.js';

// a phone (390 × 844): the toast at its usual spot, 16px from the bottom, 87px tall, 12px from each side
const phone = { vw: 390, vh: 844, toast: { top: 741, bottom: 828, left: 12, right: 378 } };

test('no dialog, or a dialog clear of the toast: the usual spot', () => {
    assert.deepEqual(toastSpot({ ...phone, box: null }), { mode: 'def' });
    assert.deepEqual(toastSpot({ ...phone, box: { top: 300, bottom: 600, left: 24, right: 366 } }), { mode: 'def' });
});

test('a question sheet from the bottom (its «Ở lại» under the toast): the toast stands 8px above the sheet', () => {
    const spot = toastSpot({ ...phone, box: { top: 560, bottom: 844, left: 0, right: 390 } });
    assert.deepEqual(spot, { mode: 'up', bottom: 844 - 560 + 8 });
    // its top edge stays at least 8px below the top of the screen
    assert.ok(844 - spot.bottom - 87 >= 8);
});

test('a sheet too tall to stand above, full width: under the dialog', () => {
    assert.deepEqual(toastSpot({ ...phone, box: { top: 68, bottom: 844, left: 0, right: 390 } }), { mode: 'under' });
});

test('…unless the dialog is a working panel (a drawer): the toast stays in sight', () => {
    assert.deepEqual(toastSpot({ ...phone, box: { top: 0, bottom: 844, left: 0, right: 390 }, keep: true }), { mode: 'keep' });
});

test('a side panel on a wide screen: the toast moves into the free side, narrower when it must be', () => {
    const wide = { vw: 1366, vh: 900, toast: { top: 818, bottom: 884, left: 403, right: 963 } };
    // a 480px panel on the right: 886px free on the left, the toast keeps its 560px, centred there
    assert.deepEqual(toastSpot({ ...wide, box: { top: 0, bottom: 900, left: 886, right: 1366 } }), { mode: 'side', left: 443, width: 560 });
    // a panel on the left (right to left): the free side is on the right
    assert.deepEqual(toastSpot({ ...wide, box: { top: 0, bottom: 900, left: 0, right: 480 } }), { mode: 'side', left: 923, width: 560 });
    // only 400px free: the toast narrows to fit with 8px each side
    assert.deepEqual(toastSpot({ ...wide, vw: 900, toast: { top: 818, bottom: 884, left: 170, right: 730 }, box: { top: 0, bottom: 900, left: 400, right: 900 } }), { mode: 'side', left: 200, width: 384 });
});

test('a side less than 280px wide is no room: under', () => {
    const narrow = { vw: 700, vh: 900, toast: { top: 818, bottom: 884, left: 70, right: 630 } };
    assert.deepEqual(toastSpot({ ...narrow, box: { top: 0, bottom: 900, left: 260, right: 700 } }), { mode: 'under' });
});
