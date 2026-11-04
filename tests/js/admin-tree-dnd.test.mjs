// The shared admin tree's drag rule and position maths (resources/js/admin-tree-dnd.js), without a
// browser: `node --test tests/js/admin-tree-dnd.test.mjs`. The moves and drops come from tests/Fixtures/tree-moves.json, the
// same file AdminTreeListTest feeds to the server, so both sides agree on every position.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { cleanRows, dropPosition, fill, gapTarget, heightOf, kidsOf, planMove, zoneOf } from '../../resources/js/admin-tree-dnd.js';

const fx = JSON.parse(readFileSync(new URL('../Fixtures/tree-moves.json', import.meta.url), 'utf8'));
const rows = fx.rows;
const at = (R, k) => R.findIndex((r) => r.k === k);

test('a keyboard or menu move works out the parent and the place the server is sent', () => {
    for (const m of fx.moves) {
        const plan = planMove(m.act, rows, m.k);
        assert.deepEqual({ parent: plan.parent, pos: plan.pos }, { parent: m.parent, pos: m.pos }, `${m.act} ${m.k}`);
    }
});

test('a move with nowhere to go names the reason instead', () => {
    for (const m of fx.refused) {
        assert.equal(planMove(m.act, rows, m.k).no, m.no, `${m.act} ${m.k}`);
    }
    assert.equal(planMove('moveUp', rows, 'gone').no, 'gone');
});

test('a drop lands at the place among the new siblings, the dragged record left out', () => {
    for (const d of fx.drops) {
        assert.equal(dropPosition(d.target, kidsOf(rows, d.target.parent, d.k)), d.pos, `${d.k} ${d.target.rel} ${d.target.refK}`);
    }
});

test('the middle half of a row is its name, the top and bottom quarters belong to the gaps', () => {
    assert.deepEqual([0, 0.1, 0.25, 0.5, 0.75, 0.9, 1].map(zoneOf), ['above', 'above', 'in', 'in', 'in', 'below', 'below']);
});

test('dragging a record leaves it and everything under it out of the drop places', () => {
    const { R, sub } = cleanRows(rows, 'san-pham');
    assert.deepEqual(Object.keys(sub), ['san-pham', 'gia-cong-cnc', 'phay-5-truc', 'tien-cnc', 'khuon-mau']);
    assert.deepEqual(R.map((r) => r.k), ['trang-chu', 'gioi-thieu', 'lich-su', 'doi-ngu', 'tin-tuc', 'lien-he']);
});

test('in a gap the level is the row above by default, one less per step left, bounded by the row below', () => {
    const { R } = cleanRows(rows, 'lien-he');
    const g = at(R, 'tien-cnc'); // the gap between Tiện CNC (level 3) and Khuôn mẫu (level 2)
    const L = (dx) => gapTarget(R, g, 300, 300 + dx, 32);
    assert.deepEqual([L(0).lv, L(0).rel, L(0).afterK, L(0).parent], [3, 'after', 'tien-cnc', 'gia-cong-cnc']);
    assert.deepEqual([L(-32).lv, L(-32).afterK, L(-32).parent], [2, 'gia-cong-cnc', 'san-pham']);
    assert.equal(L(-31).lv, 3, 'less than a whole step stays');
    assert.equal(L(-200).lv, 2, 'never below the row under the gap');
    assert.equal(L(+200).lv, 3, 'never past the row above');

    const k = at(R, 'khuon-mau'); // Khuôn mẫu (2) above Tin tức (1)
    const out = gapTarget(R, k, 300, 268, 32);
    assert.deepEqual([out.lv, out.afterK, out.parent], [1, 'san-pham', null]);

    const last = gapTarget(R, R.length - 1, 300, 0, 32); // after the last row: down to the outer level
    assert.deepEqual([last.lv, last.parent], [1, null]);
});

test('the gap under an open parent makes the first child, the gap above the first row goes before it', () => {
    const { R } = cleanRows(rows, 'lien-he');
    const under = gapTarget(R, at(R, 'gioi-thieu'), 300, 0, 32);
    assert.deepEqual([under.rel, under.parent, under.lv, under.beforeK], ['child', 'gioi-thieu', 2, 'lich-su']);
    const first = gapTarget(R, -1, 300, 300, 32);
    assert.deepEqual([first.rel, first.beforeK, first.parent, first.lv], ['before', 'trang-chu', null, 1]);
});

test('the height counts the levels a record and its children take', () => {
    assert.deepEqual(['san-pham', 'gia-cong-cnc', 'lien-he', 'gone'].map((k) => heightOf(rows, k)), [3, 2, 1, 1]);
});

test('a placeholder is filled once, longest name first, so a value holding ":level" stays intact', () => {
    assert.equal(fill('«:name» · cấp :level', { name: 'Bước :level', level: 2 }), '«Bước :level» · cấp 2');
    assert.equal(fill(':names / :name', { name: 'A', names: 'B' }), 'B / A');
    assert.equal(fill('no values', {}), 'no values');
});
