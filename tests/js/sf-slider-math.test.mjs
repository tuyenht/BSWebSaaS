// The sliders' index and offset arithmetic (resources/js/sf-slider-math.js): `node --test tests/js/sf-slider-math.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { railDot, railPages, railTarget, swipe, wrap } from '../../resources/js/sf-slider-math.js';

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
