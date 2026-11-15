// Behaviour check of resources/js/sf-slider.js in jsdom (run in CI's frontend job):
// node scripts/storefront/slider-check.mjs. The markup mirrors themes/default/sections/hero_slider.
import { JSDOM } from 'jsdom';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const script = pathToFileURL(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/sf-slider.js')).href;

const slide = (n, active) => `<div class="sf-slide${active ? ' is-active' : ''}" role="group" aria-roledescription="slide" aria-label="Ảnh ${n}/3">
<div class="sf-slide__media"${n > 1 ? ' hidden' : ''}><img src="/s${n}.webp" alt=""></div><div class="sf-slide__content"><h2>Slide ${n}</h2></div></div>`;
const html = `<!doctype html><body><section class="sf-slider" data-sf-slider data-interval="5000" aria-roledescription="carousel">
<div class="sf-slider__controls" hidden>
<button type="button" data-sf-prev></button><button type="button" data-sf-next></button>
<div><button type="button" data-sf-dot="0" aria-current="true"></button><button type="button" data-sf-dot="1"></button><button type="button" data-sf-dot="2"></button></div>
</div>
<div class="sf-slider__viewport" aria-live="off">${slide(1, true)}${slide(2)}${slide(3)}</div></section></body>`;
// A row of cards that rotates (themes/default/partials/sf-rail-open and sf-rail-close).
const rail = `<div class="sf-rail" data-sf-rail data-interval="5000" data-mobile="rotate" data-arrows="auto">
<div class="sf-rail__list"><div>Thẻ 1</div><div>Thẻ 2</div><div>Thẻ 3</div><div>Thẻ 4</div></div>
<div class="sf-rail__controls"><button type="button" data-sf-rail-prev hidden></button><button type="button" data-sf-rail-next hidden></button></div></div>`;

let run = 0;
async function mount({ reduced = false, still = false, withRail = false } = {}) {
    const dom = new JSDOM(withRail ? html.replace('</body>', `${rail}</body>`) : html);
    const { window } = dom;
    // jsdom has no layout: the rail's row is 1000px of cards in a 300px box, and a scroll is counted.
    const sized = (wide) => ({ configurable: true, get() { return this.classList.contains('sf-rail__list') ? (wide ? 1000 : 300) : 0; } });
    Object.defineProperty(window.HTMLElement.prototype, 'scrollWidth', sized(true));
    Object.defineProperty(window.HTMLElement.prototype, 'clientWidth', sized(false));
    let railMoves = 0;
    window.HTMLElement.prototype.scrollTo = function scrollTo() {
        railMoves += 1;
    };
    let now = 0;
    let seq = 0;
    const queue = new Map();
    const listeners = [];
    window.matchMedia = () => ({ matches: reduced, addEventListener: (_t, fn) => listeners.push(fn) });
    let hidden = false;
    Object.defineProperty(window.document, 'hidden', { configurable: true, get: () => hidden });
    globalThis.window = window;
    globalThis.document = window.document;
    globalThis.setTimeout = (fn, ms) => {
        queue.set(++seq, { at: now + ms, fn });
        return seq;
    };
    globalThis.clearTimeout = (id) => queue.delete(id);
    await import(`${script}?run=${++run}`);
    // The visitor is here (a mouse move) unless the case is about the page nobody has touched yet.
    if (!still) {
        window.dispatchEvent(new window.Event('pointermove'));
    }
    const doc = window.document;
    const root = doc.querySelector('[data-sf-slider]');
    return {
        window,
        doc,
        root,
        tick(ms) {
            const end = now + ms;
            for (;;) {
                let next = null;
                for (const [id, t] of queue) {
                    if (t.at <= end && (next === null || t.at < queue.get(next).at)) {
                        next = id;
                    }
                }
                if (next === null) {
                    break;
                }
                const t = queue.get(next);
                queue.delete(next);
                now = t.at;
                t.fn();
            }
            now = end;
        },
        railMoves: () => railMoves,
        active: () => [...doc.querySelectorAll('.sf-slide')].findIndex((s) => s.classList.contains('is-active')),
        current: () => [...doc.querySelectorAll('[data-sf-dot]')].findIndex((d) => d.getAttribute('aria-current') === 'true'),
        media: (i) => doc.querySelectorAll('.sf-slide__media')[i],
        fire: (el, type) => el.dispatchEvent(new window.Event(type, { bubbles: type !== 'mouseenter' && type !== 'mouseleave' })),
        // pointerenter/leave do not bubble; jsdom has no PointerEvent, so the type rides on a plain event
        point: (el, type, pointerType = 'mouse') => el.dispatchEvent(Object.assign(new window.Event(type, { bubbles: type === 'pointerdown' }), { pointerType })),
        setHidden(value) {
            hidden = value;
            doc.dispatchEvent(new window.Event('visibilitychange'));
        },
    };
}

const failures = [];
const check = (name, ok) => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}`);
    if (!ok) {
        failures.push(name);
    }
};

{
    const s = await mount();
    check('init reveals the controls', s.doc.querySelector('.sf-slider__controls').hidden === false);
    s.doc.querySelector('[data-sf-next]').click();
    check('next moves the active slide and the current dot', s.active() === 1 && s.current() === 1);
    check('next reveals that slide\'s photo', s.media(1).hidden === false);
    s.doc.querySelector('[data-sf-prev]').click();
    check('prev moves back', s.active() === 0 && s.current() === 0);
    s.doc.querySelectorAll('[data-sf-dot]')[2].click();
    check('a dot shows its slide', s.active() === 2 && s.current() === 2);
    check('no pause button (the owner chose hover to pause)', s.doc.querySelector('[data-sf-pause]') === null);
    s.tick(5000);
    check('a slide button does not stop rotation', s.active() === 0);
}
{
    const s = await mount();
    check('rotating: live region off', s.doc.querySelector('.sf-slider__viewport').getAttribute('aria-live') === 'off');
    s.tick(3600);
    check('the next photo is revealed before its turn', s.media(1).hidden === false && s.active() === 0);
    check('slide 3 photo still hidden', s.media(2).hidden === true);
    s.tick(1400);
    check('autoplay advances at the interval', s.active() === 1);
    s.tick(5000);
    check('autoplay keeps going', s.active() === 2);
}
{
    const s = await mount({ still: true });
    s.tick(20000);
    check('nobody has touched the page yet: no autoplay', s.active() === 0);
    check('still page: the next photo stays hidden', s.media(1).hidden === true);
    check('still page: live region polite', s.doc.querySelector('.sf-slider__viewport').getAttribute('aria-live') === 'polite');
    s.window.dispatchEvent(new s.window.Event('scroll'));
    check('the first scroll starts rotation: live region off', s.doc.querySelector('.sf-slider__viewport').getAttribute('aria-live') === 'off');
    s.tick(5000);
    check('the first scroll starts rotation at the interval', s.active() === 1);
}
{
    const s = await mount({ still: true });
    s.window.dispatchEvent(new s.window.Event('keydown'));
    s.tick(5000);
    check('a key press also starts rotation', s.active() === 1);
}
// Every way a visitor shows up starts rotation, and only the first one is needed.
for (const type of ['pointerdown', 'wheel', 'touchstart']) {
    const s = await mount({ still: true });
    s.tick(20000);
    s.window.dispatchEvent(new s.window.Event(type));
    s.tick(5000);
    check(`a ${type} on an untouched page starts rotation`, s.active() === 1);
}
{
    const s = await mount({ still: true, withRail: true });
    s.tick(20000);
    check('rail: nobody has touched the page yet: the row does not move', s.railMoves() === 0);
    s.window.dispatchEvent(new s.window.Event('touchstart'));
    s.tick(5000);
    check('rail: the first touch starts the row moving at the interval', s.railMoves() === 1);
}
{
    const s = await mount({ withRail: true });
    s.tick(10000);
    check('rail: a visitor who is here sees the row move at the interval', s.railMoves() === 2);
}
{
    const s = await mount({ reduced: true });
    s.tick(20000);
    check('reduced motion: no autoplay', s.active() === 0);
}
{
    const s = await mount();
    const next = s.doc.querySelector('[data-sf-next]');
    next.matches = (sel) => sel === ':focus-visible';
    s.fire(next, 'focusin');
    s.tick(20000);
    check('keyboard focus inside pauses', s.active() === 0);
    s.point(s.root, 'pointerleave');
    s.tick(20000);
    check('leaving with the pointer keeps the keyboard pause', s.active() === 0);
    s.root.dispatchEvent(Object.assign(new s.window.Event('focusout', { bubbles: true }), { relatedTarget: null }));
    s.tick(5000);
    check('focus leaving the slider resumes', s.active() === 1);
}
{
    const s = await mount();
    const next = s.doc.querySelector('[data-sf-next]');
    next.matches = () => false;
    s.fire(next, 'focusin');
    s.tick(5000);
    check('focus left by a mouse click does not pause', s.active() === 1);
}
{
    const s = await mount();
    s.point(s.root, 'pointerenter');
    s.tick(20000);
    check('hover pauses', s.active() === 0);
    s.doc.querySelector('[data-sf-next]').click();
    s.tick(20000);
    check('an arrow click while hovering stays paused', s.active() === 1);
    s.point(s.root, 'pointerleave');
    s.tick(5000);
    check('leaving resumes', s.active() === 2);
}
{
    const s = await mount();
    s.point(s.root, 'pointerenter', 'touch');
    s.tick(5000);
    check('a touch pointer entering is not a hover', s.active() === 1);
    s.point(s.doc.querySelector('.sf-slider__viewport'), 'pointerdown', 'touch');
    s.tick(9000);
    check('a touch holds rotation', s.active() === 1);
    s.tick(6000);
    check('rotation comes back after the hold', s.active() === 2);
}
{
    const s = await mount();
    s.setHidden(true);
    s.tick(20000);
    check('a hidden page pauses', s.active() === 0);
    s.setHidden(false);
    s.tick(5000);
    check('a visible page resumes', s.active() === 1);
}
{
    const s = await mount();
    const next = s.doc.querySelector('[data-sf-next]');
    next.dispatchEvent(new s.window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    check('ArrowRight on a control moves to the next slide', s.active() === 1);
}

// A looping rail (the copies of the cards and the jump back onto the real cards), on a small layout model:
// each displayed item is 200px wide with no gap in a 300px row, so four cards are one 800px copy and the
// real cards start at scrollLeft 800. A copy is displayed only while its row has .is-loop (sf-rail.css).
// A row settles on scrollend; `ends: false` removes the event, as in a browser without it, and the row then
// settles after a quiet 150 ms.
// `idle: true` gives the window requestIdleCallback; `observe: true` gives it a ResizeObserver that the
// test reports to with resize().
async function mountLoop({ loop = '', grid = false, ends = true, idle = false, observe = false } = {}) {
    const cards = [1, 2, 3, 4].map((n) => `<li><a href="/the-${n}" id="card-${n}">Thẻ ${n}</a></li>`).join('');
    const dom = new JSDOM(`<!doctype html><body><div class="sf-rail" data-sf-rail data-interval="0" data-mobile="swipe" data-arrows="auto"${loop}>
