// The product photo switcher (resources/js/sf-gallery.js): a sideways swipe on the main photo does what
// the arrows do (left = next photo, right = previous, wrapping round); a short or mostly-vertical drag and
// a two-finger touch change nothing. In a DOM without a browser: `node --test tests/js/sf-gallery.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;

async function gallery() {
    const photos = [1, 2, 3].map((n) => `<li><a href="/p${n}.jpg" data-sf-gallery-thumb data-srcset=""></a></li>`).join('');
    const dom = new JSDOM(`<!doctype html><body><div data-sf-gallery><a href="/p1.jpg" data-sf-gallery-main><img src="/p1.jpg" alt=""></a>
        <span data-sf-gallery-count>1 / 3</span><button data-sf-gallery-step="-1" hidden></button><button data-sf-gallery-step="1" hidden></button>
        <ul>${photos}</ul></div></body>`, { url: 'https://a.test/' });
    const w = dom.window;
    Object.assign(globalThis, { window: w, document: w.document });
    await import(`../../resources/js/sf-gallery.js?run=${++load}`);
    const main = w.document.querySelector('[data-sf-gallery-main]');
    const count = () => w.document.querySelector('[data-sf-gallery-count]').textContent;
    const touch = (type, points) => {
        const ev = new w.Event(type, { bubbles: true });
        ev.touches = type === 'touchend' ? [] : points;
        ev.changedTouches = points;
        main.querySelector('img').dispatchEvent(ev);
    };
    const swipe = (dx, dy) => {
        touch('touchstart', [{ clientX: 200, clientY: 300 }]);
        touch('touchend', [{ clientX: 200 + dx, clientY: 300 + dy }]);
    };

    return { count, swipe, touch, step: (n) => w.document.querySelector(`[data-sf-gallery-step="${n}"]`).click() };
}

test('a swipe left shows the next photo, a swipe right the previous one, wrapping round', async () => {
    const g = await gallery();
    g.swipe(-80, 10);
    assert.equal(g.count(), '2 / 3');
    g.swipe(90, -5);
    g.swipe(90, -5);
    assert.equal(g.count(), '3 / 3');
});

test('a short, a mostly-vertical or a two-finger drag changes nothing', async () => {
    const g = await gallery();
    g.swipe(-30, 0);
    g.swipe(-60, 120);
    g.touch('touchstart', [{ clientX: 200, clientY: 300 }]);
    g.touch('touchstart', [{ clientX: 200, clientY: 300 }, { clientX: 260, clientY: 300 }]);
    g.touch('touchend', [{ clientX: 100, clientY: 300 }]);
    assert.equal(g.count(), '1 / 3');
});

test('the arrows still step through the photos', async () => {
    const g = await gallery();
    g.step(1);
    assert.equal(g.count(), '2 / 3');
    g.step(-1);
    g.step(-1);
    assert.equal(g.count(), '3 / 3');
});
