// The sliders' index and offset arithmetic (resources/js/sf-slider-math.js): `node --test tests/js/sf-slider-math.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loopDot, loopHome, railDot, railPages, railTarget, swipe, wrap } from '../../resources/js/sf-slider-math.js';

test('wrap goes round both ways', () => {
    assert.equal(wrap(-1, 5), 4);
    assert.equal(wrap(5, 5), 0);
    assert.equal(wrap(2, 5), 2);
});

test('a rail loops: Next at the end goes to the start, Prev at the start to the end', () => {
    // 1000px of content in a 400px view: the end is scrollLeft 600.
    assert.equal(railTarget(1, 600, 400, 1000, 200), 0);
    assert.equal(railTarget(1, 599, 400, 1000, 200), 0);
    assert.equal(railTarget(-1, 0, 400, 1000, 200), 600);
    assert.equal(railTarget(-1, 1, 400, 1000, 200), 600);
    assert.equal(railTarget(1, 100, 400, 1000, 200), 300);
    assert.equal(railTarget(-1, 300, 400, 1000, 200), 100);
    assert.equal(railTarget(1, 500, 400, 1000, 200), 600);
    assert.equal(railTarget(-1, 100, 400, 1000, 200), 0);
});

test('rail dots: one per screenful, the lit one follows the scroll position', () => {
    assert.equal(railPages(400, 1000), 3);
    assert.equal(railPages(400, 400), 1);
    assert.equal(railDot(0, 400, 1000), 0);
    assert.equal(railDot(300, 400, 1000), 1);
    assert.equal(railDot(600, 400, 1000), 2);
    assert.equal(railDot(0, 400, 400), 0);
});

test('a swipe is 40px or more along x and further along x than y', () => {
    assert.equal(swipe(-60, 10), -1);
    assert.equal(swipe(60, -10), 1);
    assert.equal(swipe(39, 0), 0);
    assert.equal(swipe(50, 80), 0);
});

test('a looping rail settles back on the real cards, one copy over, the same view', () => {
    // One copy is 1000px wide: the real cards run from scrollLeft 1000 to 2000.
    assert.equal(loopHome(1000, 1000), 1000);
    assert.equal(loopHome(1500, 1000), 1500);
    assert.equal(loopHome(1999.5, 1000), 999.5);
    assert.equal(loopHome(2000, 1000), 1000);
    assert.equal(loopHome(2250, 1000), 1250);
    assert.equal(loopHome(999.5, 1000), 999.5);
    assert.equal(loopHome(750, 1000), 1750);
    assert.equal(loopHome(0, 1000), 1000);
    // Not measured yet: nothing moves.
    assert.equal(loopHome(300, 0), 300);
});

test('a looping rail lights the dot of its place within the real cards', () => {
    // 976px of cards (+24px gap = one 1000px copy) in a 400px view: three dots.
    assert.equal(loopDot(1000, 1000, 400, 976), 0);
    assert.equal(loopDot(1576, 1000, 400, 976), 2);
    assert.equal(loopDot(1900, 1000, 400, 976), 2);
    assert.equal(loopDot(2000, 1000, 400, 976), 0);
    // A copy 1000.4px wide whose start the browser rounds to 1000: still the first dot.
    assert.equal(loopDot(1000, 1000.4, 400, 976), 0);
    assert.equal(loopDot(1300, 1000, 400, 976), 1);
    // On the copies before the real cards: the same dot as the same view on the real cards.
    assert.equal(loopDot(300, 1000, 400, 976), 1);
    assert.equal(loopDot(500, 0, 400, 976), 0);
});
