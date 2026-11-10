// The post contents marker (resources/js/sf-toc.js): the server prints the side column's contents as
// plain links; the script marks the entry being read with aria-current, in a DOM without a browser:
// `node --test tests/js/sf-toc.test.mjs`. The headings' positions and the observer are stand-ins.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;

const html = `<nav class="post-toc"><ol>
    <li><a class="post-toc__link" href="#muc-a">A</a></li>
    <li><a class="post-toc__link" href="#muc-b">B</a></li>
    <li><a class="post-toc__link" href="#muc-c">C</a></li>
</ol></nav>
<details class="post-toc-phone"><ol><li><a class="post-toc__link" href="#muc-a">A</a></li></ol></details>
<h2 id="muc-a">A</h2><h2 id="muc-b">B</h2><h2 id="muc-c">C</h2>`;

// The page with each heading's top (px from the window's top), and a start() that runs a fresh copy
// of the script; `fire()` plays the observer's callback, as a heading crossing the line would.
function page(tops, { observer = true, body = html } = {}) {
    const dom = new JSDOM(`<!doctype html><body>${body}</body>`);
    const win = dom.window;
    const doc = win.document;
    Object.defineProperty(win, 'innerHeight', { value: 900, configurable: true });
    Object.defineProperty(doc.documentElement, 'scrollHeight', { value: 5000, configurable: true });
    for (const [id, top] of Object.entries(tops)) {
        doc.getElementById(id).getBoundingClientRect = () => ({ top: tops[id] ?? top });
    }
    const seen = { options: null, observed: [], callback: null };
    class Observer {
        constructor(callback, options) {
            seen.callback = callback;
            seen.options = options;
        }
        observe(el) {
            seen.observed.push(el.id);
        }
    }
    if (observer) {
        win.IntersectionObserver = Observer;
        globalThis.IntersectionObserver = Observer;
    } else {
        delete globalThis.IntersectionObserver;
    }
    return {
        doc,
        seen,
        tops,
        current: () => [...doc.querySelectorAll('nav [aria-current]')].map((a) => a.hash),
        start: async () => {
            globalThis.window = win;
            globalThis.document = doc;
            await import(`../../resources/js/sf-toc.js?run=${++load}`);
        },
    };
}

test('the first entry is marked until a heading passes the line a third down the window', async () => {
    const p = page({ 'muc-a': 600, 'muc-b': 1500, 'muc-c': 2400 });
    await p.start();
    assert.deepEqual(p.current(), ['#muc-a']);
    assert.deepEqual(p.seen.observed, ['muc-a', 'muc-b', 'muc-c']);
    assert.equal(p.seen.options.rootMargin, '0px 0px -67% 0px');
});

test('the entry is the last heading above the line, and only one is marked', async () => {
    const p = page({ 'muc-a': -900, 'muc-b': 100, 'muc-c': 1000 });
    await p.start();
    assert.deepEqual(p.current(), ['#muc-b']);

    // scrolling on: C crosses the line
    p.tops['muc-b'] = -400;
    p.tops['muc-c'] = 250;
    p.seen.callback([]);
    assert.deepEqual(p.current(), ['#muc-c']);

    // back up: B is the one being read again
    p.tops['muc-b'] = 200;
    p.tops['muc-c'] = 1100;
    p.seen.callback([]);
    assert.deepEqual(p.current(), ['#muc-b']);
});

test('the phone list is left as plain links', async () => {
    const p = page({ 'muc-a': 0, 'muc-b': 1000, 'muc-c': 2000 });
    await p.start();
    assert.equal(p.doc.querySelector('.post-toc-phone [aria-current]'), null);
});

test('without IntersectionObserver nothing is marked and the links stay plain', async () => {
    const p = page({ 'muc-a': 0, 'muc-b': 1000, 'muc-c': 2000 }, { observer: false });
    await p.start();
    assert.deepEqual(p.current(), []);
});

test('the last entry is marked once the page is scrolled to its end, even below the line', async () => {
    const p = page({ 'muc-a': -2000, 'muc-b': 200, 'muc-c': 700 });
    await p.start();
    assert.deepEqual(p.current(), ['#muc-b']);
    Object.defineProperty(p.doc.defaultView, 'scrollY', { value: 4100, configurable: true });
    p.seen.callback([]);
    assert.deepEqual(p.current(), ['#muc-c']);
});

// A product's section bar: one row of pills that slides sideways on a narrow screen. The marked pill is
// brought into the row's view by sliding the row itself; a pill already in view leaves the row still.
const bar = `<nav class="product-toc"><ul class="product-toc__list">
    <li><a class="product-toc__link" href="#muc-a">A</a></li>
    <li><a class="product-toc__link" href="#muc-b">B</a></li>
    <li><a class="product-toc__link" href="#muc-c">C</a></li>
</ul></nav>
<h2 id="muc-a">A</h2><h2 id="muc-b">B</h2><h2 id="muc-c">C</h2>`;

test('the product bar marks the pill being read and slides it into the row when it is out of view', async () => {
    const p = page({ 'muc-a': 600, 'muc-b': 1500, 'muc-c': 2400 }, { body: bar });
    const strip = p.doc.querySelector('.product-toc__list');
    strip.getBoundingClientRect = () => ({ left: 0, right: 300, width: 300 });
    const pills = [...strip.querySelectorAll('a')];
    pills.forEach((a, i) => {
        a.getBoundingClientRect = () => ({ left: i * 200 - strip.scrollLeft, right: i * 200 + 150 - strip.scrollLeft, width: 150 });
    });
    await p.start();
    assert.deepEqual(p.current(), ['#muc-a']);
    assert.equal(strip.scrollLeft, 0);

    // C passes the line: its pill (400-550) is past the row's right edge, so the row slides to centre it
    p.tops['muc-a'] = -2000;
    p.tops['muc-b'] = -1000;
    p.tops['muc-c'] = 100;
    p.seen.callback([]);
    assert.deepEqual(p.current(), ['#muc-c']);
    assert.equal(strip.scrollLeft, 325);
});
