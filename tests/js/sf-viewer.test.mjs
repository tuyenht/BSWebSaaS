// The storefront photo viewer (resources/js/sf-viewer.js): the server prints each photo as a plain link
// to itself and the viewer's translated frame in a <template>; the script opens the photo in a modal
// dialog, in a DOM without a browser: `node --test tests/js/sf-viewer.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;

const frame = `<template id="sf-viewer"><dialog class="sf-viewer" aria-label="Xem ảnh"><button type="button" class="sf-viewer__close" aria-label="Đóng">×</button><figure class="sf-viewer__figure"><img class="sf-viewer__img" alt=""><figcaption class="sf-viewer__caption" aria-live="polite"><span class="sf-viewer__text"></span> <span class="sf-viewer__count"></span></figcaption></figure><button type="button" class="sf-viewer__nav sf-viewer__prev" aria-label="Ảnh trước"></button><button type="button" class="sf-viewer__nav sf-viewer__next" aria-label="Ảnh sau"></button></dialog></template>`;

const picture = (n, caption = '', alt = '') => `<li><figure class="sf-gallery__item"><a class="sf-gallery__zoom" href="https://files.test/${n}.webp" data-sf-zoom="gallery"><img class="sf-gallery__img" src="https://files.test/${n}.webp" srcset="https://files.test/${n}.webp?w=640 640w" alt="${alt}"></a>${caption ? `<figcaption class="sf-gallery__caption">${caption}</figcaption>` : ''}</figure></li>`;

const gallery = `<ul>${picture(1, 'Xưởng một')}${picture(2, '', 'Máy phay')}${picture(3, 'Xưởng ba')}</ul>`;
const portrait = '<div class="about__media"><a class="about__zoom" href="https://files.test/cert.webp" data-sf-zoom="single"><img class="about__image" src="https://files.test/cert.webp" alt="Chứng nhận"><span class="about__zoom-hint">Nhấn để xem ảnh lớn</span></a></div>';

// The page, and a start() that runs a fresh copy of the script against it. jsdom may lack the modal
// methods of <dialog>: the page then gets small stand-ins that set `open` and fire "close".
function page(html, withFrame = true) {
    const dom = new JSDOM(`<!doctype html><head>${withFrame ? frame : ''}</head><body>${html}</body>`);
    const win = dom.window;
    const proto = win.HTMLDialogElement.prototype;
    if (typeof proto.showModal !== 'function') {
        proto.showModal = function () { this.open = true; };
    }
    if (typeof proto.close !== 'function' || !('open' in proto)) {
        proto.close = function () { this.open = false; this.dispatchEvent(new win.Event('close')); };
    }
    return {
        doc: win.document,
        win,
        start: async () => {
            globalThis.document = win.document;
            await import(`../../resources/js/sf-viewer.js?run=${++load}`);
        },
    };
}

const click = (win, el, init = {}) => el.dispatchEvent(new win.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init }));
const pointer = (win, el, type, x, init = {}) => el.dispatchEvent(new win.PointerEvent(type, { bubbles: true, cancelable: true, pointerType: 'touch', pointerId: 1, clientX: x, ...init }));
const key = (win, el, name) => el.dispatchEvent(new win.KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true }));
const viewer = (doc) => doc.querySelector('dialog.sf-viewer');
const shown = (doc) => ({
    src: viewer(doc).querySelector('.sf-viewer__img').getAttribute('src'),
    text: viewer(doc).querySelector('.sf-viewer__text').textContent,
    count: viewer(doc).querySelector('.sf-viewer__count').textContent,
});

test('a click on a gallery picture opens it in the viewer with its caption and "n / N"', async () => {
    const { doc, win, start } = page(gallery);
    await start();
    const links = doc.querySelectorAll('a[data-sf-zoom]');
    assert.equal(viewer(doc), null);

    assert.equal(click(win, links[1]), false);
    assert.equal(viewer(doc).open, true);
    assert.deepEqual(shown(doc), { src: 'https://files.test/2.webp', text: 'Máy phay', count: '2 / 3' });
    const img = viewer(doc).querySelector('.sf-viewer__img');
    assert.equal(img.getAttribute('srcset'), 'https://files.test/2.webp?w=640 640w');
    assert.equal(img.getAttribute('sizes'), '100vw');
    links[1].querySelector('img').removeAttribute('srcset');
    click(win, viewer(doc).querySelector('.sf-viewer__prev'));
    click(win, viewer(doc).querySelector('.sf-viewer__next'));
    assert.equal(img.hasAttribute('srcset'), false, 'a photo without srcset never keeps the last one');
    assert.equal(img.hasAttribute('sizes'), false);
    assert.equal(img.alt, '', 'the caption names the photo, read out once');
    assert.equal(viewer(doc).classList.contains('sf-viewer--one'), false);
    assert.equal(doc.activeElement, viewer(doc).querySelector('.sf-viewer__close'));
});

