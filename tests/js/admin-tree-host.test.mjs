// The shared tree (resources/js/admin-tree.js) in host mode — the server draws the rows and the
// screen around the tree makes each change itself, maybe asking first, so its move method is async —
// in a DOM without a browser: `node --test tests/js/admin-tree-host.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>');
globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.MutationObserver = dom.window.MutationObserver;
globalThis.getComputedStyle = dom.window.getComputedStyle;

// Each tree's host component, by the element its data sits on.
const hosts = new Map();
const hostOf = (el) => {
    for (let e = el; e; e = e.parentElement) {
        if (hosts.has(e)) {
            return hosts.get(e);
        }
    }
    throw new Error('no host');
};
let factory = null;
window.Alpine = {
    data(name, fn) {
        if (name === 'bsTree') {
            factory = fn;
        }
    },
    $data: hostOf,
    // As Alpine's own: an answer that is not there at once (a promise) is not handed back.
    evaluate(el, name, extras = {}) {
        const v = hostOf(el)[name](...(extras.params || []));
        return v instanceof Promise ? undefined : v;
    },
};
await import('../../resources/js/admin-tree.js');
document.dispatchEvent(new window.Event('alpine:init'));

const texts = new Proxy({ drag: { start: 'start', child: 'child', before: 'before', after: 'after', self: 'self' } }, { get: (o, k) => (k in o ? o[k] : String(k)) });
const tick = () => new Promise((r) => setTimeout(r, 0));
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
// Waits until check() holds (the line has finished its work), or fails after `ms`.
async function until(check, ms = 3000) {
    const end = Date.now() + ms;
    while (!check()) {
        if (Date.now() > end) {
            throw new Error('timed out');
        }
        await wait(5);
    }
}

// A host element holding a tree whose grid has the rows [key, parent] in order.
function mount(rows, host) {
    document.body.innerHTML = '';
    const outer = document.createElement('div');
    const root = document.createElement('div');
    const grid = document.createElement('div');
    outer.append(root);
    root.append(grid);
    document.body.append(outer);
    const draw = (list) => {
        grid.replaceChildren(...list.map(([k, parent]) => {
            const el = document.createElement('div');
            el.setAttribute('data-tree-row', '');
            el.dataset.k = k;
            el.dataset.name = 'Mục ' + k;
            if (parent) {
                el.dataset.parent = parent;
            }
            el.dataset.lv = parent ? '2' : '1';
            el.dataset.kids = list.some((r) => r[1] === k) ? '1' : '0';
            el.innerHTML = '<span data-tree-name tabindex="-1"></span>';
            return el;
        }));
    };
    draw(rows);
    hosts.set(outer, host);
    const tree = factory({ host: true, move: 'treeMove', texts, maxDepth: 3 });
    const span = (n) => Object.assign(document.createElement('span'), { id: n });
    Object.assign(tree, {
        $root: root,
        $el: root,
        $refs: { grid, ph: span('ph'), live: span('live'), alert: span('alert') },
        $nextTick: (fn) => Promise.resolve().then(fn),
        $wire: { $refresh: async () => {} },
    });
    tree.init();
    return { tree, grid, draw, order: () => [...grid.children].map((el) => el.dataset.k) };
}

test('a refused async move leaves the row where it was and treats nothing as done', async () => {
    const calls = [];
    const host = {
        async treeMove(k, parent, pos) {
            calls.push([k, parent, pos]);
            await wait(20);
            return 'Không chuyển được: chuyên mục đã đủ cấp.';
        },
    };
    const { tree, grid, order } = mount([['a', null], ['a1', 'a'], ['b', null]], host);
    tree.collapsed = { a: true };
    tree.indent('b'); // into "a", which is folded
    await tick();
    assert.equal(tree.busy, true, 'the line waits for the host');
    await wait(60);
    assert.deepEqual(calls, [['b', 'a', 1]]);
    assert.deepEqual(order(), ['a', 'a1', 'b']);
    assert.deepEqual(tree.collapsed, { a: true }, 'a refused move opens nothing');
    assert.equal(grid.querySelector('[data-flash]'), null, 'a refused move is not flashed as done');
    assert.equal(tree.busy, false);
});

