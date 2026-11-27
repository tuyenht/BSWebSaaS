// The product editor's picture strip (board 09, catalog/editor.blade.php): where focus goes when the server
// says `ped-vfocus` after a video is added, taken off or put back. The strip's Alpine object and its window
// listener are read from the Blade file itself and run in a DOM without a browser:
// `node --test tests/js/admin-product-strip.test.mjs`.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const src = readFileSync(new URL('../../app/Modules/Admin/resources/views/livewire/catalog/editor.blade.php', import.meta.url), 'utf8');
const section = /<section [^>]*data-picker-home\s+x-data="(\{[\s\S]*?\})"(?:\s+x-effect="[^"]*")?\s+[^>]*x-on:ped-vfocus\.window="([^"]+)">/.exec(src);
assert.ok(section, 'the strip section keeps its Alpine object and its ped-vfocus listener');
const html = (s) => s.replace(/&quot;/g, '"');
const objectSrc = html(section[1]).replace(/\{\{ Js::from\(\$imgWords\) \}\}/, '{}');
const listener = html(section[2]);
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

// The strip as the editor draws it, with the Alpine object bound to it and the listener on the window.
function strip(body, wire = {}) {
    const dom = new JSDOM(`<!doctype html><html><body><section data-picker-home><h2 id="ped-h-img" tabindex="-1">Ảnh</h2>${body}</section><button id="other">x</button></body></html>`);
    const { window } = dom;
    window.Element.prototype.scrollIntoView = function () {};
    const root = window.document.querySelector('section');
    const data = new Function('$wire', 'window', `return (${objectSrc});`)(wire, window);
    data.$root = root;
    data.$refs = {};
    // `setTimeout` inside the object is Node's: fine for a wait of 40ms
    window.addEventListener('ped-vfocus', ($event) => new Function('$event', `with (this) { ${listener} }`).call(data, $event));
    window.document.getElementById('other').focus();

    return { window, data, fire: async (i) => { window.dispatchEvent(new window.CustomEvent('ped-vfocus', { detail: { i } })); await wait(60); return window.document.activeElement; } };
}

const tiles = (pics, vids) => '<div class="ped-imgs">'
    + Array.from({ length: pics }, (_, i) => `<div class="ped-im"><button class="ped-imb" data-img-i="${i}"></button></div>`).join('')
    + Array.from({ length: vids }, (_, j) => `<div class="ped-im"><button class="ped-imb" data-vid-i="${j}"></button></div>`).join('')
    + '<div class="ped-imaddw"><button class="ped-imadd">+</button></div></div>';

test('a video added or put back takes focus at its place', async () => {
    const s = strip(tiles(2, 3));
    assert.equal((await s.fire(2)).getAttribute('data-vid-i'), '2');
    assert.equal((await s.fire(0)).getAttribute('data-vid-i'), '0');
});

test('with no video left, focus goes to the main picture', async () => {
    const s = strip(tiles(2, 0));
    assert.equal((await s.fire(-1)).getAttribute('data-img-i'), '0');
});

test('a video that is no longer drawn hands focus to the main picture', async () => {
    const s = strip(tiles(1, 1));
    assert.equal((await s.fire(4)).getAttribute('data-img-i'), '0');
});

test('the last video gone with no picture: focus goes to «Chọn ảnh» in the empty card, never to the page', async () => {
    const s = strip('<div class="ped-imempty"><span>Chưa có ảnh</span><button id="choose">Chọn ảnh</button></div>');
    const to = await s.fire(-1);
    assert.equal(to.id, 'choose');
    assert.notEqual(to, s.window.document.body);
});

test('videos left with no picture: focus goes to the next video, or the add tile once none is left', async () => {
    assert.equal((await strip(tiles(0, 2)).fire(1)).getAttribute('data-vid-i'), '1');
    assert.ok((await strip(tiles(0, 0)).fire(-1)).classList.contains('ped-imadd'));
});

test('a member who may not open the Library (no «Chọn ảnh»): focus goes to the card heading, never to the page', async () => {
    const s = strip('<div class="ped-imempty"><span>Chưa có ảnh</span></div>');
    const to = await s.fire(-1);
    assert.equal(to.id, 'ped-h-img');
    assert.notEqual(to, s.window.document.body);
});

test('after a picture is taken off the product, focus goes to the tile now at its place, then the last one, never the page', async () => {
    const taken = { removeImage: () => Promise.resolve() };
    const s = strip(tiles(3, 0), taken);
    s.data.sel = 1;
    await s.data.act('removeImage');
    await wait(80);
    assert.equal(s.window.document.activeElement.getAttribute('data-img-i'), '1');
    assert.equal(s.data.sel, null);

    const last = strip(tiles(2, 0), taken);
    last.data.sel = 2;
    await last.data.act('removeImage');
    await wait(80);
    assert.equal(last.window.document.activeElement.getAttribute('data-img-i'), '1');

    const none = strip('<div class="ped-imempty"><button id="choose">Chọn ảnh</button></div>', taken);
    none.data.sel = 0;
    await none.data.act('removeImage');
    await wait(80);
    assert.equal(none.window.document.activeElement.id, 'choose');
});