test('previous and next step through every gallery picture and wrap at both ends', async () => {
    const { doc, win, start } = page(gallery);
    await start();
    click(win, doc.querySelectorAll('a[data-sf-zoom]')[0]);
    const next = viewer(doc).querySelector('.sf-viewer__next');
    const prev = viewer(doc).querySelector('.sf-viewer__prev');

    click(win, prev);
    assert.deepEqual(shown(doc), { src: 'https://files.test/3.webp', text: 'Xưởng ba', count: '3 / 3' });
    click(win, next);
    assert.equal(shown(doc).count, '1 / 3');
    key(win, viewer(doc), 'ArrowRight');
    assert.equal(shown(doc).count, '2 / 3');
    key(win, viewer(doc), 'ArrowLeft');
    key(win, viewer(doc), 'ArrowLeft');
    assert.equal(shown(doc).count, '3 / 3');
});

test('Esc or the close button closes the viewer and puts focus back on the picture', async () => {
    const { doc, win, start } = page(gallery);
    await start();
    const link = doc.querySelectorAll('a[data-sf-zoom]')[2];
    click(win, link);
    click(win, viewer(doc).querySelector('.sf-viewer__close'));
    assert.equal(viewer(doc).open, false);
    assert.equal(doc.activeElement, link);
});

test('a click on the dark area closes it, a click on the photo does not', async () => {
    const { doc, win, start } = page(gallery);
    await start();
    click(win, doc.querySelector('a[data-sf-zoom]'));
    click(win, viewer(doc).querySelector('.sf-viewer__img'));
    assert.equal(viewer(doc).open, true);
    click(win, viewer(doc));
    assert.equal(viewer(doc).open, false);
});

test('a portrait photo opens alone: no previous, next or count, and the heading as its text', async () => {
    const { doc, win, start } = page(portrait + gallery);
    await start();
    click(win, doc.querySelector('a[data-sf-zoom="single"]'));
    assert.deepEqual(shown(doc), { src: 'https://files.test/cert.webp', text: 'Chứng nhận', count: '' });
    assert.equal(viewer(doc).classList.contains('sf-viewer--one'), true);
    assert.equal(viewer(doc).querySelector('.sf-viewer__img').hasAttribute('srcset'), false);
    key(win, viewer(doc), 'ArrowRight');
    assert.equal(shown(doc).src, 'https://files.test/cert.webp');

    viewer(doc).close();
    click(win, doc.querySelector('a[data-sf-zoom="gallery"]'));
    assert.equal(viewer(doc).classList.contains('sf-viewer--one'), false);
    assert.equal(shown(doc).count, '1 / 3');
    assert.equal(doc.querySelectorAll('dialog.sf-viewer').length, 1);
});

test('a click with a modifier key, or a page without the frame, leaves the plain link alone', async () => {
    const one = page(gallery);
    await one.start();
    assert.equal(click(one.win, one.doc.querySelector('a[data-sf-zoom]'), { ctrlKey: true }), true);
    assert.equal(viewer(one.doc), null);

    const bare = page(gallery, false);
    await bare.start();
    assert.equal(click(bare.win, bare.doc.querySelector('a[data-sf-zoom]')), true);
    assert.equal(viewer(bare.doc), null);
});

test('a one-finger swipe steps through the gallery; the click that ends it does not close the viewer', async () => {
    const { doc, win, start } = page(gallery);
    await start();
    click(win, doc.querySelectorAll('a[data-sf-zoom]')[0]);
    const d = viewer(doc);

    pointer(win, d, 'pointerdown', 300);
    pointer(win, d, 'pointerup', 100);
    assert.equal(shown(doc).count, '2 / 3');
    click(win, d, { detail: 1 });
    assert.equal(d.open, true, 'the swipe\'s own click is ignored');

    pointer(win, d, 'pointerdown', 100);
    pointer(win, d, 'pointerup', 300);
    assert.equal(shown(doc).count, '1 / 3');
    pointer(win, d, 'pointerdown', 100);
    pointer(win, d, 'pointerup', 130);
    assert.equal(shown(doc).count, '1 / 3', 'under 50px is not a swipe');
    pointer(win, d, 'pointerdown', 300, { pointerType: 'mouse' });
    pointer(win, d, 'pointerup', 100, { pointerType: 'mouse' });
    assert.equal(shown(doc).count, '1 / 3', 'a mouse drag is not a swipe');
});

test('a key press right after a swipe still works, and a second finger cancels the swipe', async () => {
    const { doc, win, start } = page(gallery);
    await start();
    click(win, doc.querySelectorAll('a[data-sf-zoom]')[0]);
    const d = viewer(doc);

    pointer(win, d, 'pointerdown', 300);
    pointer(win, d, 'pointerup', 100);
    click(win, d.querySelector('.sf-viewer__next'), { detail: 0 });
    assert.equal(shown(doc).count, '3 / 3', 'Enter on a button after a swipe is not swallowed');

    pointer(win, d, 'pointerdown', 300);
    pointer(win, d, 'pointerdown', 320, { pointerId: 2 });
    pointer(win, d, 'pointerup', 100, { pointerId: 2 });
    pointer(win, d, 'pointerup', 100);
    assert.equal(shown(doc).count, '3 / 3', 'a pinch is not a swipe');
    pointer(win, d, 'pointerdown', 300);
    pointer(win, d, 'pointercancel', 100);
    assert.equal(shown(doc).count, '3 / 3', 'a cancelled touch is not a swipe');
    pointer(win, d, 'pointerdown', 300);
    pointer(win, d, 'pointerup', 100);
    assert.equal(shown(doc).count, '1 / 3', 'the next one-finger swipe works again');
});