test('two quick moves run one after the other, the second from where the first left the row', async () => {
    const calls = [];
    let out = 0;
    let most = 0;
    let ctx = null;
    const host = {
        async treeMove(k, parent, pos) {
            out++;
            most = Math.max(most, out);
            calls.push([k, parent, pos]);
            await wait(25);
            // the server's redraw: the row at its new place among its group
            const rest = ctx.order().filter((x) => x !== k);
            rest.splice(pos, 0, k);
            ctx.draw(rest.map((x) => [x, null]));
            await tick();
            out--;
            return null;
        },
    };
    ctx = mount([['a', null], ['b', null], ['c', null]], host);
    ctx.tree.moveDown('a');
    ctx.tree.moveDown('a');
    await until(() => calls.length === 2 && !ctx.tree.busy);
    assert.equal(most, 1, 'never two calls out at once');
    assert.deepEqual(calls, [['a', null, 1], ['a', null, 2]]);
    assert.deepEqual(ctx.order(), ['b', 'c', 'a']);
});

test('undo goes to the host and waits for it too', async () => {
    const seen = [];
    const host = {
        async treeMove() {
            seen.push('move');
            await wait(20);
            seen.push('moved');
            return null;
        },
        async undo() {
            seen.push('undo');
            await wait(5);
        },
    };
    const { tree } = mount([['a', null], ['b', null]], host);
    tree.moveDown('a');
    tree.undo();
    await wait(80);
    assert.deepEqual(seen, ['move', 'moved', 'undo']);
});

// ---- dragging: the insertion line of a gap, the highlight of a row, no element between the rows ----
// Every row is 40px high and 400px wide, stacked from the top; a name starts 24px into its row.
function stack(grid, { nb = 24 } = {}) {
    [...grid.children].forEach((el, i) => {
        const left = 10;
        el.getBoundingClientRect = () => ({ left, right: left + 400, top: i * 40, bottom: i * 40 + 40, width: 400, height: 40 });
        const tg = document.createElement('span');
        tg.className = 'tree-tg0';
        tg.getBoundingClientRect = () => ({ left: left + nb, right: left + nb + 20, top: i * 40, bottom: i * 40 + 40, width: 20, height: 40 });
        el.prepend(tg);
    });
}
const ev = (el, x, yFrac) => {
    const i = [...el.parentElement.children].indexOf(el);
    return { target: el, clientX: x, clientY: i * 40 + 40 * yFrac, preventDefault() {}, dataTransfer: { setData() {}, effectAllowed: '', dropEffect: '' } };
};
// The pointer moves: the tree's own handler, then the document's (which draws the line).
const hover = (tree, el, x, yFrac) => {
    tree.dragOver(ev(el, x, yFrac));
    document.dispatchEvent(Object.assign(new window.Event('dragover'), { clientX: x, clientY: 0 }));
};
const lineEl = () => document.querySelector('.tree-il');
const lineAt = () => {
    const m = /translate\((-?\d+)px,\s*(-?\d+)px\)/.exec(lineEl().style.transform) || [0, NaN, NaN];
    return { x: Number(m[1]), y: Number(m[2]), on: lineEl().getAttribute('data-on') === '1', bad: lineEl().getAttribute('data-bad') === '1' };
};
const rowOf = (grid, k) => grid.querySelector(`[data-k="${k}"]`);

test('a gap shows a thin line indented to the level the drop takes, and no element enters the list', () => {
    const { tree, grid } = mount([['a', null], ['a1', 'a'], ['b', null], ['c', null]], { async treeMove() { return null; } });
    stack(grid);
    const before = [...grid.children];
    tree.dragStart(ev(rowOf(grid, 'c'), 0, 0.5));
    hover(tree, rowOf(grid, 'a1'), 300, 0.9); // the gap under "a1": its own level (2) by default
    assert.deepEqual([...grid.children], before, 'the rows are the same elements in the same order');
    assert.equal(grid.querySelectorAll('.tree-ph').length, 0, 'no placeholder in the list');
    assert.equal(grid.querySelectorAll('[data-tree-ph-text]').length, 0);
    let l = lineAt();
    assert.equal(l.on, true);
    assert.equal(l.y, 80, 'at the bottom edge of "a1"');
    assert.equal(l.x, 10 + 24 + 32, 'one step in: level 2, from the dragged row (level 1) name');
    assert.equal(l.bad, false);
    hover(tree, rowOf(grid, 'a1'), 300 - 32, 0.9); // one step to the left in the same gap: level 1
    l = lineAt();
    assert.equal(l.x, 10 + 24, 'back to the outer level');
    assert.equal(l.y, 80);
    hover(tree, rowOf(grid, 'a'), 300, 0.1); // the gap above "a": the line at its top edge
    assert.equal(lineAt().y, 0);
    tree.dragCancel();
    assert.equal(lineAt().on, false, 'the line is gone when the drag ends');
});

