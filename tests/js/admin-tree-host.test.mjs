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

const texts = new Proxy({ drag: {} }, { get: (o, k) => (k in o ? o[k] : String(k)) });
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
