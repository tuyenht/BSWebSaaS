// The footer's factory cards (resources/js/sf-footer-rot.js) below 1024px: they turn every 5 seconds, a
// sideways swipe turns them by hand (a mostly-vertical drag does not), and a card picked by hand rests
// 10 seconds before the turning goes on. In a DOM without a browser: `node --test tests/js/sf-footer-rot.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;

async function footer({ narrow = true } = {}) {
    const dom = new JSDOM(`<!doctype html><body><div class="sf-rot"><ul data-sf-rot>${[1, 2, 3].map((n) => `<li class="sf-factory">Nhà máy ${n}</li>`).join('')}</ul>
        <div data-sf-rot-nav hidden><button data-sf-rot-prev></button>${[1, 2, 3].map(() => '<button data-sf-rot-go></button>').join('')}<button data-sf-rot-next></button></div></div></body>`, { url: 'https://a.test/' });
    Object.defineProperty(dom.window.document, 'hidden', { value: false });
    const w = dom.window;
    w.matchMedia = (q) => ({ matches: q.includes('max-width') ? narrow : false, addEventListener() {} });
    const timers = [];
    let now = 0;
    const fakeSet = (fn, ms) => timers.push({ fn, at: now + ms, id: timers.length + 1 });
    const fakeClear = (id) => {
        const t = timers.find((x) => x.id === id);
        if (t) {
            t.fn = null;
        }
    };
    Object.assign(globalThis, { window: w, document: w.document, setTimeout: fakeSet, clearTimeout: fakeClear });
    await import(`../../resources/js/sf-footer-rot.js?run=${++load}`);
    const advance = (ms) => {
        const end = now + ms;
        for (;;) {
            const due = timers.filter((t) => t.fn && t.at <= end).sort((a, b) => a.at - b.at)[0];
            if (!due) {
                break;
            }
            now = due.at;
            const fn = due.fn;
            due.fn = null;
            fn();
        }
        now = end;
    };
    const root = w.document.querySelector('[data-sf-rot]');
    const shown = () => [...root.children].findIndex((c) => c.getAttribute('aria-hidden') === 'false') + 1;
    const touch = (type, x, y) => {
        const ev = new w.Event(type, { bubbles: true });
        const list = [{ clientX: x, clientY: y }];
        ev.touches = type === 'touchend' ? [] : list;
        ev.changedTouches = list;
        root.children[0].dispatchEvent(ev);
    };
    const swipe = (dx, dy) => {
        touch('touchstart', 200, 300);
        touch('touchend', 200 + dx, 300 + dy);
    };

    return { advance, shown, swipe };
}

test('the cards turn every 5 seconds, not sooner', async () => {
    const f = await footer();
    assert.equal(f.shown(), 1);
    f.advance(4999);
    assert.equal(f.shown(), 1);
    f.advance(1);
    assert.equal(f.shown(), 2);
});

test('a sideways swipe turns the card; a mostly-vertical drag scrolls the page instead', async () => {
    const f = await footer();
    f.swipe(-80, 10);
    assert.equal(f.shown(), 2, 'finger to the left = next card');
    f.swipe(80, 5);
    assert.equal(f.shown(), 1, 'finger to the right = previous card');
    f.swipe(80, 5);
    assert.equal(f.shown(), 3, 'past the first card it wraps to the last');
    f.swipe(-30, 0);
    f.swipe(-60, 90);
    assert.equal(f.shown(), 3, 'too short, or more vertical than sideways: no turn');
});

test('a card picked by hand rests 10 seconds, then the turning goes on', async () => {
    const f = await footer();
    f.swipe(-80, 0);
    assert.equal(f.shown(), 2);
    f.advance(9999);
    assert.equal(f.shown(), 2);
    f.advance(1 + 5000);
    assert.equal(f.shown(), 3);
});

test('on a wide screen the cards stand side by side: no turning, no swipe', async () => {
    const f = await footer({ narrow: false });
    f.advance(20000);
    f.swipe(-80, 0);
    assert.equal(f.shown(), 0, 'no card is hidden');
});