<ul class="sf-rail__list${grid ? ' sf-rail__list--grid' : ''}">${cards}</ul>
<div class="sf-rail__controls" hidden><button type="button" data-sf-rail-prev hidden></button><span data-sf-rail-dots hidden></span><button type="button" data-sf-rail-next hidden></button></div></div></body>`);
    const { window } = dom;
    if (!ends) {
        for (let o = window; o && 'onscrollend' in o; o = Object.getPrototypeOf(o)) {
            delete o.onscrollend;
        }
    }
    let W = 200;
    let rowW = 300;
    const isList = (el) => el.classList?.contains('sf-rail__list');
    const shown = (el) => !(el.hasAttribute('data-sf-copy') && !el.parentElement.classList.contains('is-loop'));
    const row = (list) => [...list.children].filter(shown);
    const left = new WeakMap();
    const proto = window.HTMLElement.prototype;
    const getter = (name, get) => Object.defineProperty(proto, name, { configurable: true, get });
    getter('offsetWidth', function () {
        return isList(this.parentElement) && shown(this) ? W : 0;
    });
    getter('offsetLeft', function () {
        return isList(this.parentElement) ? Math.max(0, row(this.parentElement).indexOf(this)) * W : 0;
    });
    getter('clientWidth', function () {
        return isList(this) ? rowW : 0;
    });
    getter('scrollWidth', function () {
        return isList(this) ? row(this).length * W : 0;
    });
    Object.defineProperty(proto, 'scrollLeft', {
        configurable: true,
        get() {
            return left.get(this) ?? 0;
        },
        set(v) {
            left.set(this, Math.max(0, Math.min(v, this.scrollWidth - this.clientWidth)));
        },
    });
    const end = (el) => ends && el.dispatchEvent(new window.Event('scrollend'));
    proto.scrollTo = function scrollTo({ left: to }) {
        this.scrollLeft = to;
        this.dispatchEvent(new window.Event('scroll'));
        end(this);
    };
    proto.getBoundingClientRect = function rect() {
        const x = isList(this.parentElement) ? this.offsetLeft - this.parentElement.scrollLeft : 0;
        return { left: x, right: x + this.offsetWidth, top: 0, bottom: 0, width: this.offsetWidth, height: 0 };
    };
    const css = window.getComputedStyle.bind(window);
    window.getComputedStyle = (el) => (isList(el) ? { overflowX: el.classList.contains('sf-rail__list--grid') ? 'visible' : 'auto' } : css(el));
    window.matchMedia = () => ({ matches: false, addEventListener: () => {} });
    let now = 0;
    let seq = 0;
    const queue = new Map();
    window.performance.now = () => now;
    globalThis.performance = window.performance;
    if (idle) {
        window.requestIdleCallback = (fn) => globalThis.setTimeout(fn, 0);
    }
    let observed = null;
    if (observe) {
        window.ResizeObserver = class {
            constructor(fn) {
                observed = fn;
            }
            observe() {}
        };
    }
    globalThis.ResizeObserver = window.ResizeObserver;
    globalThis.window = window;
    globalThis.document = window.document;
    globalThis.getComputedStyle = window.getComputedStyle;
    globalThis.setTimeout = (fn, ms) => {
        queue.set(++seq, { at: now + ms, fn });
        return seq;
    };
    globalThis.clearTimeout = (id) => queue.delete(id);
    await import(`${script}?run=${++run}`);
    const tick = (ms) => {
        const end = now + ms;
        for (;;) {
            const due = [...queue].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
            if (!due) {
                break;
            }
            queue.delete(due[0]);
            now = due[1].at;
            due[1].fn();
        }
        now = end;
    };
    const track = window.document.querySelector('.sf-rail__list');
    // A swipe that ends at `to`: the scroll, then (where the browser has it) scrollend.
    const scrollBy = (to) => {
        track.scrollLeft = to;
        track.dispatchEvent(new window.Event('scroll'));
        end(track);
    };
    // A touch on the row; `fingers` is how many stay on it afterwards.
    const touch = (type, fingers = 0) => track.dispatchEvent(Object.assign(new window.Event(type), { touches: { length: fingers } }));
    // The cards and the row take new widths (a phone turned) and the observer reports it.
    const resize = (card, row) => {
        W = card;
        rowW = row;
        observed?.([]);
    };
    return { window, doc: window.document, track, tick, scrollBy, touch, resize };
}
for (const ends of [true, false]) {
    const s = await mountLoop({ ends });
    const way = ends ? 'scrollend' : 'no scrollend';
    s.tick(1000);
    check(`loop (${way}): nothing is copied before the visitor is here`, s.doc.querySelectorAll('[data-sf-copy]').length === 0 && s.track.scrollLeft === 0);
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    const copies = [...s.doc.querySelectorAll('[data-sf-copy]')];
    check(`loop (${way}): a copy of the cards before and after them once the visitor is here`, copies.length === 8 && s.track.classList.contains('is-loop'));
    check(`loop (${way}): the copies are hidden from screen readers, skipped by Tab and carry no id`, copies.every((c) => c.getAttribute('aria-hidden') === 'true') && [...s.doc.querySelectorAll('[data-sf-copy] a')].every((a) => a.getAttribute('tabindex') === '-1') && s.doc.querySelectorAll('[data-sf-copy] [id], [data-sf-copy][id]').length === 0);
    check(`loop (${way}): the view stays on the first real card`, s.track.scrollLeft === 800);
    s.scrollBy(1600);
    s.tick(200);
    check(`loop (${way}): a swipe past the last card settles on the first real card`, s.track.scrollLeft === 800);
    s.scrollBy(400);
    s.tick(200);
    check(`loop (${way}): a swipe back past the first card settles on the same view of the real cards`, s.track.scrollLeft === 1200);
    // The row's settle comes while the finger is still on it (a rotation ending under a resting finger).
    s.touch('touchstart', 1);
    s.scrollBy(1800);
    s.tick(400);
    check(`loop (${way}): no jump while a finger is on the row`, s.track.scrollLeft === 1800);
    s.touch('touchend');
    s.tick(400);
    check(`loop (${way}): the jump comes once the finger lifts and the row settles`, s.track.scrollLeft === 1000);
    s.scrollBy(800);
    s.doc.querySelector('[data-sf-rail-prev]').click();
    s.tick(200);
    check(`loop (${way}): Prev on the first card runs on to the last real card`, s.track.scrollLeft === 1400);
    s.doc.querySelector('[data-sf-rail-next]').click();
    s.tick(200);
    check(`loop (${way}): Next on the last card runs on to the first real card`, s.track.scrollLeft === 800);
}
{
    // The visitor's first sign of being here is a swipe on this row: the loop waits until it is still.
    const s = await mountLoop({ idle: true });
    s.tick(1000);
    s.touch('touchstart', 1);
    s.window.dispatchEvent(new s.window.Event('touchstart'));
    s.scrollBy(150);
    s.tick(400);
    check('loop, first touch on the row: no copies while the finger is on it', !s.track.classList.contains('is-loop'));
    s.touch('touchend');
    for (const to of [200, 250, 300, 350, 400]) {
        s.scrollBy(to);
        s.tick(50);
    }
    check('loop, first touch on the row: no copies while the row still moves after the finger lifts', !s.track.classList.contains('is-loop') && s.track.scrollLeft === 400);
    s.tick(600);
    check('loop, first touch on the row: copies once the row is still, with the view kept', s.track.classList.contains('is-loop') && s.track.scrollLeft === 1200);
    // Two fingers on the row (a pinch): lifting one keeps the row held.
    s.touch('touchstart', 2);
    s.scrollBy(1700);
    s.touch('touchend', 1);
    s.tick(400);
    check('loop: no jump while a second finger stays on the row', s.track.scrollLeft === 1700);
    s.touch('touchend');
    s.tick(400);
    check('loop: the jump comes once the last finger lifts', s.track.scrollLeft === 900);
}
{
    // The row and its cards change width with the loop on (a phone turned): no jump with the old copy
    // width, then the copy width is measured again and the nearest card is kept.
    const s = await mountLoop({ observe: true });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    s.scrollBy(1000);
    s.tick(200);
    s.resize(150, 200);
    s.scrollBy(1600);
    s.tick(100);
    check('loop, new width: no jump before the new copy width is measured', s.track.scrollLeft === 1600);
    s.tick(400);
    check('loop, new width: the nearest card is kept on the real cards', s.track.scrollLeft === 1050);
    s.scrollBy(1250);
    s.tick(400);
    check('loop, new width: a swipe past the last card settles by the new copy width', s.track.scrollLeft === 650);
}
{
    const s = await mountLoop({ loop: ' data-loop="off"' });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    check('loop off: no copies, the row stops at its ends', s.doc.querySelectorAll('[data-sf-copy]').length === 0 && !s.track.classList.contains('is-loop'));
}
{
    const s = await mountLoop({ grid: true });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    check('loop: a grid that does not scroll gets no copies', s.doc.querySelectorAll('[data-sf-copy]').length === 0);
}

if (failures.length > 0) {
    console.error(`slider-check: ${failures.length} failed`);
    process.exit(1);
}
console.log('slider-check: all passed');