test('a refused drop turns red with its label, in a row and in a gap', () => {
    const { tree, grid } = mount([['a', null], ['a1', 'a'], ['a2', 'a1'], ['c', null], ['c1', 'c']], { async treeMove() { return null; } });
    rowOf(grid, 'a2').dataset.lv = '3';
    stack(grid);
    tree.dragStart(ev(rowOf(grid, 'c'), 0, 0.5));
    hover(tree, rowOf(grid, 'a'), 100, 0.5);
    assert.equal(rowOf(grid, 'a').getAttribute('data-drop'), 'in', '"c" with its child fits under "a" (3 levels)');
    hover(tree, rowOf(grid, 'a2'), 100, 0.5);
    assert.equal(rowOf(grid, 'a').hasAttribute('data-drop'), false, 'only one row is highlighted');
    assert.equal(rowOf(grid, 'a2').getAttribute('data-drop'), 'bad');
    assert.equal(lineAt().on, false);
    assert.equal(document.querySelector('.tree-dl').getAttribute('data-bad'), '1');
    assert.equal(document.querySelector('.tree-dl').textContent, 'tooDeep');
    hover(tree, rowOf(grid, 'a2'), 100, 0.9); // the gap under "a2" at level 3: refused too
    const l = lineAt();
    assert.equal(l.on, true);
    assert.equal(l.bad, true, 'a refused gap is red');
    assert.equal(rowOf(grid, 'a2').hasAttribute('data-drop'), false);
    assert.equal(document.querySelector('.tree-dl').textContent, 'tooDeep');
    tree.dragCancel();
    assert.equal(rowOf(grid, 'c').hasAttribute('data-drag'), false);
});

test('the middle of an allowed row highlights it and draws no line', () => {
    const { tree, grid } = mount([['a', null], ['b', null], ['c', null]], { async treeMove() { return null; } });
    stack(grid);
    tree.dragStart(ev(rowOf(grid, 'c'), 0, 0.5));
    hover(tree, rowOf(grid, 'a'), 100, 0.5);
    assert.equal(rowOf(grid, 'a').getAttribute('data-drop'), 'in');
    assert.equal(lineAt().on, false, 'no line for a drop into a row');
    tree.dragCancel();
    assert.equal(rowOf(grid, 'a').hasAttribute('data-drop'), false);
});

test('a drop sends the same parent and place as before, and clears the line and the highlight', async () => {
    const moves = [];
    const { tree, grid } = mount([['a', null], ['a1', 'a'], ['b', null], ['c', null]], { async treeMove(k, p, pos) { moves.push([k, p, pos]); return null; } });
    stack(grid);
    tree.dragStart(ev(rowOf(grid, 'c'), 0, 0.5));
    hover(tree, rowOf(grid, 'a'), 100, 0.5); // into "a": its first child
    tree.dragDone();
    await until(() => moves.length === 1 && !tree.busy);
    assert.deepEqual(moves[0], ['c', 'a', 0]);
    assert.equal(rowOf(grid, 'a').hasAttribute('data-drop'), false);
    assert.equal(lineAt().on, false);
    tree.dragStart(ev(rowOf(grid, 'c'), 0, 0.5));
    hover(tree, rowOf(grid, 'a1'), 300, 0.9); // the gap under "a1" at level 2: after "a1" inside "a"
    tree.dragDone();
    await until(() => moves.length === 2 && !tree.busy);
    assert.deepEqual(moves[1], ['c', 'a', 1]);
    tree.dragStart(ev(rowOf(grid, 'c'), 0, 0.5));
    hover(tree, rowOf(grid, 'a1'), 300, 0.9);
    hover(tree, rowOf(grid, 'a1'), 300 - 32, 0.9); // one step left: after "a" at the outer level
    tree.dragDone();
    await until(() => moves.length === 3 && !tree.busy);
    assert.deepEqual(moves[2], ['c', null, 1]);
});

test('Alt+arrow still moves a row and Ctrl+Z still undoes', async () => {
    const seen = [];
    const { tree, grid } = mount([['a', null], ['b', null]], {
        async treeMove(k, p, pos) { seen.push(['move', k, p, pos]); return null; },
        async undo() { seen.push(['undo']); },
    });
    stack(grid);
    const a = rowOf(grid, 'a');
    const key = (extra) => ({ target: a, preventDefault() {}, ...extra });
    tree.keydown(key({ key: 'ArrowDown', altKey: true }));
    await until(() => seen.length === 1 && !tree.busy);
    assert.deepEqual(seen[0], ['move', 'a', null, 1]);
    tree.keydown(key({ key: 'z', ctrlKey: true }));
    await until(() => seen.length === 2 && !tree.busy);
    assert.deepEqual(seen[1], ['undo']);
});
